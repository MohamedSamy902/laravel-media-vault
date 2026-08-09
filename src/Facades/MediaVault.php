<?php

namespace MohamedSamy902\LaravelMediaVault\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult|array upload(mixed $source, array $options = [])
 * @method static \MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult|array uploadFromUrl(string|array $url, array $options = [])
 * @method static array delete(int|string|array $idOrPath)
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