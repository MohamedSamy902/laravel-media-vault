<?php

namespace MohamedSamy902\LaravelMediaVault\Contracts;

use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;

interface MediaVaultContract
{
    /**
     * Upload a file or multiple files from a Request, direct UploadedFile, URL, or array.
     *
     * @param  mixed  $source
     * @param  array<string, mixed>  $options
     * @return UploadResult|array<string, mixed>|array<int, UploadResult|array<string, mixed>>
     */
    public function upload(mixed $source, array $options = []): UploadResult|array;

    /**
     * Upload one or more files from a remote URL.
     *
     * @param string|array<string> $url
     * @param array<string, mixed> $options
     * @return UploadResult|array<mixed>
     */
    public function uploadFromUrl(string|array $url, array $options = []): UploadResult|array;

    /**
     * Soft-deletes (moves to trash) a file or a batch of files.
     * Physical bytes are preserved under .trash/ and can be restored.
     *
     * @param int|string|array<int, int|string> $idOrPath
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function trash(int|string|array $idOrPath): array;

    /**
     * Restores a soft-deleted file (or batch) from trash.
     *
     * @param int|string|array<int, int|string> $idOrPath
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function restore(int|string|array $idOrPath): array;

    /**
     * Permanently deletes a file or batch from database and storage.
     *
     * @param int|string|array<int, int|string> $idOrPath
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function forceDelete(int|string|array $idOrPath): array;

    /**
     * Permanently deletes a file or multiple files by ID, path, or array of IDs/paths.
     *
     * Alias of forceDelete() for backward compatibility.
     *
     * @param  int|string|array<int,int|string>  $idOrPath
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    public function delete(int|string|array $idOrPath): array;
}
