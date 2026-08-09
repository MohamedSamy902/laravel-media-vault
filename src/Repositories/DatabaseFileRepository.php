<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;

class DatabaseFileRepository implements FileRepositoryContract
{
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
        $query = FileUpload::query();

        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['disk']) && $filters['disk'] !== 'all') {
            $query->where('disk', $filters['disk']);
        }
        if (!empty($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }
        if (!empty($filters['filter']) && $filters['filter'] !== 'all' && !in_array($filters['filter'], ['images', 'documents', 'videos', 'audio'])) {
             if ($filters['filter'] === 'used') {
                 $query->where('is_used', true);
             } elseif ($filters['filter'] === 'unused') {
                 $query->where('is_used', false);
             } elseif ($filters['filter'] === 'deleted') {
                 $query = FileUpload::onlyTrashed();
             }
        } elseif (!empty($filters['filter']) && in_array($filters['filter'], ['images', 'documents', 'videos', 'audio'])) {
             $typeMap = ['images' => 'image', 'documents' => 'document', 'videos' => 'video', 'audio' => 'audio'];
             $query->where('type', $typeMap[$filters['filter']]);
        }

        $paginator = $query->latest()->paginate($perPage);
        
        $paginator->getCollection()->transform(function (FileUpload $file) {
            return $this->toDto($file);
        });

        /** @var LengthAwarePaginator<int, FileDto> $paginator */
        return $paginator;
    }

    public function delete(string $path, bool $force = false): bool
    {
        /** @var FileUpload|null $file */
        $file = FileUpload::withTrashed()->where('path', $path)->first();
        if (!$file) {
            return false;
        }

        if ($force) {
            // Delete physical file
            if (\Illuminate\Support\Facades\Storage::disk($file->disk)->exists($file->path)) {
                \Illuminate\Support\Facades\Storage::disk($file->disk)->delete($file->path);
            }

            // Delete thumbnails from metadata if available
            $metadata = is_array($file->metadata) ? $file->metadata : [];
            if (!empty($metadata['thumbnails']) && is_array($metadata['thumbnails'])) {
                foreach ($metadata['thumbnails'] as $thumbPath) {
                    if (is_string($thumbPath) && \Illuminate\Support\Facades\Storage::disk($file->disk)->exists($thumbPath)) {
                        \Illuminate\Support\Facades\Storage::disk($file->disk)->delete($thumbPath);
                    }
                }
            } else {
                // Fallback: use config sizes
                $dir = dirname($file->path);
                $dir = $dir === '.' ? '' : $dir . '/';
                $fileName = basename($file->path);
                $baseName = pathinfo($fileName, PATHINFO_FILENAME);
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                
                try {
                    $thumbnailsConfig = config('media-vault.thumbnails.sizes', []);
                    foreach (array_keys($thumbnailsConfig) as $sizeName) {
                        $thumbPath = "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
                        if (\Illuminate\Support\Facades\Storage::disk($file->disk)->exists($thumbPath)) {
                            \Illuminate\Support\Facades\Storage::disk($file->disk)->delete($thumbPath);
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to delete fallback thumbnails for [{$file->path}]. Error: " . $e->getMessage());
                }
            }

            return (bool) $file->forceDelete();
        }

        return (bool) $file->delete();
    }

    public function restore(string $path): bool
    {
        /** @var FileUpload|null $file */
        $file = FileUpload::withTrashed()->where('path', $path)->first();
        if ($file) {
            return (bool) $file->restore();
        }
        return false;
    }

    /**
     * @return LengthAwarePaginator<int, FileDto>
     */
    public function getOrphanedFiles(int $perPage = 20): LengthAwarePaginator
    {
        $paginator = FileUpload::where('is_used', false)->paginate($perPage);

        $paginator->getCollection()->transform(function (FileUpload $file) {
            return $this->toDto($file);
        });

        /** @var LengthAwarePaginator<int, FileDto> $paginator */
        return $paginator;
    }

    /**
     * @return array<string|int, array<int, FileDto>>
     */
    public function getDuplicateFiles(): array
    {
        // Simple duplicate grouping for Database Mode
        // Groups by original_name and size
        $duplicates = FileUpload::select('original_name', 'size')
            ->groupBy('original_name', 'size')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $result = [];
        foreach ($duplicates as $duplicate) {
            /** @var array<int, FileDto> $files */
            $files = FileUpload::where('original_name', $duplicate->original_name)
                ->where('size', $duplicate->size)
                ->get()
                ->map(fn(FileUpload $f) => $this->toDto($f))
                ->toArray();
            
            $result[] = $files;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        $query = FileUpload::query();

        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['disk']) && $filters['disk'] !== 'all') {
            $query->where('disk', $filters['disk']);
        }
        if (!empty($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }
        if (!empty($filters['filter']) && $filters['filter'] !== 'all' && !in_array($filters['filter'], ['images', 'documents', 'videos', 'audio'])) {
             if ($filters['filter'] === 'used') {
                 $query->where('is_used', true);
             } elseif ($filters['filter'] === 'unused') {
                 $query->where('is_used', false);
             }
        }

        return [
            'total_files' => (clone $query)->count(),
            'total_size' => (clone $query)->sum('size'),
            'used_files' => (clone $query)->where('is_used', true)->count(),
            'unused_files' => (clone $query)->where('is_used', false)->count(),
            'images' => (clone $query)->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->count(),
            'videos' => (clone $query)->whereIn('mime_type', ['video/mp4', 'video/quicktime'])->count(),
            'documents' => (clone $query)->whereIn('mime_type', ['application/pdf', 'application/msword'])->count(),
            'other' => (clone $query)->whereNotIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'application/pdf', 'application/msword'])->count(),
        ];
    }

    protected function toDto(FileUpload $file): FileDto
    {
        return new FileDto(
            path: $file->path,
            name: $file->name,
            mimeType: $file->mime_type,
            size: (int) $file->size,
            lastModified: $file->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            isUsed: (bool) $file->is_used,
            url: $file->url,
            disk: $file->disk
        );
    }
}
