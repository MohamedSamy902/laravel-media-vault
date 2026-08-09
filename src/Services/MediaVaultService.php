<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaVaultContract;
use MohamedSamy902\LaravelMediaVault\Contracts\QuotaManagerContract;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use RuntimeException;

use MohamedSamy902\LaravelMediaVault\Security\VirusScanner;

/**
 * Orchestrates file upload operations.
 *
 * This class does not implement any storage, validation, downloading, or
 * processing logic itself. It delegates each concern to a focused dependency:
 *
 *   - UrlDownloader     : fetches files from remote URLs
 *   - FileValidator     : validates MIME types and Laravel validation rules
 *   - StorageManager    : writes files to disk, generates URLs, handles deletion
 *   - QuotaManagerContract : enforces per-user storage limits
 *   - VirusScanner      : scans files for viruses and malware before storage
 *
 * The upload() method accepts three source types:
 *   1. A URL string (or array of URLs) — delegates to UrlDownloader
 *   2. An Illuminate Request — supports chunked and multi-file uploads
 *   3. A direct UploadedFile (or array of files)
 */
class MediaVaultService implements MediaVaultContract
{
    private readonly VirusScanner $virusScanner;

    public function __construct(
        private readonly UrlDownloader         $urlDownloader,
        private readonly FileValidator         $fileValidator,
        private readonly StorageManager        $storageManager,
        private readonly QuotaManagerContract  $quotaManager,
        ?VirusScanner                           $virusScanner = null,
    ) {
        $this->virusScanner = $virusScanner ?? app(VirusScanner::class);
    }

    /**
     * Uploads a file from the given source to the configured storage disk.
     *
     * @param mixed $source  The upload source
     * @param array<string, mixed> $options
     *
     * @return UploadResult|array<int, UploadResult|array<string, mixed>>
     * @throws RuntimeException When the source is invalid or the upload fails
     */
    #[\Override]
    public function upload(mixed $source, array $options = []): UploadResult|array
    {
        $disk       = $options['disk']        ?? (string) config('media-vault.storage.disk', 'public');
        $basePath   = $options['path']        ?? (string) config('media-vault.storage.path', 'uploads');
        $folderName = $options['folder_name'] ?? (string) config('media-vault.storage.default_folder', 'default');

        $this->assertCloudDependenciesInstalled($disk);

        $storagePath = $folderName
            ? trim($basePath, '/') . '/' . trim($folderName, '/')
            : trim($basePath, '/');

        $customRules = $options['validation_rules'] ?? [];

        if (isset($options['url'])) {
            return $this->handleUrlSource($options['url'], $storagePath, $disk, $options, $customRules);
        }

        if ($source instanceof Request) {
            return $this->handleRequestSource($source, $storagePath, $disk, $options, $customRules);
        }

        return $this->handleDirectSource($source, $storagePath, $disk, $options, $customRules);
    }

    /**
     * Uploads one or more files from a remote URL.
     *
     * @param string|array<int, string> $url
     * @param array<string, mixed> $options
     * @return UploadResult|array<int, UploadResult|array<string, mixed>>
     */
    #[\Override]
    public function uploadFromUrl(string|array $url, array $options = []): UploadResult|array
    {
        $options['url'] = $url;
        return $this->upload(null, $options);
    }

    /**
     * Deletes a file or a batch of files.
     *
     * Accepts an integer database record ID, a storage path string, or an array
     * of either. Returns a single result array for scalar input or an array of
     * result arrays for batch input.
     *
     * @param int|string|array<int, int|string> $idOrPath
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    #[\Override]
    public function delete(int|string|array $idOrPath): array
    {
        if (!is_array($idOrPath)) {
            return $this->storageManager->delete($idOrPath);
        }

        $results = [];

        foreach ($idOrPath as $item) {
            try {
                $results[] = $this->storageManager->delete($item);
            } catch (\Exception $e) {
                $results[] = ['status' => false, 'error' => $e->getMessage(), 'item' => $item];
            }
        }

        return $results;
    }

    /**
     * Handles uploads initiated from one or more remote URLs.
     *
     * @param string|array<string> $urls
     * @param string       $storagePath
     * @param string       $disk
     * @param array<string, mixed> $options
     * @param array<string, mixed> $customRules
     * @return UploadResult|array<int, UploadResult|array<string, mixed>>
     */
    private function handleUrlSource(
        string|array $urls,
        string       $storagePath,
        string       $disk,
        array        $options,
        array        $customRules,
    ): UploadResult|array {
        if (!is_array($urls)) {
            return $this->processSingleUrl($urls, $storagePath, $disk, $options, $customRules);
        }

        $results = [];

        foreach ($urls as $url) {
            try {
                $results[] = $this->processSingleUrl($url, $storagePath, $disk, $options, $customRules);
            } catch (\Exception $e) {
                Log::error("URL upload failed [{$url}]: " . $e->getMessage());
                $results[] = ['status' => false, 'error' => $e->getMessage(), 'url' => $url];
            }
        }

        return $results;
    }

