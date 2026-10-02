<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

/**
 * Shared naming helpers for package-generated thumbnail paths.
 */
final class ThumbnailPath
{
    /**
     * Build a conventional thumbnail path next to the original file.
     */
    public static function for(string $originalPath, string $sizeName): string
    {
        $dir = dirname($originalPath);
        $dir = $dir === '.' ? '' : $dir . '/';
        $fileName = basename($originalPath);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        return "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
    }

    /**
     * Whether a storage path looks like a generated thumbnail.
     */
    public static function isThumbnail(string $path): bool
    {
        return (bool) preg_match('#(^|/)thumb_[^/]+_[^/]+$#', $path);
    }

    /**
     * Yield thumbnail paths for every configured size.
     *
     * @return list<string>
     */
    public static function allFor(string $originalPath): array
    {
        $sizes = array_keys(config('media-vault.thumbnails.sizes', []));
        $paths = [];
        foreach ($sizes as $sizeName) {
            $paths[] = self::for($originalPath, (string) $sizeName);
        }

        return $paths;
    }
}
