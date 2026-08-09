<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Represents a stored file record in the database.
 *
 * @property int         $id
 * @property string      $original_name
 * @property string      $name            UUID-based filename on disk
 * @property string      $path            Full storage path (disk-relative)
 * @property string      $disk            Storage disk name
 * @property string      $mime_type
 * @property string      $type            image | video | audio | document | other
 * @property int|null    $size            File size in bytes
 * @property int|null    $user_id
 * @property string|null $model_type      Polymorphic owner type (optional linkage)
 * @property int|null    $model_id        Polymorphic owner ID (optional linkage)
 * @property bool        $is_used         Whether any model references this file
 * @property array|null  $metadata        JSON storage for extra info like thumbnail paths
 * @property string      $url             Public URL accessor
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder<static> unused()
 * @method static Builder<static> withTrashed()
 * @method static Builder<static> onlyTrashed()
 * @mixin \Illuminate\Database\Eloquent\Builder<static>
 */
class FileUpload extends Model
{
    use SoftDeletes;

    protected $table = 'file_uploads';

    protected $fillable = [
        'original_name',
        'name',
        'path',
        'disk',
        'mime_type',
        'type',
        'size',
        'user_id',
        'model_type',
        'model_id',
        'is_used',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size'     => 'integer',
        'user_id'  => 'integer',
        'model_id' => 'integer',
        'is_used'  => 'boolean',
        'metadata' => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the owning model for this file, if any.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this>
     */
    public function model(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Safely check if the owning model exists in the database.
     * Prevents "Class not found" errors if the model was renamed or deleted.
     */
    public function getOwnerExistsAttribute(): bool
    {
        if (empty($this->model_type) || empty($this->model_id)) {
            return false;
        }

        if (!class_exists($this->model_type)) {
            return false;
        }

        $modelClass = $this->model_type;
        $query = (new $modelClass)->newQuery();

        if (method_exists($modelClass, 'withTrashed')) {
            $query->withTrashed();
        }

        return $query->where((new $modelClass)->getKeyName(), $this->model_id)->exists();
    }

    /**
     * Returns the public URL for this file using its stored disk.
     */
    public function getUrlAttribute(): string
    {
        $cdn = config('media-vault.storage.cdn', []);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
        $storageDisk = Storage::disk($this->disk);
        $url = $storageDisk->url($this->path);

        if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
            $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            return rtrim((string) $cdn['url'], '/') . '/' . $relativePath;
        }

        return $url;
    }

    /**
     * Returns a human-readable file size string (e.g. "2.5 MB").
     */
    public function getHumanSizeAttribute(): string
    {
        return \Illuminate\Support\Number::fileSize((int) $this->size);
    }

    /**
     * Returns true when the physical file exists on the storage disk.
     */
    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    // -------------------------------------------------------------------------
    // Query Scopes
    // -------------------------------------------------------------------------

    /**
     * Filter to image files only.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('type', 'image');
    }

    /**
     * Filter to video files only.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('type', 'video');
    }

    /**
     * Filter to document files only.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeDocuments(Builder $query): Builder
    {
        return $query->where('type', 'document');
    }

    /**
     * Filter to files that are marked as used (linked to a model).
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('is_used', true);
    }

    /**
     * Filter to files that are NOT marked as used (orphaned in DB).
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_used', false)->orWhereNull('is_used');
        });
    }

    /**
     * Filter by file type.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Filter to files belonging to a specific user.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter to files stored on a specific disk.
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOnDisk(Builder $query, string $disk): Builder
    {
        return $query->where('disk', $disk);
    }
}