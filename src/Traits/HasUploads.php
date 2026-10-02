<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
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
     * Link an existing upload record to this model and mark it as used.
     *
     * @param int|FileUpload|string $file  Record id, model instance, or storage path
     * @param array<string, mixed>  $attributes  Extra columns to persist on the upload row
     */
    public function attachUpload(int|FileUpload|string $file, array $attributes = []): FileUpload
    {
        $upload = $this->resolveUploadReference($file);

        if (!$this->exists) {
            throw new InvalidArgumentException('Save the model before attaching uploads.');
        }

        $upload->forceFill(array_merge([
            'model_type' => $this->getMorphClass(),
            'model_id'   => $this->getKey(),
            'is_used'    => true,
        ], $attributes))->save();

        return $upload->fresh();
    }

    /**
     * Alias for {@see attachUpload()}.
     */
    public function markUploadAsUsed(int|FileUpload|string $file, array $attributes = []): FileUpload
    {
        return $this->attachUpload($file, $attributes);
    }

    /**
     * Mark an upload as unused and optionally clear polymorphic ownership.
     */
    public function detachUpload(int|FileUpload|string $file, bool $clearOwnership = true): FileUpload
    {
        $upload = $this->resolveUploadReference($file);
        $this->assertUploadBelongsToModel($upload);

        $payload = ['is_used' => false];
        if ($clearOwnership) {
            $payload['model_type'] = null;
            $payload['model_id'] = null;
        }

        $upload->forceFill($payload)->save();

        return $upload->fresh();
    }

    /**
     * @param int|FileUpload|string $file
     */
    protected function resolveUploadReference(int|FileUpload|string $file): FileUpload
    {
        if ($file instanceof FileUpload) {
            return $file;
        }

        $modelClass = config('media-vault.database.model', FileUpload::class);
        /** @var class-string<FileUpload> $modelClass */

        if (is_int($file)) {
            $upload = $modelClass::query()->find($file);
            if (!$upload) {
                throw new InvalidArgumentException("Upload record [{$file}] was not found.");
            }

            return $upload;
        }

        $upload = $modelClass::query()->where('path', $file)->first();
        if (!$upload) {
            throw new InvalidArgumentException("Upload with path [{$file}] was not found.");
        }

        return $upload;
    }

    protected function assertUploadBelongsToModel(FileUpload $upload): void
    {
        if ($upload->model_type !== $this->getMorphClass() || (int) $upload->model_id !== (int) $this->getKey()) {
            throw new InvalidArgumentException('Upload is not attached to this model.');
        }
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
