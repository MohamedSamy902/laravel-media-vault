<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult|array upload(mixed $source, array $options = [])
 * @method static \MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult|array uploadFromUrl(string|array $url, array $options = [])
 * @method static array trash(int|string|array $idOrPath)
 * @method static array restore(int|string|array $idOrPath)
 * @method static array forceDelete(int|string|array $idOrPath)
 * @method static array delete(int|string|array $idOrPath)
 * @method static array markAsUsed(int|string|array $idOrPath, ?object $owner = null)
 * @method static array markAsUnused(int|string|array $idOrPath, bool $clearOwnership = true)
 * @method static string|null temporaryUrl(string $path, \DateTimeInterface|\DateInterval|int $expiration = 60, ?string $disk = null)
 *
 * @see \MohamedSamy902\LaravelMediaVault\Services\MediaVaultService
 */
class MediaVault extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return \MohamedSamy902\LaravelMediaVault\Services\MediaVaultService::class;
    }
}