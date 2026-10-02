<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Invalidates short-lived disk listing / stats caches after mutations.
 */
final class DiskListingCache
{
    public static function forget(?string $disk = null, ?string $basePath = null): void
    {
        $disk ??= (string) config('media-vault.storage.disk', 'public');
        $basePath ??= (string) config('media-vault.storage.path', 'uploads');
        $key = 'media-vault:disk-listing:' . $disk . ':' . md5($basePath);

        Cache::forget($key);
        Cache::forget('media-vault:disk-stats');
        Cache::forget('media-vault:duplicates');
        Cache::forget('media-vault:orphans');
        Cache::forget('media-vault:orphans_count');
    }
}
