<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaSourceContract;
use MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager;
use MohamedSamy902\LaravelMediaVault\Sources\CentralMediaSource;
use MohamedSamy902\LaravelMediaVault\Sources\CustomMediaSource;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;

class MultiSourceFileRepository implements FileRepositoryContract
{
    /** @var array<MediaSourceContract> */
    protected array $sources = [];

    public function __construct(
        protected DatabaseFileRepository $centralRepository,
        protected MediaSourceManager $sourceManager
    ) {
        // Register central source
        $this->sources[] = new CentralMediaSource($this->centralRepository);

        // Register custom sources
        foreach ($this->sourceManager->getRegisteredModels() as $modelClass) {
            $fields = $this->sourceManager->getModelFields($modelClass);
            if (!empty($fields)) {
                $this->sources[] = new CustomMediaSource($modelClass, $fields);
            }
        }
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->getFilteredFiles([], $perPage);
    }

    /**
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // Enforce a single source to prevent memory exhaustion (Option B from audit).
        // Merging multiple tables in memory is unscalable. 
        // We default to 'central' if 'all' or no source is provided.
        $sourceId = $filters['source'] ?? $filters['model_type'] ?? 'central';
        if ($sourceId === 'all') {
            $sourceId = 'central';
        }

        $targetSource = null;
        foreach ($this->sources as $source) {
            if ($source->getSourceId() === $sourceId) {
                $targetSource = $source;
                break;
            }
        }

        if (!$targetSource) {
            // Fallback to central source for polymorphic model types in file_uploads
            $targetSource = $this->sources[0]; 
            $filters['model_type'] = $sourceId;
        }

        return $targetSource->getFilteredFiles($filters, $perPage);
    }

    public function delete(string $path, bool $force = false): bool
    {
        $deleted = false;
        
        // Iterate through ALL sources to ensure we clean up ghost records (overlaps)
        foreach ($this->sources as $source) {
            if ($source->delete($path, $force)) {
                $deleted = true;
            }
        }

        return $deleted;
    }

    public function restore(string $path): bool
    {
        foreach ($this->sources as $source) {
            if ($source->restore($path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getOrphanedFiles(int $perPage = 20): LengthAwarePaginator
    {
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $offset = ($page - 1) * $perPage;

        // Build used paths map (memory efficient using cursor)
        $usedPathsMap = [];
        foreach ($this->sources as $source) {
            foreach ($source->getAllUsedPaths() as $path) {
                $usedPathsMap[$path] = true;
            }
        }

        $disks = config('filesystems.disks', []);
        $uploadPath = config('media-vault.storage.path', 'uploads');
        $orphans = [];
        $currentIndex = 0;
        
        $totalCount = \Illuminate\Support\Facades\Cache::get('media-vault:orphans_count');
        
        $scannedCount = 0;
        $maxScanLimit = config('media-vault.max_orphan_scan_limit', 100000);

        foreach (array_keys($disks) as $disk) {
            try {
                /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
                $diskStorage = Storage::disk((string) $disk);
                $driver = $diskStorage->getDriver();
                $listing = $driver->listContents($uploadPath, true);
                
                foreach ($listing as $item) {
                    if ($scannedCount >= $maxScanLimit) break 2;
                    $scannedCount++;

                    if ($item->type() !== 'file') continue;
                    
                    $file = $item->path();
                    if (str_starts_with(basename($file), '.')) continue;

                    if (!isset($usedPathsMap[$file])) {
                        if ($currentIndex >= $offset && $currentIndex < $offset + $perPage) {
                            $orphans[] = ['path' => $file, 'disk' => $disk];
                        }
                        $currentIndex++;
                        
                        if (count($orphans) >= $perPage && $totalCount !== null) {
                            // If we already know the total count, we can stop scanning early!
                            break 2;
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failed to scan disk [{$disk}] for orphaned files. Error: " . $e->getMessage());
            }
        }

        if ($totalCount === null) {
            $totalCount = $currentIndex;
            \Illuminate\Support\Facades\Cache::put('media-vault:orphans_count', $totalCount, 3600);
        }

        $orphansDto = [];
        foreach ($orphans as $item) {
            $file = $item['path'];
            $disk = $item['disk'];
            $size = 0;
            $mime = 'application/octet-stream';
            $lastModified = now()->toIso8601String();
            
            $ext = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) {
                $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                $mime = 'video/' . $ext;
            } elseif (in_array($ext, ['pdf'])) {
                $mime = 'application/pdf';
            }
            
            /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
            $storageDisk = Storage::disk($disk);

            $orphansDto[] = new FileDto(
                path: $file,
                name: basename($file),
                mimeType: $mime,
                size: (int) $size,
                lastModified: $lastModified,
                isUsed: false,
                url: $storageDisk->url($file),
                disk: $disk
            );
        }

        return new LengthAwarePaginator(
            $orphansDto,
            $totalCount,
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
    }

    /**
     * @return array<string|int, array<int, mixed>>
     */
    public function getDuplicateFiles(): array
    {
        // Custom sources don't support duplicates easily due to distributed architecture.
        if (count($this->sources) > 1) {
            \Illuminate\Support\Facades\Log::notice('Duplicate detection across multiple sources is not supported yet.');
            return [];
        }

        foreach ($this->sources as $source) {
            if ($source->getSourceId() === 'central') {
                return ($source instanceof CentralMediaSource) 
                    ? $this->centralRepository->getDuplicateFiles()
                    : [];
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        $aggregated = [
            'total_files' => 0,
            'total_size' => 0,
            'used_files' => 0,
            'unused_files' => 0, // In multi-source we'd need to count orphaned files separately
            'images' => 0,
            'videos' => 0,
            'documents' => 0,
            'other' => 0,
        ];

        foreach ($this->sources as $source) {
            if (!empty($filters['source']) && $filters['source'] !== 'all' && $source->getSourceId() !== $filters['source']) {
                continue;
            }

            $stats = $source->getStats($filters);
            foreach ($aggregated as $key => $value) {
                $aggregated[$key] += $stats[$key] ?? 0;
            }
        }

        return $aggregated;
    }
}
