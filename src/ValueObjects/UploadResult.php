<?php

namespace MohamedSamy902\LaravelMediaVault\ValueObjects;

use ArrayAccess;
use JsonSerializable;

/**
 * Immutable value object returned by every successful upload() call.
 *
 * Implements ArrayAccess for backward compatibility so existing code
 * that uses $result['path'] continues to work without changes.
 *
 * @implements ArrayAccess<string, mixed>
 */
readonly class UploadResult implements ArrayAccess, JsonSerializable
{
    /**
     * @param array<string, string> $thumbnailUrls
     */
    public function __construct(
        public bool    $status,
        public string  $originalName,
        public string  $path,
        public string  $url,
        public string  $mimeType,
        public string  $type,
        public ?int    $size,
        public array   $thumbnailUrls = [],
        public ?int    $id            = null,
        public string  $disk          = 'public',
    ) {}

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /**
     * Convert to plain array — use this for backward-compatible array access.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status'         => $this->status,
            'original_name'  => $this->originalName,
            'path'           => $this->path,
            'url'            => $this->url,
            'mime_type'      => $this->mimeType,
            'type'           => $this->type,
            'size'           => $this->size,
            'thumbnail_urls' => $this->thumbnailUrls,
            'id'             => $this->id,
            'database_id'    => $this->id,
            'disk'           => $this->disk,
        ];
    }

    /**
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------------------
    // JsonSerializable
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -------------------------------------------------------------------------
    // ArrayAccess — backward compatibility layer
    // -------------------------------------------------------------------------

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    /** @codeCoverageIgnore */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Value object is immutable — silently ignore writes.
    }

    /** @codeCoverageIgnore */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        // Value object is immutable — silently ignore unsets.
    }

    public function __get(string $name): mixed
    {
        if ($name === 'databaseId') {
            return $this->id;
        }

        $trace = debug_backtrace();
        trigger_error(
            'Undefined property via __get(): ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE
        );
        return null;
    }
}
