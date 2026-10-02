<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Memory-safe hashing helpers for duplicate detection.
 */
final class StreamHash
{
    public static function md5(string $disk, string $path): string
    {
        /** @var Filesystem&\Illuminate\Filesystem\FilesystemAdapter $filesystem */
        $filesystem = Storage::disk($disk);

        try {
            $local = $filesystem->path($path);
            if (is_string($local) && is_file($local)) {
                $hash = hash_file('md5', $local);
                if (is_string($hash) && $hash !== '') {
                    return $hash;
                }
            }
        } catch (\Throwable) {
            // Remote disks may not expose a local path.
        }

        $stream = $filesystem->readStream($path);
        if (!is_resource($stream)) {
            return md5($path);
        }

        try {
            $ctx = hash_init('md5');
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }

            return hash_final($ctx);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
