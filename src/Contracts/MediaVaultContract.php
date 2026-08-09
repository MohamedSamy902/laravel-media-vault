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
     * Delete a file or multiple files by ID, path, or array of IDs/paths.
     *
     * @param  int|string|array<int,int|string>  $idOrPath
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    public function delete(int|string|array $idOrPath): array;
}
