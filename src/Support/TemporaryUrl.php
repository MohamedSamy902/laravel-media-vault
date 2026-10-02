<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Support;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Thin wrapper over Laravel temporary / signed URLs.
 *
 * Prefer the disk driver's temporaryUrl() when available (S3, etc.).
 * For local disks, fall back to a signed route when temp_url is enabled.
 */
final class TemporaryUrl
{
    /**
     * @param DateTimeInterface|DateInterval|int $expiration Seconds or absolute/relative expiration
     */
    public function for(
        string $path,
        DateTimeInterface|DateInterval|int $expiration = 60,
        ?string $disk = null,
    ): ?string {
        $diskName = $disk ?? (string) config('media-vault.storage.disk', 'public');
        $expiresAt = $this->resolveExpiration($expiration);

        try {
            return Storage::disk($diskName)->temporaryUrl($path, $expiresAt);
        } catch (Throwable) {
            // Driver does not support temporary URLs (e.g. local).
        }

        if (!(bool) config('media-vault.temp_url.enabled', true)) {
            return null;
        }

        $routeName = (string) config('media-vault.temp_url.route_name', 'media-vault.temp');

        if (!\Illuminate\Support\Facades\Route::has($routeName)) {
            return null;
        }

        return URL::temporarySignedRoute($routeName, $expiresAt, [
            'path' => base64_encode($path),
            'disk' => $diskName,
        ]);
    }

    private function resolveExpiration(DateTimeInterface|DateInterval|int $expiration): DateTimeInterface
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration;
        }

        if ($expiration instanceof DateInterval) {
            return now()->add($expiration);
        }

        return now()->addSeconds(max(1, $expiration));
    }
}
