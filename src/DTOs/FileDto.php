<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * @implements Arrayable<string, mixed>
 */
class FileDto implements Arrayable, Jsonable
{
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
        public bool $is_missing = false
    ) {
        $this->encoded_path = base64_encode($path);
    }

    public function trashed(): bool
    {
        return str_contains($this->path, '.trash/');
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
        if ($name === 'human_size') {
            return $this->getHumanSizeAttribute();
        }
        if ($name === 'type') {
            if (str_starts_with((string)$this->mimeType, 'image/')) return 'image';
            if (str_starts_with((string)$this->mimeType, 'video/')) return 'video';
            if (str_starts_with((string)$this->mimeType, 'audio/')) return 'audio';
            if (str_contains((string)$this->mimeType, 'pdf') || str_contains((string)$this->mimeType, 'word')) return 'document';
            return 'other';
        }
        if ($name === 'original_name') {
            return $this->name;
        }
        if ($name === 'is_used') {
            return $this->isUsed;
        }
        if ($name === 'model_id' || $name === 'owner_exists') {
            return null; // DB mode provides these, Disk mode defaults to null
        }
        return null;
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
        ];
    }

    public function toJson($options = 0): string
    {
        return (string) json_encode($this->toArray(), $options);
    }
}
