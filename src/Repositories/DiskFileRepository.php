<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;
use MohamedSamy902\LaravelMediaVault\Services\FileUsageScanner;
use Symfony\Component\Finder\SplFileInfo;

class DiskFileRepository implements FileRepositoryContract
{
    public function __construct(protected FileUsageScanner $scanner)
    {
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
        $files = $disk->allFiles($this->getBasePath());
        
        if (!empty($filters['filter']) && $filters['filter'] !== 'all') {
            $filter = $filters['filter'];
            if ($filter === 'deleted') {
                $files = array_filter($files, fn($path) => str_contains($path, '.trash/'));
            } else {
                $files = array_filter($files, fn($path) => !str_contains($path, '.trash/'));
                if ($filter === 'images') {
                    $files = array_filter($files, fn($p) => str_starts_with((string)$disk->mimeType($p), 'image/'));
                } elseif ($filter === 'videos') {
                    $files = array_filter($files, fn($p) => str_starts_with((string)$disk->mimeType($p), 'video/'));
                } elseif ($filter === 'documents') {
                    $files = array_filter($files, fn($p) => str_contains((string)$disk->mimeType($p), 'pdf') || str_contains((string)$disk->mimeType($p), 'word'));
                } elseif ($filter === 'used') {
                    $orphans = Cache::get('media-vault:orphans', []);
                    $orphansMap = array_flip((array) $orphans);
                    $files = array_filter($files, fn($p) => !isset($orphansMap[$p]));
                }
            }
        } else {
            // Default: All active files
            $files = array_filter($files, fn($path) => !str_contains($path, '.trash/'));
        }

        $currentPage = (int) request()->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        
        $items = array_slice($files, $offset, $perPage);

        // Try to get orphans from cache to accurately mark isUsed on the 'All' tab
        $orphans = Cache::get('media-vault:orphans', []);
        $orphansMap = array_flip((array) $orphans);

        $dtos = array_map(function($path) use ($orphansMap) {
            $isUsed = !isset($orphansMap[$path]);
            return $this->toDto($path, $isUsed);
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
        $disk = $this->getDisk();
        if (!$disk->exists($path)) {
            return false;
        }

        if ($force) {
            $deleted = $disk->delete($path);
            if ($deleted) {
                event(new \MohamedSamy902\LaravelMediaVault\Events\FileDeletedEvent($path, true));
            }
            return $deleted;
        }

        // Soft delete -> move to trash
        $trashPath = $this->getBasePath() . '/.trash/' . basename($path);
        
        // Ensure trash dir exists
        if (!$disk->exists(dirname($trashPath))) {
            $disk->makeDirectory(dirname($trashPath));
        }

        $moved = $disk->move($path, $trashPath);
        if ($moved) {
            event(new \MohamedSamy902\LaravelMediaVault\Events\FileDeletedEvent($path, false));
        }
        return $moved;
    }

    public function restore(string $path): bool
    {
        $disk = $this->getDisk();

        // If the provided path is already a trash path, we deduce the target path
        if (str_contains($path, '.trash/')) {
            $trashPath = $path;
            $targetPath = $this->getBasePath() . '/' . config('media-vault.storage.default_folder', 'default') . '/' . basename($path);
        } else {
            // Otherwise it expects the original path
            $trashPath = $this->getBasePath() . '/.trash/' . basename($path);
            $targetPath = $path;
        }

        if (!$disk->exists($trashPath)) {
            return false;
        }

        // Ensure target directory exists
        if (!$disk->exists(dirname($targetPath))) {
            $disk->makeDirectory(dirname($targetPath));
        }

        $restored = $disk->move($trashPath, $targetPath);
        if ($restored) {
            event(new \MohamedSamy902\LaravelMediaVault\Events\FileRestoredEvent($targetPath));
        }
        return $restored;
    }

    /**
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getOrphanedFiles(int $perPage = 20): LengthAwarePaginator
    {
        // Ideally this comes from Cache populated by an Artisan command.
        $orphanedPaths = Cache::remember('media-vault:orphans', 3600, function () {
            $usedPathsMap = [];
            foreach ($this->scanner->getAllUsedPaths() as $path) {
                $usedPathsMap[$path] = true;
            }
            
            $allFiles = $this->getDisk()->allFiles($this->getBasePath());
            
            $orphans = [];
            foreach ($allFiles as $path) {
                if (!str_contains($path, '.trash/') && !isset($usedPathsMap[$path])) {
                    $orphans[] = $path;
                }
            }

            return $orphans;
        });

        $currentPage = (int) request()->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        
        $items = array_slice($orphanedPaths, $offset, $perPage);
        $dtos = array_map(fn($path) => $this->toDto($path, false), $items);

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
            $allFiles = $disk->allFiles($this->getBasePath());
            
            // 1. Group by size first (Smart Detection)
            $sizeGroups = [];
            foreach ($allFiles as $path) {
                if (str_contains($path, '.trash/')) continue;
                $size = $disk->size($path);
                $sizeGroups[$size][] = $path;
            }

            // 2. Hash only files that share the exact same size
            $duplicates = [];
            foreach ($sizeGroups as $size => $paths) {
                if (count($paths) > 1) {
                    $hashGroups = [];
                    foreach ($paths as $path) {
                        $hash = md5((string) $disk->get($path));
                        $hashGroups[$hash][] = $path;
                    }
                    
                    foreach ($hashGroups as $hash => $hashPaths) {
                        if (count($hashPaths) > 1) {
                            $duplicates[$hash] = array_map(fn($p) => $this->toDto($p, true), $hashPaths);
                        }
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
        $disk = $this->getDisk();
        $files = $disk->allFiles($this->getBasePath());
        
        $totalSize = 0;
        $images = 0;
        $videos = 0;
        $documents = 0;
        $other = 0;

        foreach ($files as $path) {
            if (str_contains($path, '.trash/')) continue;
            
            $totalSize += $disk->size($path);
            $mime = $disk->mimeType($path);

            if (str_starts_with((string)$mime, 'image/')) {
                $images++;
            } elseif (str_starts_with((string)$mime, 'video/')) {
                $videos++;
            } elseif (str_contains((string)$mime, 'pdf') || str_contains((string)$mime, 'word')) {
                $documents++;
            } else {
                $other++;
            }
        }

        // Ideally orphans count is read from Cache, otherwise we just return 0 to avoid scanning on dashboard load
        $orphansCount = count(Cache::get('media-vault:orphans', []));
        $totalFiles = count($files);

        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'used_files' => $totalFiles - $orphansCount,
            'unused_files' => $orphansCount,
            'images' => $images,
            'videos' => $videos,
            'documents' => $documents,
            'other' => $other,
        ];
    }

    protected function toDto(string $path, bool $isUsed = true): FileDto
    {
        $disk = $this->getDisk();
        
        if (!$disk->exists($path)) {
            return new FileDto(
                path: $path,
                name: basename($path),
                mimeType: 'unknown',
                size: 0,
                lastModified: now()->toIso8601String(),
                isUsed: $isUsed,
                url: null,
                disk: config('media-vault.storage.disk', 'public'),
                encoded_path: null,
                disk_exists: false,
                is_missing: true
            );
        }

        return new FileDto(
            path: $path,
            name: basename($path),
            mimeType: $disk->mimeType($path) ?: 'application/octet-stream',
            size: $disk->size($path),
            lastModified: date('c', $disk->lastModified($path)),
            isUsed: $isUsed,
            url: $disk->url($path),
            disk: config('media-vault.storage.disk', 'public')
        );
    }
}
