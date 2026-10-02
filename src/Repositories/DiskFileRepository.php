<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;
use MohamedSamy902\LaravelMediaVault\Events\FileDeletedEvent;
use MohamedSamy902\LaravelMediaVault\Events\FileRestoredEvent;
use MohamedSamy902\LaravelMediaVault\Services\FileUsageScanner;
use MohamedSamy902\LaravelMediaVault\Services\TrashManager;
use MohamedSamy902\LaravelMediaVault\Support\FileCategories;
use MohamedSamy902\LaravelMediaVault\Support\MediaUrl;
use MohamedSamy902\LaravelMediaVault\Support\StreamHash;
use MohamedSamy902\LaravelMediaVault\Support\ThumbnailPath;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;

class DiskFileRepository implements FileRepositoryContract
{
    public function __construct(
        protected FileUsageScanner $scanner,
        protected TrashManager $trashManager,
    ) {
    }

    /**
     * @return \Illuminate\Filesystem\FilesystemAdapter
     */
    protected function getDisk()
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk(config('media-vault.storage.disk', 'public'));

        return $disk;
    }

    protected function getBasePath(): string
    {
        return config('media-vault.storage.path', 'uploads');
    }

    /**
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->getFilteredFiles([], $perPage);
    }

    /**
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $disk = $this->getDisk();
        $files = $this->cachedListing();
        $filter = $filters['filter'] ?? 'all';

        if ($filter === 'deleted') {
            $files = array_values(array_filter($files, fn ($path) => TrashPath::isTrashed($path)));
        } else {
            // Active library never includes trash.
            $files = array_values(array_filter($files, fn ($path) => !TrashPath::isTrashed($path)));

            if ($filter === 'images') {
                $files = array_values(array_filter($files, fn ($p) => FileCategories::isImagePath($p)));
            } elseif ($filter === 'videos') {
                $files = array_values(array_filter($files, fn ($p) => FileCategories::isVideoPath($p)));
            } elseif ($filter === 'documents') {
                $files = array_values(array_filter($files, fn ($p) => FileCategories::isDocumentPath($p)));
            } elseif ($filter === 'used') {
                $orphans = Cache::get('media-vault:orphans', []);
                $orphansMap = array_flip((array) $orphans);
                $files = array_values(array_filter($files, fn ($p) => !isset($orphansMap[$p])));
            }

            $files = array_values(array_filter($files, fn ($p) => !ThumbnailPath::isThumbnail($p)));
        }

        $currentPage = (int) request()->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $items = array_slice($files, $offset, $perPage);

        $orphans = Cache::get('media-vault:orphans', []);
        $orphansMap = array_flip((array) $orphans);

        $dtos = array_map(function ($path) use ($orphansMap, $filter) {
            $isUsed = !isset($orphansMap[$path]);
            $logical = $filter === 'deleted' ? TrashPath::fromTrash($path) : $path;
            $isTrashed = $filter === 'deleted' || TrashPath::isTrashed($path);

            return $this->toDto(
                path: $filter === 'deleted' ? $path : $logical,
                isUsed: $isUsed,
                logicalPath: $logical,
                isTrashed: $isTrashed,
            );
        }, $items);

        return new LengthAwarePaginator(
            $dtos,
            count($files),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    public function delete(string $path, bool $force = false): bool
    {
        $diskName = (string) config('media-vault.storage.disk', 'public');
        $logical = TrashPath::isTrashed($path) ? TrashPath::fromTrash($path) : TrashPath::normalize($path);
        $disk = $this->getDisk();

        if ($force) {
            if (!$this->trashManager->existsAnywhere($diskName, $logical) && !$disk->exists($path)) {
                return false;
            }

            $this->trashManager->purge($diskName, $logical);
            event(new FileDeletedEvent($logical, true));

            return true;
        }

        // Soft delete — move active file into structured .trash/ path.
        if (!$disk->exists($logical) && !$disk->exists(TrashPath::toTrash($logical))) {
            return false;
        }

        if ($disk->exists(TrashPath::toTrash($logical)) && !$disk->exists($logical)) {
            return true; // already trashed
        }

        $moved = $this->trashManager->moveToTrash($diskName, $logical);
        if ($moved) {
            event(new FileDeletedEvent($logical, false));
        }

        return $moved;
    }

    public function restore(string $path): bool
    {
        $diskName = (string) config('media-vault.storage.disk', 'public');
        $logical = TrashPath::isTrashed($path) ? TrashPath::fromTrash($path) : TrashPath::normalize($path);

        $restored = $this->trashManager->restoreFromTrash($diskName, $logical);
        if ($restored) {
            event(new FileRestoredEvent($logical));
        }

        return $restored;
    }

    /**
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getOrphanedFiles(int $perPage = 20): LengthAwarePaginator
    {
        $orphanedPaths = Cache::remember('media-vault:orphans', 3600, function () {
            $usedPathsMap = [];
            foreach ($this->scanner->getAllUsedPaths() as $path) {
                $usedPathsMap[$path] = true;
            }

            $allFiles = $this->cachedListing();
            $orphans = [];
            foreach ($allFiles as $path) {
                if (TrashPath::isTrashed($path)) {
                    continue;
                }
                if (!isset($usedPathsMap[$path])) {
                    $orphans[] = $path;
                }
            }

            return $orphans;
        });

        $currentPage = (int) request()->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $items = array_slice($orphanedPaths, $offset, $perPage);
        $dtos = array_map(fn ($path) => $this->toDto($path, false), $items);

        return new LengthAwarePaginator(
            $dtos,
            count($orphanedPaths),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    /**
     * @return array<string, array<int, FileDto>>
     */
    public function getDuplicateFiles(): array
    {
        return Cache::remember('media-vault:duplicates', 3600, function () {
            $disk = $this->getDisk();
            $allFiles = $this->cachedListing();
            $diskName = (string) config('media-vault.storage.disk', 'public');

            $sizeGroups = [];
            foreach ($allFiles as $path) {
                if (TrashPath::isTrashed($path) || ThumbnailPath::isThumbnail($path)) {
                    continue;
                }
                $size = $disk->size($path);
                $sizeGroups[$size][] = $path;
            }

            $duplicates = [];
            foreach ($sizeGroups as $paths) {
                if (count($paths) <= 1) {
                    continue;
                }

                $hashGroups = [];
                foreach ($paths as $path) {
                    $hash = StreamHash::md5($diskName, $path);
                    $hashGroups[$hash][] = $path;
                }

                foreach ($hashGroups as $hash => $hashPaths) {
                    if (count($hashPaths) > 1) {
                        $duplicates[$hash] = array_map(fn ($p) => $this->toDto($p, true), $hashPaths);
                    }
                }
            }

            return $duplicates;
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        return Cache::remember('media-vault:disk-stats', 60, function () {
            $disk = $this->getDisk();
            $files = $this->cachedListing();

            $totalSize = 0;
            $images = 0;
            $videos = 0;
            $documents = 0;
            $other = 0;
            $activeCount = 0;
            $trashedCount = 0;

            foreach ($files as $path) {
                if (TrashPath::isTrashed($path)) {
                    $trashedCount++;
                    continue;
                }

                if (ThumbnailPath::isThumbnail($path)) {
                    continue;
                }

                $activeCount++;
                $totalSize += $disk->size($path);

                if (FileCategories::isImagePath($path)) {
                    $images++;
                } elseif (FileCategories::isVideoPath($path)) {
                    $videos++;
                } elseif (FileCategories::isDocumentPath($path)) {
                    $documents++;
                } else {
                    $other++;
                }
            }

            $orphansCount = count(Cache::get('media-vault:orphans', []));

            return [
                'total_files' => $activeCount,
                'total_size' => $totalSize,
                'used_files' => max(0, $activeCount - $orphansCount),
                'unused_files' => $orphansCount,
                'trashed_files' => $trashedCount,
                'images' => $images,
                'videos' => $videos,
                'documents' => $documents,
                'other' => $other,
            ];
        });
    }

    protected function toDto(
        string $path,
        bool $isUsed = true,
        ?string $logicalPath = null,
        ?bool $isTrashed = null,
    ): FileDto {
        $disk = $this->getDisk();
        $trashed = $isTrashed ?? TrashPath::isTrashed($path);
        $logical = $logicalPath ?? ($trashed ? TrashPath::fromTrash($path) : TrashPath::normalize($path));
        $physical = TrashPath::physical($logical, $trashed);

        if (!$disk->exists($physical)) {
            return new FileDto(
                path: $logical,
                name: basename($logical),
                mimeType: 'unknown',
                size: 0,
                lastModified: now()->toIso8601String(),
                isUsed: $isUsed,
                url: null,
                disk: config('media-vault.storage.disk', 'public'),
                disk_exists: false,
                is_missing: true,
                isTrashed: $trashed,
            );
        }

        return new FileDto(
            path: $logical,
            name: basename($logical),
            mimeType: $disk->mimeType($physical) ?: 'application/octet-stream',
            size: $disk->size($physical),
            lastModified: date('c', $disk->lastModified($physical)),
            isUsed: $isUsed,
            url: MediaUrl::forMaybeTrashed((string) config('media-vault.storage.disk', 'public'), $physical, $trashed),
            disk: config('media-vault.storage.disk', 'public'),
            disk_exists: true,
            is_missing: false,
            isTrashed: $trashed,
        );
    }

    /**
     * @return list<string>
     */
    protected function cachedListing(): array
    {
        $diskName = (string) config('media-vault.storage.disk', 'public');
        $base = $this->getBasePath();
        $key = "media-vault:disk-listing:{$diskName}:" . md5($base);

        /** @var list<string> $files */
        $files = Cache::remember($key, 60, function () {
            return $this->getDisk()->allFiles($this->getBasePath());
        });

        return $files;
    }

}
