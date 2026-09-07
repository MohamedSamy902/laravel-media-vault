<?php

namespace MohamedSamy902\LaravelMediaVault\Contracts;

interface ImageProcessorContract
{
    /**
     * Process an image file and return the encoded binary content.
     *
     * @param  string  $path      Absolute path to the source image
     * @param  array<string, mixed>   $config    Processing config (resize, watermark, filters, convert_to, quality)
     * @param  array<string, mixed>   $options   Per-request overrides (convert_to, quality)
     * @return string             Binary image content
     */
    public function process(string $path, array $config, array $options): string;

    /**
     * Generate a thumbnail and return its binary content.
     *
     * @param  string  $path    Absolute path to the source image
     * @param  int|null $width
     * @param  int|null $height
     * @param  bool    $crop    If true, use cover (crop to fill). If false, scale with aspect ratio.
     * @param  string|null $format  Optional output format (e.g. webp). Null keeps encoder default.
     * @param  int $quality
     * @return string           Binary image content
     */
    public function thumbnail(
        string $path,
        ?int $width,
        ?int $height,
        bool $crop,
        ?string $format = null,
        int $quality = 85,
    ): string;
}
