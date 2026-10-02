<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Builds public media URLs with optional CDN rewriting.
 */
final class MediaUrl
{
    /**
     * @param array<string, mixed>|null $config
     */
    public static function for(string $disk, string $path, ?array $config = null): string
    {
        $config ??= config('media-vault');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $filesystem */
        $filesystem = Storage::disk($disk);
        $url = $filesystem->url($path);

        $cdn = $config['storage']['cdn'] ?? [];
        if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
            $relativePath = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $path), '/');

            return rtrim((string) $cdn['url'], '/') . '/' . $relativePath;
        }

        return $url;
    }

    /**
     * Trashed files should not be exposed via a stable public URL.
     *
     * @param array<string, mixed>|null $config
     */
    public static function forMaybeTrashed(string $disk, string $path, bool $isTrashed, ?array $config = null): string
    {
        if ($isTrashed) {
            return '';
        }

        return self::for($disk, $path, $config);
    }
}