    /**
     * Downloads a single URL and stores the result.
     *
     * @param string $url
     * @param string $storagePath
     * @param string $disk
     * @param array<string, mixed> $options
     * @param array<string, mixed> $customRules
     * @return UploadResult
     */
    private function processSingleUrl(
        string $url,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): UploadResult {
        $file = null;

        try {
            $file = $this->urlDownloader->download($url, $options);

            $this->enforceQuota($file);

            $this->fileValidator->validate($file, $file->getMimeType() ?? '', 'file', $customRules);

            $this->virusScanner->scan((string) $file->getRealPath());

            return $this->storageManager->store($file, $storagePath, $disk, $options);

        } finally {
            $this->cleanupTempFile($file);
        }
    }

    /**
     * Handles uploads submitted through an HTTP Request object.
     *
     * Supports three sub-cases:
     *   1. Chunked upload — returns progress JSON for incomplete chunks and a result for the final chunk.
     *   2. Multiple files under a "files" field.
     *   3. Single file under the configured field name.
     *
     * @param Request $request
     * @param string  $storagePath
     * @param string  $disk
     * @param array<string, mixed> $options
     * @param array<string, mixed> $customRules
     * @return UploadResult|array<mixed>
     */
    private function handleRequestSource(
        Request $request,
        string  $storagePath,
        string  $disk,
        array   $options,
        array   $customRules,
    ): UploadResult|array {
        $fieldName = $options['field_name'] ?? 'file';

        // Check if this is a chunked upload from our JS client
        if ($request->has(['chunkNumber', 'totalChunks', 'originalName'])) {
            $chunkIndex   = (int) $request->input('chunkNumber') - 1; // 0-based index
            $totalChunks  = (int) $request->input('totalChunks');
            $originalName = (string) $request->input('originalName');
            $sessionId    = $request->input('sessionId');
            
            $file = $request->file($fieldName);
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new RuntimeException('Chunk file is invalid or missing.');
            }

            if (!config('media-vault.database.enabled', true)) {
                throw new RuntimeException('Resumable chunked uploads require database tracking to be enabled (media-vault.database.enabled = true).');
            }

            $resumable = app(ResumableUploadService::class);
            
            if (!empty($sessionId)) {
                $existingSession = \MohamedSamy902\LaravelMediaVault\Models\UploadSession::where('session_id', $sessionId)->first();
                if ($existingSession === null || $existingSession->status === 'complete' || !$existingSession->isValid()) {
                    $sessionId = null;
                }
            }

            if (empty($sessionId)) {
                $totalSize = (int) $request->input('totalSize', $file->getSize() * $totalChunks);
                $session   = $resumable->startSession(
                    $originalName,
                    $file->getMimeType() ?? 'application/octet-stream',
                    $totalSize,
                    $totalChunks,
                    $disk,
                    trim($storagePath, '/'),
                    $options,
                );
                $sessionId = $session->session_id;
            }

            $progress = $resumable->uploadChunk((string) $sessionId, $chunkIndex, $file);
            
            if (empty($progress['missing'])) {
                return $resumable->completeSession((string) $sessionId, $options);
            }
            
            return [
                'status'    => true,
                'sessionId' => $sessionId,
                'done'      => ($progress['received'] / $progress['total']) * 100,
                'missing'   => $progress['missing']
            ];
        }

        $files = $request->file('files') ?? $request->file($fieldName);

        if (is_array($files)) {
            return $this->processFileArray($files, $storagePath, $disk, $options, $customRules);
        }

