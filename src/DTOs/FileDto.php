<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;

/**
 * @implements Arrayable<string, mixed>
 */
class FileDto implements Arrayable, Jsonable
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $path,
        public string $name,
        public ?string $mimeType,
        public ?int $size,
        public string $lastModified,
        public bool $isUsed = true,
        public ?string $url = null,
        public ?string $disk = null,
        public ?string $encoded_path = null,
        public bool $disk_exists = true,
        public bool $is_missing = false,
        public bool $isTrashed = false,
        public ?string $deletedAt = null,
        public ?array $metadata = null,
        public ?int $model_id = null,
        public ?bool $owner_exists = null,
    ) {
        $this->encoded_path = base64_encode($path);

        if (!$this->isTrashed && TrashPath::isTrashed($path)) {
            $this->isTrashed = true;
        }
    }

    public function trashed(): bool
    {
        return $this->isTrashed || TrashPath::isTrashed($this->path);
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size ?? 0;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 4) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'human_size' => $this->getHumanSizeAttribute(),
            'type' => $this->resolveType(),
            'original_name' => $this->name,
            'is_used' => $this->isUsed,
            'model_id' => $this->model_id,
            'owner_exists' => $this->owner_exists,
            'metadata' => $this->metadata ?? [],
            'deleted_at' => $this->deletedAt,
            default => null,
        };
    }

    private function resolveType(): string
    {
        if (str_starts_with((string) $this->mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with((string) $this->mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with((string) $this->mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_contains((string) $this->mimeType, 'pdf') || str_contains((string) $this->mimeType, 'word')) {
            return 'document';
        }

        return 'other';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'last_modified' => $this->lastModified,
            'is_used' => $this->isUsed,
            'url' => $this->url,
            'disk' => $this->disk,
            'is_trashed' => $this->trashed(),
            'deleted_at' => $this->deletedAt,
            'disk_exists' => $this->disk_exists,
            'is_missing' => $this->is_missing,
            'metadata' => $this->metadata,
        ];
    }

    public function toJson($options = 0): string
    {
        return (string) json_encode($this->toArray(), $options);
    }
}
