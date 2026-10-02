<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;

/**
 * Moves files and thumbnails between active storage and .trash/.
 */
final class TrashManager
{
    /**
     * Moves a logical file path (and known thumbnails) into .trash/.
     *
     * @param array<string, string>|null $thumbnailPaths Map of size => path (logical/active paths)
     */
    public function moveToTrash(string $disk, string $logicalPath, ?array $thumbnailPaths = null): bool
    {
        $moved = $this->movePath($disk, $logicalPath, TrashPath::toTrash($logicalPath));

        foreach ($this->resolveThumbnailPaths($logicalPath, $thumbnailPaths) as $thumbPath) {
            if (TrashPath::isTrashed($thumbPath)) {
                continue;
            }
            $this->movePath($disk, $thumbPath, TrashPath::toTrash($thumbPath));
        }

        return $moved;
    }

    /**
     * Moves a logical file path (and thumbnails) out of .trash/ back to the active path.
     *
     * @param array<string, string>|null $thumbnailPaths Map of size => path (logical/active paths)
     */
    public function restoreFromTrash(string $disk, string $logicalPath, ?array $thumbnailPaths = null): bool
    {
        $logicalPath = TrashPath::normalize($logicalPath);
        $trashPath = TrashPath::toTrash($logicalPath);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        // Backward compatibility with the previous flat trash layout: uploads/.trash/{basename}
        if (!$storage->exists($trashPath)) {
            $legacyTrashPath = TrashPath::storageBase() . '/.trash/' . basename($logicalPath);
            if ($legacyTrashPath !== $trashPath && $storage->exists($legacyTrashPath)) {
                $trashPath = $legacyTrashPath;
            }
        }

        $restored = $this->movePath($disk, $trashPath, $logicalPath);

        foreach ($this->resolveThumbnailPaths($logicalPath, $thumbnailPaths) as $thumbPath) {
            $thumbTrash = TrashPath::toTrash($thumbPath);
            if (!$storage->exists($thumbTrash)) {
                $legacyThumbTrash = TrashPath::storageBase() . '/.trash/' . basename($thumbPath);
                if ($legacyThumbTrash !== $thumbTrash && $storage->exists($legacyThumbTrash)) {
                    $thumbTrash = $legacyThumbTrash;
                }
            }
            $this->movePath($disk, $thumbTrash, $thumbPath);
        }

        return $restored;
    }

    /**
     * Permanently deletes a file from active path and/or trash, including thumbnails.
     *
     * @param array<string, string>|null $thumbnailPaths
     */
    public function purge(string $disk, string $logicalPath, ?array $thumbnailPaths = null): void
    {
        $candidates = array_unique([
            TrashPath::normalize($logicalPath),
            TrashPath::toTrash($logicalPath),
            TrashPath::fromTrash($logicalPath),
        ]);

        foreach ($candidates as $path) {
            $this->deleteIfExists($disk, $path);
        }

        foreach ($this->resolveThumbnailPaths($logicalPath, $thumbnailPaths) as $thumbPath) {
            $this->deleteIfExists($disk, $thumbPath);
            $this->deleteIfExists($disk, TrashPath::toTrash($thumbPath));
        }
    }

    public function existsAnywhere(string $disk, string $logicalPath): bool
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        return $storage->exists(TrashPath::normalize($logicalPath))
            || $storage->exists(TrashPath::toTrash($logicalPath))
            || $storage->exists(TrashPath::fromTrash($logicalPath));
    }

    public function physicalExists(string $disk, string $logicalPath, bool $trashed): bool
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        return $storage->exists(TrashPath::physical($logicalPath, $trashed));
    }

    /**
     * @param array<string, string>|null $thumbnailPaths
     * @return array<int, string>
     */
    private function resolveThumbnailPaths(string $logicalPath, ?array $thumbnailPaths): array
    {
        $paths = [];

        if (is_array($thumbnailPaths)) {
            foreach ($thumbnailPaths as $thumbPath) {
                if (is_string($thumbPath) && $thumbPath !== '') {
                    $paths[] = TrashPath::normalize(
                        TrashPath::isTrashed($thumbPath) ? TrashPath::fromTrash($thumbPath) : $thumbPath
                    );
                }
            }
        }

        // Fallback to configured thumbnail naming convention.
        foreach (\MohamedSamy902\LaravelMediaVault\Support\ThumbnailPath::allFor($logicalPath) as $thumbPath) {
            $paths[] = TrashPath::normalize($thumbPath);
        }

        return array_values(array_unique($paths));
    }

    private function movePath(string $disk, string $from, string $to): bool
    {
        $from = TrashPath::normalize($from);
        $to = TrashPath::normalize($to);

        if ($from === $to) {
            return true;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        if (!$storage->exists($from)) {
            return false;
        }

        $targetDir = dirname($to);
        if ($targetDir !== '.' && !$storage->exists($targetDir)) {
            $storage->makeDirectory($targetDir);
        }

        // Overwrite destination if a stale trash/active copy exists.
        if ($storage->exists($to)) {
            $storage->delete($to);
        }

        try {
            return (bool) $storage->move($from, $to);
        } catch (\Throwable $e) {
            Log::error("TrashManager failed to move [{$from}] → [{$to}]: " . $e->getMessage());

            return false;
        }
    }

    private function deleteIfExists(string $disk, string $path): void
    {
        $path = TrashPath::normalize($path);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $storage->delete($path);
        }
    }
}