        $file = $request->file($fieldName);

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new RuntimeException('The uploaded file is invalid or missing.');
        }

        $this->enforceQuota($file);

        $this->fileValidator->validate($file, $file->getMimeType() ?? '', $fieldName, $customRules);

        $this->virusScanner->scan((string) $file->getRealPath());

        return $this->storageManager->store($file, $storagePath, $disk, $options);
    }

    /**
     * Handles a direct UploadedFile or array of UploadedFiles passed by the caller.
     *
     * @param mixed  $source
     * @param string $storagePath
     * @param string $disk
     * @param array<string, mixed> $options
     * @param array<string, mixed> $customRules
     * @return UploadResult|array<mixed>
     */
    private function handleDirectSource(
        mixed  $source,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): UploadResult|array {
        if (is_array($source)) {
            return $this->processFileArray($source, $storagePath, $disk, $options, $customRules);
        }

        if (!$source instanceof UploadedFile || !$source->isValid()) {
            throw new RuntimeException('Invalid file: expected a valid UploadedFile instance.');
        }

        $this->enforceQuota($source);

        $this->fileValidator->validate($source, $source->getMimeType() ?? '', 'file', $customRules);

        $this->virusScanner->scan((string) $source->getRealPath());

        return $this->storageManager->store($source, $storagePath, $disk, $options);
    }

    /**
     * Processes an array of files, collecting results and continuing on partial failures.
     *
     * @param array<mixed> $files
     * @param string $storagePath
     * @param string $disk
     * @param array<string, mixed> $options
     * @param array<string, mixed> $customRules
     * @return array<int, UploadResult|array<string, mixed>>
     */
    private function processFileArray(
        array  $files,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): array {
        $results = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $name      = $file instanceof UploadedFile ? $file->getClientOriginalName() : 'unknown';
                $results[] = ['status' => false, 'error' => 'Invalid file.', 'original_name' => $name];
                continue;
            }

            try {
                $this->enforceQuota($file);
                $this->fileValidator->validate($file, $file->getMimeType() ?? '', 'file', $customRules);
                $this->virusScanner->scan((string) $file->getRealPath());
                $results[] = $this->storageManager->store($file, $storagePath, $disk, $options);
            } catch (\Exception $e) {
                $results[] = [
                    'status'        => false,
                    'error'         => $e->getMessage(),
                    'original_name' => $file->getClientOriginalName(),
                ];
            }
        }

        return $results;
    }

    /**
     * Checks the quota for the authenticated user before storing a file.
     *
     * Does nothing when quota enforcement is disabled or no user is authenticated.
     *
     * @param UploadedFile $file
     * @throws \MohamedSamy902\LaravelMediaVault\Exceptions\QuotaExceededException
     */
    private function enforceQuota(UploadedFile $file): void
    {
        // ✅ Fixed: removed extra parentheses that caused null to be treated as true
        if (!config('media-vault.quota.enabled', false) || !Auth::check()) {
            return;
        }

        $this->quotaManager->check((int) Auth::id(), (int) $file->getSize());
    }

    /**
     * Verifies that the required driver package is installed for cloud disks.
     *
     * Throws a RuntimeException with an actionable install command when the
     * adapter class is absent, rather than letting PHP throw a cryptic error.
     *
     * @param string $disk
     * @throws RuntimeException
     */
    protected function assertCloudDependenciesInstalled(string $disk): void
    {
        $requirements = [
            's3'  => [
                'class'   => 'League\Flysystem\AwsS3V3\AwsS3V3Adapter',
                'package' => 'league/flysystem-aws-s3-v3',
            ],
            'gcs' => [
                'class'   => 'Spatie\LaravelGoogleCloudStorage\GoogleCloudStorageAdapter',
                'package' => 'spatie/laravel-google-cloud-storage',
            ],
        ];

        if (!isset($requirements[$disk])) {
            return;
        }

        if (!class_exists($requirements[$disk]['class'])) {
            $pkg = $requirements[$disk]['package'];
            throw new RuntimeException(
                "Storage disk [{$disk}] requires the [{$pkg}] package. "
                . "Install it with: composer require {$pkg}"
            );
        }
    }

    /**
     * Removes a temp file left by a URL download, ignoring errors.
     *
     * @param UploadedFile|null $file
     */
    private function cleanupTempFile(?UploadedFile $file): void
    {
        if ($file === null) {
            return;
        }

        $path = $file->getRealPath();

        if ($path !== false && is_file($path)) {
            \Illuminate\Support\Facades\File::delete($path);
        }
    }
}
