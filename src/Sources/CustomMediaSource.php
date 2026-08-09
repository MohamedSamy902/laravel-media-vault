<?php

    declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Sources;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaSourceContract;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;

class CustomMediaSource implements MediaSourceContract
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param array<string, array<string, mixed>> $fieldsDefinition
     */
    public function __construct(
        protected string $modelClass,
        protected array $fieldsDefinition
    ) {}

    public function getSourceId(): string
    {
        return $this->modelClass;
    }

    /**
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->modelClass::query();
        
        // DB-level filtering
        $query->where(function ($q) use ($filters) {
            $search = $filters['search'] ?? null;
            $escapedSearch = $search ? str_replace('/', '\\/', (string) $search) : null;
            
            foreach ($this->fieldsDefinition as $field => $config) {
                $q->orWhere(function ($subQ) use ($field, $search, $escapedSearch) {
                    $subQ->whereNotNull($field)->where($field, '!=', '');
                    if ($search) {
                        $subQ->where(function($qq) use ($field, $search, $escapedSearch) {
                            $qq->where($field, 'LIKE', '%' . $search . '%')
                               ->orWhere($field, 'LIKE', '%' . $escapedSearch . '%');
                        });
                    }
                });
            }
        });

        // Technical Limitation: Since multiple paths are stored in a single JSON column,
        // we cannot easily DB-paginate by INDIVIDUAL FILE across all DB engines without
        // complex DB-specific JSON unnesting (like JSON_TABLE).
        // To prevent OOM/CPU timeout on massive tables, we MUST paginate by DB ROW.
        // This means a page of "20" might return more than 20 files if a row contains a JSON array.
        // This is a documented trade-off for v1.0.
        $paginator = $query->paginate($perPage);
        
        $allFiles = collect();
        foreach ($paginator->items() as $model) {
            foreach ($this->fieldsDefinition as $field => $config) {
                $path = $model->getAttribute($field);
                if (!$path) continue;

                $disk = $config['disk'] ?? 'public';
                if (!empty($filters['disk']) && $filters['disk'] !== 'all' && $filters['disk'] !== $disk) {
                    continue;
                }
                
                if (!empty($filters['search']) && stripos((string) $path, (string) $filters['search']) === false) {
                    continue;
                }

                $isMultiple = $config['multiple'] ?? false;
                $paths = $isMultiple ? (is_string($path) ? json_decode($path, true) : $path) : [$path];

                if (!is_array($paths)) continue;

                foreach ($paths as $p) {
                    $allFiles->push($this->createDto((string) $p, (string) $disk, $model));
                }
            }
        }

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $allFiles,
            $paginator->total(), // Total ROWS, not files.
            $perPage,
            $paginator->currentPage(),
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
    }

    public function getAllUsedPaths(): iterable
    {
        $query = $this->modelClass::query();
        
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($this->modelClass)) && method_exists($query, 'withTrashed')) {
            /** @var mixed $query */
            $query->withTrashed();
        }

        
        foreach ($query->cursor() as $model) {
            foreach ($this->fieldsDefinition as $field => $config) {
                $path = $model->getAttribute($field);
                if (!$path) continue;
                
                $isMultiple = $config['multiple'] ?? false;
                $paths = $isMultiple ? (is_string($path) ? json_decode($path, true) : $path) : [$path];

                if (!is_array($paths)) continue;

                foreach ($paths as $p) {
                    yield (string) $p;

                    // Data Loss Protection: Custom models do not store thumbnail paths.
                    // We must dynamically yield potential thumbnails based on config
                    // so they aren't incorrectly flagged as orphaned and deleted.
                    $ext = strtolower(pathinfo((string)$p, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) {
                        $thumbnailsConfig = config('media-vault.thumbnails.sizes', []);
                        if (!empty($thumbnailsConfig)) {
                            $dir = dirname((string) $p);
                            $dir = $dir === '.' ? '' : $dir . '/';
                            $fileName = basename((string) $p);
                            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
                            
                            foreach (array_keys($thumbnailsConfig) as $sizeName) {
                                yield "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
                            }
                        }
                    }
                }
            }
        }
    }

    public function delete(string $path, bool $force = false): bool
    {
        $query = $this->modelClass::query();
        $deleted = false;
        $diskToUse = null;

        // Optimize query by only looking for models that might contain this path
        $query->where(function ($q) use ($path) {
            $escapedPath = str_replace('/', '\\/', $path);
            foreach ($this->fieldsDefinition as $field => $config) {
                $isMultiple = $config['multiple'] ?? false;
                if ($isMultiple) {
                    $q->orWhere($field, 'LIKE', "%{$path}%")
                      ->orWhere($field, 'LIKE', "%{$escapedPath}%");
                } else {
                    $q->orWhere($field, $path);
                }
            }
        });

        foreach ($query->cursor() as $model) {
            foreach ($this->fieldsDefinition as $field => $config) {
                $value = $model->getAttribute($field);
                if (!$value) continue;

                $isMultiple = $config['multiple'] ?? false;
                if ($isMultiple) {
                    $paths = is_string($value) ? json_decode($value, true) : $value;
                    if (is_array($paths) && in_array($path, $paths)) {
                        $paths = array_values(array_diff($paths, [$path]));
                        $model->setAttribute($field, empty($paths) ? null : json_encode($paths));
                        $model->save();
                        
                        $diskToUse = $config['disk'] ?? 'public';
                        $deleted = true;
                    }
                } else {
                    if ($value === $path) {
                        $model->setAttribute($field, null);
                        $model->save();
                        
                        $diskToUse = $config['disk'] ?? 'public';
                        $deleted = true;
                    }
                }
            }
        }

        if ($deleted && $diskToUse && !$this->isPathUsedElsewhere($path)) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
            $diskStorage = Storage::disk((string) $diskToUse);
            if ($diskStorage->exists($path)) {
                $diskStorage->delete($path);
            }
            $this->deleteThumbnails((string) $diskToUse, $path);
        }

        return $deleted;
    }

    protected function isPathUsedElsewhere(string $path): bool
    {
        if (\MohamedSamy902\LaravelMediaVault\Models\FileUpload::withTrashed()->where('path', $path)->exists()) {
            return true;
        }

        $sourceManager = app(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class);
        foreach ($sourceManager->getRegisteredModels() as $modelClass) {
            $fields = $sourceManager->getModelFields($modelClass);
            $query = $modelClass::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass)) && method_exists($query, 'withTrashed')) {
                /** @var mixed $query */
                $query->withTrashed();
            }
            
            $query->where(function ($q) use ($path, $fields) {
                $escapedPath = str_replace('/', '\\/', $path);
                foreach ($fields as $field => $config) {
                    $q->orWhere($field, 'LIKE', "%{$path}%")
                      ->orWhere($field, 'LIKE', "%{$escapedPath}%");
                }
            });
            if ($query->exists()) {
                return true;
            }
        }
        
        return false;
    }

    protected function deleteThumbnails(string $disk, string $path): void
    {
        try {
            $dir = dirname($path);
            $dir = $dir === '.' ? '' : $dir . '/';
            $fileName = basename($path);
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            
            /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
            $diskStorage = Storage::disk($disk);
            $thumbnailsConfig = config('media-vault.thumbnails.sizes', []);
            foreach (array_keys($thumbnailsConfig) as $sizeName) {
                $thumbPath = "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
                if ($diskStorage->exists($thumbPath)) {
                    $diskStorage->delete($thumbPath);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to delete custom source thumbnails for [{$path}]. Error: " . $e->getMessage());
        }
    }

    public function restore(string $path): bool
    {
        // Custom sources don't easily support soft deleting images
        // We'd have to know which model it belonged to. 
        return false; 
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        $cacheKey = "media-vault:custom-stats:" . md5($this->modelClass . json_encode($filters));
        
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($filters) {
            $stats = [
                'total_files' => 0,
                'total_size' => 0,
                'used_files' => 0,
                'unused_files' => 0,
                'images' => 0,
                'videos' => 0,
                'documents' => 0,
                'other' => 0,
            ];

            foreach ($this->fieldsDefinition as $field => $config) {
                $disk = $config['disk'] ?? 'public';
                if (!empty($filters['disk']) && $filters['disk'] !== 'all' && $filters['disk'] !== $disk) {
                    continue;
                }
                
                $query = $this->modelClass::query()->whereNotNull($field)->where($field, '!=', '');
                
                if (!empty($filters['search'])) {
                    $query->where($field, 'LIKE', '%' . $filters['search'] . '%');
                }

                // We use the same fast aggregate queries even for multiple JSON fields
                // This is an approximation for total_files (assuming 1 file per row if it matches) 
                // but prevents OOM and CPU timeouts on large tables.
                // To prevent Full Table Scans (OOM/CPU Timeouts) on large tables,
                // we only count the total files and do not run multiple expensive LIKE queries
                // for file type categorization (images, videos, etc.) on Custom Sources.
                $count = (clone $query)->count();
                $stats['total_files'] += $count;
            }
            
            $stats['used_files'] = $stats['total_files'];
            $stats['other'] = $stats['total_files'];

            return $stats;
        });
    }

    protected function createDto(string $path, string $disk, mixed $model): FileDto
    {
        $size = 0;
        $mime = 'application/octet-stream';
        $lastModified = now()->toIso8601String();
        $isMissing = false;
        
        /** @var \Illuminate\Filesystem\FilesystemAdapter $diskStorage */
        $diskStorage = Storage::disk($disk);

        try {
            if ($diskStorage->exists($path)) {
                $size = $diskStorage->size($path);
                $mime = $diskStorage->mimeType($path);
                $lastModified = \Carbon\Carbon::createFromTimestamp($diskStorage->lastModified($path))->toIso8601String();
            } else {
                $isMissing = true;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("CustomMediaSource: Could not fetch file details for [{$path}] on disk [{$disk}]. Error: " . $e->getMessage());
            $isMissing = true;
        }

        $url = $diskStorage->url($path);
        $cdn = config('media-vault.storage.cdn', []);
        if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
            $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $url = rtrim((string) $cdn['url'], '/') . '/' . $relativePath;
        }

        return new FileDto(
            path: $path,
            name: basename($path),
            mimeType: $isMissing ? null : (string) $mime,
            size: $isMissing ? null : (int) $size,
            lastModified: $lastModified,
            isUsed: true, // Always used if it's in a custom source
            url: $url,
            disk: $disk,
            disk_exists: !$isMissing,
            is_missing: $isMissing
        );
    }
}
