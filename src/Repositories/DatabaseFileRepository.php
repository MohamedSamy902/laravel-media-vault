<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\DTOs\FileDto;
use MohamedSamy902\LaravelMediaVault\Events\FileDeletedEvent;
use MohamedSamy902\LaravelMediaVault\Events\FileRestoredEvent;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Services\TrashManager;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;

class DatabaseFileRepository implements FileRepositoryContract
{
    public function __construct(protected TrashManager $trashManager)
    {
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
        $filter = $filters['filter'] ?? 'all';
        $query = $filter === 'deleted'
            ? FileUpload::onlyTrashed()
            : FileUpload::query();

        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['disk']) && $filters['disk'] !== 'all') {
            $query->where('disk', $filters['disk']);
        }
        if (!empty($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }

        if ($filter !== 'all' && $filter !== 'deleted' && !in_array($filter, ['images', 'documents', 'videos', 'audio'], true)) {
            if ($filter === 'used') {
                $query->where('is_used', true);
            } elseif ($filter === 'unused') {
                $query->where('is_used', false);
            }
        } elseif (in_array($filter, ['images', 'documents', 'videos', 'audio'], true)) {
            $typeMap = ['images' => 'image', 'documents' => 'document', 'videos' => 'video', 'audio' => 'audio'];
            $query->where('type', $typeMap[$filter]);
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
        $logicalPath = TrashPath::isTrashed($path) ? TrashPath::fromTrash($path) : TrashPath::normalize($path);

        /** @var FileUpload|null $file */
        $file = FileUpload::withTrashed()
            ->where(function ($query) use ($logicalPath, $path) {
                $query->where('path', $logicalPath)->orWhere('path', $path);
            })
            ->first();

        if (!$file) {
            return false;
        }

        $disk = (string) ($file->disk ?: config('media-vault.storage.disk', 'public'));
        $logical = TrashPath::normalize((string) $file->path);
        $thumbnails = is_array($file->metadata['thumbnails'] ?? null)
            ? $file->metadata['thumbnails']
            : null;

        if ($force) {
            $this->trashManager->purge($disk, $logical, $thumbnails);
            $deleted = (bool) $file->forceDelete();
            if ($deleted) {
                event(new FileDeletedEvent($logical, true));
            }

            return $deleted;
        }

        // Soft delete: keep logical path in DB, move bytes into .trash/
        $this->trashManager->moveToTrash($disk, $logical, $thumbnails);

        if ($file->trashed()) {
            return true;
        }

        $deleted = (bool) $file->delete();
        if ($deleted) {
            event(new FileDeletedEvent($logical, false));
        }

        return $deleted;
    }

    public function restore(string $path): bool
    {
        $logicalPath = TrashPath::isTrashed($path) ? TrashPath::fromTrash($path) : TrashPath::normalize($path);

        /** @var FileUpload|null $file */
        $file = FileUpload::withTrashed()
            ->where(function ($query) use ($logicalPath, $path) {
                $query->where('path', $logicalPath)->orWhere('path', $path);
            })
            ->first();

        if (!$file || !$file->trashed()) {
            return false;
        }

        $disk = (string) ($file->disk ?: config('media-vault.storage.disk', 'public'));
        $logical = TrashPath::normalize((string) $file->path);
        $thumbnails = is_array($file->metadata['thumbnails'] ?? null)
            ? $file->metadata['thumbnails']
            : null;

        $this->trashManager->restoreFromTrash($disk, $logical, $thumbnails);

        $restored = (bool) $file->restore();
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
        // Unused active records only — never mix with soft-deleted trash.
        $paginator = FileUpload::query()->where('is_used', false)->paginate($perPage);

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
        $duplicates = FileUpload::query()
            ->select('original_name', 'size')
            ->groupBy('original_name', 'size')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $result = [];
        foreach ($duplicates as $duplicate) {
            /** @var array<int, FileDto> $files */
            $files = FileUpload::query()
                ->where('original_name', $duplicate->original_name)
                ->where('size', $duplicate->size)
                ->get()
                ->map(fn (FileUpload $f) => $this->toDto($f))
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
        if (!empty($filters['filter']) && $filters['filter'] !== 'all' && !in_array($filters['filter'], ['images', 'documents', 'videos', 'audio'], true)) {
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
            'trashed_files' => FileUpload::onlyTrashed()->count(),
            'images' => (clone $query)->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->count(),
            'videos' => (clone $query)->whereIn('mime_type', ['video/mp4', 'video/quicktime'])->count(),
            'documents' => (clone $query)->whereIn('mime_type', ['application/pdf', 'application/msword'])->count(),
            'other' => (clone $query)->whereNotIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'application/pdf', 'application/msword'])->count(),
        ];
    }

    protected function toDto(FileUpload $file): FileDto
    {
        $trashed = $file->trashed();
        $disk = (string) ($file->disk ?: config('media-vault.storage.disk', 'public'));
        $logicalPath = TrashPath::normalize((string) $file->path);
        $physicalPath = TrashPath::physical($logicalPath, $trashed);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        $exists = $storage->exists($physicalPath);

        $url = null;
        if ($exists) {
            $url = $storage->url($physicalPath);
            $cdn = config('media-vault.storage.cdn', []);
            if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
                $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                $url = rtrim((string) $cdn['url'], '/') . '/' . $relativePath;
            }
        }

        return new FileDto(
            path: $logicalPath,
            name: (string) ($file->original_name ?: $file->name),
            mimeType: $file->mime_type,
            size: (int) $file->size,
            lastModified: $file->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            isUsed: (bool) $file->is_used,
            url: $url,
            disk: $disk,
            disk_exists: $exists,
            is_missing: !$exists,
            isTrashed: $trashed,
            deletedAt: $file->deleted_at?->toIso8601String(),
            metadata: is_array($file->metadata) ? $file->metadata : null,
            model_id: $file->model_id,
            owner_exists: $file->owner_exists,
        );
    }
}
