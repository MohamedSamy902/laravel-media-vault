<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface FileRepositoryContract
{
    /**
     * Get a paginated list of all files.
     *
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator;

    /**
     * Get a paginated list of files based on filters.
     *
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Delete a file from the repository.
     *
     * @param string $path
     * @param bool $force If true, permanently deletes the file. Otherwise moves it to trash.
     * @return bool
     */
    public function delete(string $path, bool $force = false): bool;

    /**
     * Restore a previously deleted file (from trash).
     *
     * @param string $path
     * @return bool
     */
    public function restore(string $path): bool;

    /**
     * Get a paginated list of files that are on disk but not used in the database.
     *
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function getOrphanedFiles(int $perPage = 20): LengthAwarePaginator;

    /**
     * Get duplicate files grouped by their content hash or attributes.
     *
     * @return array<string|int, array<int, mixed>>
     */
    public function getDuplicateFiles(): array;

    /**
     * Get repository statistics for the dashboard.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array;
}
