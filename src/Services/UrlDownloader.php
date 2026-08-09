<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MohamedSamy902\LaravelMediaVault\Contracts\SsrfValidatorContract;
use MohamedSamy902\LaravelMediaVault\Exceptions\RateLimitExceededException;
use RuntimeException;

/**
 * Downloads remote files to a local temporary path for subsequent processing.
 *
 * Two download strategies are available and selected via config:
 *
 *   - "chunked": loads the full response body into memory first, then writes
 *     to disk. Supports automatic retry on HTTP 429 (rate limiting).
 *
 *   - "simple": streams the response directly to disk via a file sink,
 *     which is more memory-efficient for large files.
 *
 * Both strategies validate the SSRF safety of the URL before issuing
 * any outbound HTTP request.
 */
final class UrlDownloader
{
    public function __construct(
        private readonly SsrfValidatorContract $ssrfValidator,
        private readonly FileValidator         $fileValidator,
        private readonly MimeTypeResolver      $mimeResolver,
    ) {}

    /**
     * Downloads a file from the given URL and returns it as an UploadedFile.
     *
     * The download strategy (chunked vs. simple) is determined by the
     * "url_download.chunked" config value.
     *
     * @param string $url     The remote URL to download from
     * @param array<string, mixed> $options Optional per-request overrides (timeout, max_size)
     *
     * @return UploadedFile A temporary file ready for validation and storage
     *
     * @throws \MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException
     *         When the URL resolves to a blocked address
     * @throws RuntimeException
     *         When the download fails or the file exceeds the size limit
     */
    public function download(string $url, array $options = []): UploadedFile
    {
        $resolvedIp = $this->ssrfValidator->validate($url);
        
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            throw new RuntimeException("Invalid URL provided: {$url}");
        }

        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? (strtolower($parsed['scheme'] ?? '') === 'https' ? 443 : 80);
        $resolveConfig = ["{$host}:{$port}:{$resolvedIp}"];

        // Always stream to disk to prevent memory exhaustion (OOM) on large files
        return $this->streamToDisk($url, $options, $resolveConfig);
    }

    // Memory-exhausting downloadIntoMemory method has been removed for security and performance.

    /**
     * Streams the remote file directly to disk without loading it into memory.
     *
     * Performs a HEAD request first to check Content-Length and Content-Type
     * before committing to the full download.
     *
     * @param string $url
     * @param array<string, mixed> $options
     * @param array<int, string> $resolveConfig
     * @return UploadedFile
     * @throws RuntimeException
     */
    private function streamToDisk(string $url, array $options, array $resolveConfig): UploadedFile
    {
        $timeout  = (int) ($options['timeout'] ?? config('media-vault.url_upload.timeout_seconds', 10));
        $maxBytes = (int) ($options['max_size'] ?? config('media-vault.url_upload.max_size_bytes', 52428800));
        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.tmp';

        try {
            $head = Http::timeout(10)
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => $resolveConfig]])
                ->head($url);

            if ($head->failed()) {
                $status = $head->status();
                if ($status === 429) {
                    $retryHeader = $head->header('Retry-After');
                    $retryAfter = (int) ($retryHeader !== null && $retryHeader !== '' ? $retryHeader : 60);
                    throw new RateLimitExceededException("Rate limit exceeded (HTTP 429) when accessing [{$url}].", $retryAfter);
                }
                throw new RuntimeException("Cannot access [{$url}]. HTTP status: {$status}.");
            }

            $contentLength = (int) $head->header('Content-Length');
            $contentType   = (string) $head->header('Content-Type');
            $mime          = $this->mimeResolver->parseContentType($contentType);

            if ($contentLength > 0 && $contentLength > $maxBytes) {
                throw new RuntimeException('File exceeds the maximum allowed download size.');
            }

            $this->fileValidator->validateUrlMime($mime);

            $response = Http::timeout($timeout)
                ->withoutRedirecting()
                ->withOptions([
                    'sink' => $tempPath,
                    'curl' => [CURLOPT_RESOLVE => $resolveConfig],
                    'progress' => function (int $downloadTotal, int $downloadedBytes) use ($maxBytes) {
                        if ($downloadedBytes > $maxBytes) {
                            throw new \RuntimeException('Downloaded file exceeds the maximum allowed size.');
                        }
                    },
                ])
                ->get($url);

            if ($response->failed()) {
                $status = $response->status();
                if ($status === 429) {
                    $retryHeader = $response->header('Retry-After');
                    $retryAfter = (int) ($retryHeader !== null && $retryHeader !== '' ? $retryHeader : 60);
                    throw new RateLimitExceededException("Rate limit exceeded (HTTP 429) when downloading [{$url}].", $retryAfter);
                }
                throw new RuntimeException("Failed to download [{$url}]. HTTP status: {$status}.");
            }

            $actualSize = (int) filesize($tempPath);
            if ($actualSize > $maxBytes) {
                throw new RuntimeException('Downloaded file exceeds the maximum allowed size.');
            }

            $ext          = $this->mimeResolver->toExtension($mime, $url);
            $urlPath      = parse_url($url, PHP_URL_PATH);
            $originalName = pathinfo((string) $urlPath, PATHINFO_FILENAME) ?: 'file';

            return new UploadedFile($tempPath, "{$originalName}.{$ext}", $mime, null, true);

        } catch (\Exception $e) {
            $this->cleanupTemp($tempPath);
            if ($e instanceof RateLimitExceededException) {
                throw $e;
            }
            throw new RuntimeException('Stream download failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Removes a temporary file if it exists on disk.
     *
     * Called on failure to prevent orphaned temp files from accumulating.
     *
     * @param string|null $path The absolute path to the temp file
     */
    private function cleanupTemp(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }
}
