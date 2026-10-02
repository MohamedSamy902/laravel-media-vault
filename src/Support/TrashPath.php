<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

/**
 * Converts between active storage paths and .trash/ counterparts.
 *
 * Example:
 *   uploads/default/photo.webp
 *     → uploads/.trash/default/photo.webp
 */
final class TrashPath
{
    public static function storageBase(): string
    {
        return trim((string) config('media-vault.storage.path', 'uploads'), '/');
    }

    public static function isTrashed(string $path): bool
    {
        $normalized = self::normalize($path);
        $base = self::storageBase();

        if ($base !== '' && str_starts_with($normalized, $base . '/.trash/')) {
            return true;
        }

        return str_contains($normalized, '/.trash/') || str_starts_with($normalized, '.trash/');
    }

    public static function toTrash(string $path): string
    {
        $path = self::normalize($path);

        if (self::isTrashed($path)) {
            return $path;
        }

        $base = self::storageBase();

        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $relative = $path === $base ? '' : substr($path, strlen($base) + 1);

            return $relative === ''
                ? $base . '/.trash'
                : $base . '/.trash/' . $relative;
        }

        return ($base !== '' ? $base . '/' : '') . '.trash/' . $path;
    }

    public static function fromTrash(string $path): string
    {
        $path = self::normalize($path);

        if (!self::isTrashed($path)) {
            return $path;
        }

        $base = self::storageBase();
        $prefix = $base !== '' ? $base . '/.trash/' : '.trash/';

        if (str_starts_with($path, $prefix)) {
            $relative = substr($path, strlen($prefix));

            return $base !== '' ? $base . '/' . $relative : $relative;
        }

        return (string) preg_replace('#/?\.trash/#', '/', $path, 1);
    }

    /**
     * Resolves the on-disk path for a logical (DB) path.
     */
    public static function physical(string $logicalPath, bool $trashed): string
    {
        $logicalPath = self::normalize($logicalPath);

        if ($trashed) {
            return self::isTrashed($logicalPath) ? $logicalPath : self::toTrash($logicalPath);
        }

        return self::isTrashed($logicalPath) ? self::fromTrash($logicalPath) : $logicalPath;
    }

    public static function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
