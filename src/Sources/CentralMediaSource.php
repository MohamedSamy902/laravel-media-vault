<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Sources;

use Illuminate\Pagination\LengthAwarePaginator;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaSourceContract;
use MohamedSamy902\LaravelMediaVault\Repositories\DatabaseFileRepository;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

class CentralMediaSource implements MediaSourceContract
{
    public function __construct(protected DatabaseFileRepository $repository)
    {
    }

    public function getSourceId(): string
    {
        return 'central';
    }

    /**
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getFilteredFiles($filters, $perPage);
    }

    public function getAllUsedPaths(): iterable
    {
        $query = FileUpload::query();

        foreach ($query->cursor() as $file) {
            if ($file->path) {
                yield $file->path;
            }

            $thumbnails = $file->metadata['thumbnails'] ?? [];
            if (is_array($thumbnails) && !empty($thumbnails)) {
                $dir = dirname((string) $file->path);
                $dir = $dir === '.' ? '' : $dir . '/';
                $fileName = basename((string) $file->path);

                foreach (array_keys($thumbnails) as $sizeName) {
                    yield "{$dir}thumb_{$sizeName}_{$fileName}";
                }
            }
        }
    }

    public function delete(string $path, bool $force = false): bool
    {
        return $this->repository->delete($path, $force);
    }

    public function restore(string $path): bool
    {
        return $this->repository->restore($path);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        return $this->repository->getStats($filters);
    }
}
