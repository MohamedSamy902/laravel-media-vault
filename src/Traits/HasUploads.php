<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

/**
 * Trait HasUploads
 *
 * Add this trait to any Eloquent model that owns uploaded files.
 * Provides relationships and helper methods for retrieving media.
 */
trait HasUploads
{
    /**
     * Polymorphic relationship to the MediaVault model.
     */
    public function uploads(): MorphMany
    {
        $model = config('media-vault.database.model', FileUpload::class);
        return $this->morphMany($model, 'model');
    }

    /**
     * Get the URL for a specific media type and size.
     *
     * By default, it retrieves the original size of the latest 'image'.
     * If a specific size is requested and the thumbnail exists in the metadata,
     * it returns the thumbnail URL instead, falling back to the original URL if not found.
     *
     * @param string $type The file type (image, video, document, etc.)
     * @param string $size The thumbnail size name (e.g. 'small', 'medium'), or 'original'
     * @return string|null
     */
    public function getMediaUrl(string $type = 'image', string $size = 'original'): ?string
    {
        $upload = $this->relationLoaded('uploads')
            ? $this->uploads->where('type', $type)->sortByDesc('id')->first()
            : $this->uploads()->where('type', $type)->latest()->first();

        if (!$upload) {
            return null;
        }

        if ($size === 'original') {
            return $upload->url;
        }

        // Try to fetch from JSON metadata stored during generation
        $thumbnails = $upload->metadata['thumbnails'] ?? [];
        return $thumbnails[$size] ?? $upload->url;
    }

    /**
     * Get all available thumbnail URLs for the latest image.
     *
     * @return array<string, string> Map of size names to their URLs.
     */
    public function getThumbnails(): array
    {
        $upload = $this->uploads()->images()->latest()->first();
        if (!$upload) {
            return [];
        }

        return $upload->metadata['thumbnails'] ?? [];
    }
}
