<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Traits;

use MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager;
use Illuminate\Support\Facades\Storage;

/**
 * Trait HasMediaFields
 *
 * Add this trait to any Eloquent model that has custom columns for storing media paths.
 */
trait HasMediaFields
{
    /**
     * Boot the trait to dynamically register the model.
     */
    protected static function bootHasMediaFields(): void
    {
        // Resolve the manager and register this class at runtime
        // This acts as a fallback for models not caught by the auto-discovery scan.
        app(MediaSourceManager::class)->registerModel(static::class);
    }

    /**
     * Get the definition of media fields for this model.
     *
     * @return array
     */
    public function getMediaFieldsDefinition(): array
    {
        return $this->mediaFields ?? [];
    }

    /**
     * Get the URL for a specific media field.
     *
     * @param string $field
     * @param string $size (optional, for future thumbnail support in custom fields)
     * @return string|null
     */
    public function getMediaUrl(string $field, string $size = 'original'): ?string
    {
        $path = $this->getAttribute($field);

        if (!$path) {
            return null;
        }

        // Handle JSON array fields if multiple => true
        $fieldsDef = $this->getMediaFieldsDefinition();
        $fieldConfig = $fieldsDef[$field] ?? [];
        $isMultiple = $fieldConfig['multiple'] ?? false;
        $disk = $fieldConfig['disk'] ?? config('media-vault.storage.disk', 'public');

        if ($isMultiple) {
            $paths = is_string($path) ? json_decode($path, true) : $path;
            if (!is_array($paths) || empty($paths)) {
                return null;
            }
            // Just return the first one's URL if they ask for a string, or maybe we should return array?
            // To be consistent with string return type, we return the first image URL.
            $path = $paths[0];
        }

        if ($size !== 'original') {
            $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) {
                $dir = dirname((string) $path);
                $dir = $dir === '.' ? '' : $dir . '/';
                $fileName = basename((string) $path);
                $baseName = pathinfo($fileName, PATHINFO_FILENAME);
                
                $thumbPath = "{$dir}thumb_{$size}_{$baseName}.{$ext}";
                
                // Optional: Check if the thumbnail actually exists, otherwise fallback to original.
                // We assume it exists to prevent disk I/O on every page load, similar to HasUploads metadata assumption.
                $path = $thumbPath;
            }
        }

        return Storage::disk($disk)->url($path);
    }
}
