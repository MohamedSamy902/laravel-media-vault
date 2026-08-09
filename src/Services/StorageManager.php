<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use RuntimeException;

/**
 * Persists uploaded files to the configured storage disk.
 *
 * Responsibilities:
 *   - Writing file content to the storage disk
 *   - Triggering optional image processing before storage
 *   - Delegating thumbnail generation to the ImageProcessor
 *   - Generating public URLs with optional CDN rewriting
 *   - Persisting metadata to the database when enabled
 *   - Deleting files and their associated thumbnails
 */
final class StorageManager
{
    public function __construct(
        private readonly ImageProcessorContract $imageProcessor,
        private readonly MimeTypeResolver       $mimeResolver,
    ) {}

    /**
     * Stores an uploaded file and returns a typed result object.
     *
     * @param UploadedFile $file                  The validated uploaded file
     * @param string       $path                  The destination directory path on the disk
     * @param string       $disk                  The storage disk name
     * @param array<string, mixed> $options       Per-request overrides (convert_to, quality)
     *
     * @return UploadResult
     * @throws RuntimeException When the file cannot be written to storage
     */
    public function store(
        UploadedFile $file,
        string       $path,
        string       $disk,
        array        $options = [],
    ): UploadResult {
        $fileSize     = $file->getSize();
        $config       = config('media-vault');
        $mime         = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = $file->getClientOriginalName();
        $extension    = $this->resolveExtension($file, $mime, $config, $options);
        $fileName     = Str::uuid() . '.' . $extension;
        $fullPath     = ltrim($path . '/' . $fileName, '/');
        $thumbnailUrls = [];

        try {
            $this->writeFile($file, $fullPath, $disk, $mime, $config, $options);

            $thumbnailPaths = $this->maybeGenerateThumbnails(
                $file, $fullPath, $fileName, $disk, $mime, $config
            );

            $databaseId = $this->maybeWriteRecord(
                $originalName, $fileName, $fullPath, $disk, $mime, $fileSize, $thumbnailPaths, $config
            );

            $thumbnailUrls = [];
            foreach ($thumbnailPaths as $sizeName => $thumbPath) {
                $thumbnailUrls[$sizeName] = $this->buildUrl($config, $disk, $thumbPath);
            }

            return new UploadResult(
                status:        true,
                originalName:  $originalName,
                path:          $fullPath,
                url:           $this->buildUrl($config, $disk, $fullPath),
                mimeType:      $mime,
                type:          $this->mimeResolver->toFileType($mime),
                size:          $fileSize,
                thumbnailUrls: $thumbnailUrls,
                id:            $databaseId,
                disk:          $disk,
            );

        } catch (\Throwable $e) {
            // ✅ Rollback both the main file AND all generated thumbnails
            $this->rollbackFile($disk, $fullPath);
            $this->deleteThumbnails($disk, $fullPath, $fileName, $config);
            Log::error("File storage failed [{$originalName}]: " . $e->getMessage());
            throw new RuntimeException('Failed to store file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deletes a file and its thumbnails from the storage disk.
     *
     * @param int|string $idOrPath The database record ID or the file path
     * @return array{status: bool, message: string}
     * @throws RuntimeException When the file is not found or cannot be deleted
     */
    public function delete(int|string $idOrPath): array
    {
        $config = is_array(config('media-vault')) ? config('media-vault') : [];

        if ($config['database']['enabled'] ?? false) {
            return $this->deleteByRecord($idOrPath, $config);
        }

        return $this->deleteByPath((string) $idOrPath, $config);
    }

    /**
     * Writes file content to the storage disk.
     *
     * @param UploadedFile $file
     * @param string       $fullPath
     * @param string       $disk
     * @param string       $mime
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function writeFile(
        UploadedFile $file,
        string       $fullPath,
        string       $disk,
        string       $mime,
        array        $config,
        array        $options,
    ): void {
        $imageConfig       = $config['processing']['image'] ?? [];
        $compressionConfig = $config['compression'] ?? [];

        $compressionEnabled = (bool) ($compressionConfig['enabled'] ?? false);
        $compressionLevel   = (int) ($compressionConfig['level'] ?? $compressionConfig['quality'] ?? 80);

        $processingEnabled = str_starts_with($mime, 'image')
            && ($imageConfig['enabled'] ?? false);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        if ($processingEnabled || ($compressionEnabled && str_starts_with($mime, 'image') && $mime !== 'image/svg+xml')) {
            if ($compressionEnabled) {
                $options['quality'] = $compressionLevel;
            }
            $content = $this->imageProcessor->process((string) $file->getRealPath(), $imageConfig, $options);
            $diskStorage->put($fullPath, $content);
        } else {
            $dir = match ($d = dirname($fullPath)) {
                '.' => '',
                default => $d,
            };
            
            // Basic SVG sanitization to strip script tags
            if (str_contains($mime, 'svg')) {
                // Prevent XML Entity Expansion / Memory Exhaustion by limiting SVG size to 2MB before parsing
                if ($file->getSize() > 2 * 1024 * 1024) {
                    throw new RuntimeException("SVG file exceeds the maximum allowed size of 2MB for safe parsing.");
                }

                $svgContent = file_get_contents((string) $file->getRealPath());
                $sanitizer = new \enshrined\svgSanitize\Sanitizer();
                $cleanSvg = $sanitizer->sanitize($svgContent !== false ? $svgContent : '');
                
                if ($cleanSvg !== false) {
                    $diskStorage->put($fullPath, $cleanSvg);
                } else {
                    throw new RuntimeException("Failed to sanitize SVG file.");
                }
            } else {
                $sourceRealPath = (string) $file->getRealPath();
                $targetPath     = method_exists($diskStorage, 'path') ? $diskStorage->path($fullPath) : null;

                if ($targetPath !== null && $sourceRealPath !== '' && file_exists($sourceRealPath)) {
                    $targetDir = dirname($targetPath);
                    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        // Directory creation failed, fallback to Flysystem
                    } elseif (@copy($sourceRealPath, $targetPath)) {
                        return;
                    }
                }

                $diskStorage->putFileAs($dir, $file, basename($fullPath));
            }
        }
    }

    /**
     * Generates thumbnails for image files when the feature is enabled.
     *
     * @param UploadedFile $file
     * @param string       $fullPath
     * @param string       $fileName
     * @param string       $disk
     * @param string       $mime
     * @param array<string, mixed> $config
     * @return array<string, string> Map of size name to public URL
     */
    private function maybeGenerateThumbnails(
        UploadedFile $file,
        string       $fullPath,
        string       $fileName,
        string       $disk,
        string       $mime,
        array        $config,
    ): array {
        if (!str_starts_with($mime, 'image') || !($config['thumbnails']['enabled'] ?? false)) {
            return [];
        }

        return $this->generateThumbnails(
            (string) $file->getRealPath(),
            $fullPath,
            $fileName,
            $disk,
            $config['thumbnails']['sizes'] ?? [],
            $config,
        );
    }

    /**
     * Iterates over the configured thumbnail sizes and stores each one.
     *
     * @param string $realPath    Absolute path to the source image on the local filesystem
     * @param string $fullPath    The stored path of the original file
     * @param string $fileName    The stored filename (UUID + extension)
     * @param string $disk        The storage disk name
     * @param array<string, mixed> $sizes Thumbnail size definitions from config
     * @param array<string, mixed> $config Full package config
     * @return array<string, string>
     */
    private function generateThumbnails(
        string $realPath,
        string $fullPath,
        string $fileName,
        string $disk,
        array  $sizes,
        array  $config,
    ): array {
        $dir      = dirname($fullPath);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext      = pathinfo($fileName, PATHINFO_EXTENSION);
        $paths    = [];

        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        foreach ($sizes as $sizeName => $dimensions) {
            try {
                $width  = isset($dimensions['width'])  && $dimensions['width']  > 0 ? (int) $dimensions['width']  : null;
                $height = isset($dimensions['height']) && $dimensions['height'] > 0 ? (int) $dimensions['height'] : null;
                $crop   = (bool) ($dimensions['crop'] ?? false);

                $content   = $this->imageProcessor->thumbnail($realPath, $width, $height, $crop);
                $thumbPath = "{$dir}/thumb_{$sizeName}_{$baseName}.{$ext}";

                $diskStorage->put($thumbPath, $content);

                $paths[$sizeName] = $thumbPath;

            } catch (\Exception $e) {
                Log::error("Thumbnail [{$sizeName}] generation failed: " . $e->getMessage());
            }
        }

        return $paths;
    }

    /**
     * Writes a record to the database when database tracking is enabled.
     *
     * @param string  $originalName
     * @param string  $fileName
     * @param string  $fullPath
     * @param string  $disk
     * @param string  $mime
     * @param int|null $size
     * @param array<string, string> $thumbnails Map of generated thumbnail size => URL
     * @param array<string, mixed> $config
     * @return int|null The ID of the created record, or null when DB is disabled
     */
    private function maybeWriteRecord(
        string  $originalName,
        string  $fileName,
        string  $fullPath,
        string  $disk,
        string  $mime,
        ?int    $size,
        array   $thumbnails,
        array   $config,
    ): ?int {
        if (!($config['database']['enabled'] ?? false)) {
            return null;
        }

        $model = $config['database']['model'];

        // ✅ Wrap in transaction: if DB insert fails, the caller's catch block
        // will rollback the already-written physical file and thumbnails.
        $record = \Illuminate\Support\Facades\DB::transaction(
            static function () use ($model, $originalName, $fileName, $fullPath, $disk, $mime, $size, $thumbnails) {
                $modelInstance = new $model();
                $modelInstance->forceFill([
                    'original_name' => $originalName,
                    'name'          => $fileName,
                    'path'          => $fullPath,
                    'disk'          => $disk,
                    'mime_type'     => $mime,
                    'size'          => $size,
                    'type'          => (new \MohamedSamy902\LaravelMediaVault\Services\MimeTypeResolver())->toFileType($mime),
                    'user_id'       => Auth::id(),
                    'is_used'       => false,
                    'metadata'      => empty($thumbnails) ? null : ['thumbnails' => $thumbnails],
                ]);
                $modelInstance->save();
                return $modelInstance;
            }
        );

        return $record->id;
    }

    /**
     * Deletes a file using its database record for path and disk resolution.
     *
     * @param int|string $idOrPath
     * @param array<string, mixed> $config
     * @return array{status: bool, message: string}
     */
    private function deleteByRecord(int|string $idOrPath, array $config): array
    {
        $model = $config['database']['model'];

        try {
            $record = is_numeric($idOrPath)
                ? $model::findOrFail($idOrPath)
                : $model::where('path', $idOrPath)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new RuntimeException("File [{$idOrPath}] was not found in the database.", 0, $e);
        }

        $disk = $record->disk ?? $config['storage']['disk'];

        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        if ($diskStorage->exists($record->path)) {
            if (!$diskStorage->delete($record->path)) {
                throw new \RuntimeException("Failed to delete physical file: {$record->path}");
            }
        } else {
            Log::warning("Physical file not found during delete: {$record->path}");
        }

        if (!empty($record->metadata['thumbnails']) && is_array($record->metadata['thumbnails'])) {
            foreach ($record->metadata['thumbnails'] as $thumbPath) {
                if (is_string($thumbPath) && $diskStorage->exists($thumbPath)) {
                    if (!$diskStorage->delete($thumbPath)) {
                        throw new \RuntimeException("Failed to delete thumbnail: {$thumbPath}");
                    }
                }
            }
        } else {
            $this->deleteThumbnails($disk, $record->path, $record->name, $config);
        }

        $record->delete();

        Log::info("File deleted successfully [ID/Path: {$idOrPath}].");

        return ['status' => true, 'message' => 'File deleted successfully.'];
    }

    /**
     * Deletes a file directly by its storage path without consulting the database.
     *
     * @param string $path
     * @param array<string, mixed> $config
     * @return array{status: bool, message: string}
     */
    private function deleteByPath(string $path, array $config): array
    {
        $disk = $config['storage']['disk'];

        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        if (!$diskStorage->exists($path)) {
            throw new RuntimeException("File not found in storage: {$path}");
        }

        if (!$diskStorage->delete($path)) {
            throw new RuntimeException("Failed to delete physical file: {$path}");
        }

        $fileName = basename($path);
        $this->deleteThumbnails($disk, $path, $fileName, $config);

        Log::info("File deleted from storage: {$path}.");

        return ['status' => true, 'message' => 'File deleted successfully.'];
    }

    /**
     * Attempts to delete all generated thumbnails for a given file.
     *
     * @param string $disk
     * @param string $filePath
     * @param string $fileName
     * @param array<string, mixed> $config
     */
    private function deleteThumbnails(string $disk, string $filePath, string $fileName, array $config): void
    {
        $dir = dirname($filePath);
        $dir = $dir === '.' ? '' : $dir . '/';
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        try {
            $thumbnailsConfig = $config['thumbnails']['sizes'] ?? [];
            foreach (array_keys($thumbnailsConfig) as $sizeName) {
                $thumbPath = "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
                if ($diskStorage->exists($thumbPath)) {
                    if (!$diskStorage->delete($thumbPath)) {
                        throw new \RuntimeException("Failed to delete thumbnail: {$thumbPath}");
                    }
                }
            }
        } catch (\Exception $e) {
            if ($e instanceof \RuntimeException) {
                throw $e; // Do not swallow our own deletion failure exceptions
            }
            Log::error("Failed to clean up thumbnails: " . $e->getMessage());
        }
    }

    /**
     * Resolves the file extension to use for storage.
     *
     * @param UploadedFile $file
     * @param string       $mime
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     * @return string
     */
    private function resolveExtension(UploadedFile $file, string $mime, array $config, array $options): string
    {
        $originalExt   = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $guessedExt    = $file->guessExtension() ?: $file->extension();
        $dangerousExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'exe', 'bat', 'sh'];

        if (in_array($originalExt, $dangerousExts, true)) {
            $ext = $guessedExt ?: 'txt';
        } else {
            $ext = $originalExt !== '' ? $originalExt : ($guessedExt ?: 'bin');
        }

        $imageConfig = $config['processing']['image'] ?? [];
        $isImage     = str_starts_with($mime, 'image');

        if ($isImage && ($imageConfig['enabled'] ?? false)) {
            $convertTo = $options['convert_to'] ?? $imageConfig['convert_to'] ?? null;
            if ($convertTo) {
                return (string) $convertTo;
            }
        }

        return $ext;
    }

    /**
     * Builds the public URL for a stored file, applying CDN rewriting if configured.
     *
     * @param array<string, mixed> $config
     * @param string $disk
     * @param string $path
     * @return string
     */
    private function buildUrl(array $config, string $disk, string $path): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);
        $url = $diskStorage->url($path);

        $cdn = $config['storage']['cdn'] ?? [];
        if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
            $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            return rtrim((string) $cdn['url'], '/') . '/' . $relativePath;
        }

        return $url;
    }

    /**
     * Removes a file from storage if it exists, used for rollback on failure.
     *
     * @param string $disk
     * @param string $path
     */
    private function rollbackFile(string $disk, string $path): void
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);
        if ($diskStorage->exists($path)) {
            $diskStorage->delete($path);
        }
    }


}
