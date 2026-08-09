<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

class FileUsageScanner
{
    public function __construct(protected MediaSourceManager $sourceManager)
    {
    }

    /**
     * Get a flattened array of all file paths used across the entire database.
     * Memory safe (uses DB::cursor).
     *
     * @return \Generator<string>
     */
    public function getAllUsedPaths(): \Generator
    {
        // For backwards compatibility, yield paths from CentralMediaSource if it was used
        if (config('media-vault.database.enabled', true)) {
            $central = new \MohamedSamy902\LaravelMediaVault\Sources\CentralMediaSource(
                app(\MohamedSamy902\LaravelMediaVault\Repositories\DatabaseFileRepository::class)
            );
            foreach ($central->getAllUsedPaths() as $path) {
                yield $path;
            }
        }

        // Fetch custom models using HasMediaFields via MediaSourceManager
        $models = $this->sourceManager->getRegisteredModels();

        foreach ($models as $class) {
            $fields = $this->sourceManager->getModelFields($class);
            if (empty($fields)) {
                continue;
            }

            $source = new \MohamedSamy902\LaravelMediaVault\Sources\CustomMediaSource($class, $fields);
            
            foreach ($source->getAllUsedPaths() as $path) {
                yield $path;
            }
        }
    }
}
