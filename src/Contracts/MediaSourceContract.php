<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaSourceContract
{
    /**
     * Get the source identifier.
     *
     * @return string
     */
    public function getSourceId(): string;

    /**
     * Get a paginated list of all files in this source based on filters.
     *
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @return LengthAwarePaginator<int, mixed>
     */
    public function getFilteredFiles(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Get all paths used in this source (for checking orphaned files).
     *
     * @return \Generator<string>|array<string>
     */
    public function getAllUsedPaths(): iterable;

    /**
     * Delete a file path from this source.
     *
     * @param string $path
     * @param bool $force
     * @return bool
     */
    public function delete(string $path, bool $force = false): bool;

    /**
     * Restore a previously soft-deleted file path from this source.
     *
     * @param string $path
     * @return bool
     */
    public function restore(string $path): bool;

    /**
     * Get source statistics.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array;
}
