<?php

namespace MohamedSamy902\LaravelMediaVault\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Handles image processing using Intervention/Image v3.
 *
 * v3 API key changes from v2:
 *  - ImageManager::gd() / ImageManager::imagick() replaced by new ImageManager(new GdDriver())
 *  - $manager->read($path) replaces Image::make($path)
 *  - $img->scale() replaces $img->resize() with constraint callbacks
 *  - $img->cover(w, h) replaces $img->fit(w, h)
 *  - $img->place() replaces $img->insert()
 *  - $img->toWebp(quality) / toJpeg() replaces $img->encode('webp', quality)
 *  - $img->encode()->toString() for generic encoding
 */
final class ImageProcessor implements ImageProcessorContract
{
    public function __construct(
        private readonly ImageManager $manager,
    ) {}

    /**
     * Create an instance from the package config (respects image_driver setting).
     */
    public static function fromConfig(): self
    {
        $driverName = config('media-vault.image_driver', 'gd');

        $driver = $driverName === 'imagick'
            ? new ImagickDriver()
            : new GdDriver();

        return new self(new ImageManager($driver));
    }

    // -------------------------------------------------------------------------
    // ImageProcessorContract
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function process(string $path, array $config, array $options): string
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                throw new Exception("File is not accessible for image processing.");
            }
            $dimensions = getimagesize($path);
            if ($dimensions !== false && ($dimensions[0] > 8000 || $dimensions[1] > 8000)) {
                throw new Exception("Image dimensions exceed the maximum allowed limit of 8000x8000 pixels.");
            }

            $image = $this->manager->read($path);
            $image->core(); // No-op, in v3 EXIF is dropped on encode by default


            // 1. Resize
            $resize = $config['resize'] ?? [];
            if (!empty($resize['width']) || !empty($resize['height'])) {
                $width  = isset($resize['width'])  && $resize['width']  > 0 ? (int) $resize['width']  : null;
                $height = isset($resize['height']) && $resize['height'] > 0 ? (int) $resize['height'] : null;

                $maintainRatio = $resize['maintain_aspect_ratio'] ?? true;
                $preventUpsize = !($resize['upsize'] ?? false);

                if ($maintainRatio) {
                    // scale() preserves aspect ratio and optionally prevents upscaling
                    if ($preventUpsize) {
                        $image->scaleDown(width: $width, height: $height);
                    } else {
                        $image->scale(width: $width, height: $height);
                    }
                } else {
                    // resize() stretches to exact dimensions
                    if ($width && $height) {
                        $image->resize($width, $height);
                    } elseif ($width) {
                        $image->resize($width, $image->height());
                    } elseif ($height) {
                        $image->resize($image->width(), $height);
                    }
                }
            }

            // 2. Watermark
            $watermark = $config['watermark'] ?? [];
            if (!empty($watermark['enabled']) && !empty($watermark['path'])) {
                $watermarkPath = public_path($watermark['path']);
                if (file_exists($watermarkPath)) {
                    $xOffset  = (int) ($watermark['x_offset'] ?? 10);
                    $yOffset  = (int) ($watermark['y_offset'] ?? 10);
                    $position = $watermark['position'] ?? 'bottom-right';

                    $image->place($watermarkPath, $position, $xOffset, $yOffset);
                } else {
                    Log::warning("Watermark file not found: {$watermarkPath}");
                }
            }

            // 3. Filters
            $allowedFilters = ['brightness', 'contrast', 'greyscale', 'blur', 'sharpen', 'pixelate', 'flip', 'rotate'];
            foreach ($config['filters'] ?? [] as $filter => $value) {
                if (!in_array($filter, $allowedFilters, true)) {
                    Log::warning("Disallowed image filter skipped: {$filter}");
                    continue;
                }
                if (method_exists($image, $filter)) {
                    $image->$filter($value);
                }
            }

            // 4. Encode
            return $this->encode($image, $options['convert_to'] ?? $config['convert_to'] ?? null, (int) ($options['quality'] ?? $config['quality'] ?? 85));

        } catch (Exception $e) {
            Log::error("Image processing failed: " . $e->getMessage());
            throw new Exception('Image processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    #[\Override]
    public function thumbnail(
        string $path,
        ?int $width,
        ?int $height,
        bool $crop,
        ?string $format = null,
        int $quality = 85,
    ): string
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                throw new Exception("File is not accessible for thumbnail generation.");
            }
            $dimensions = getimagesize($path);
            if ($dimensions !== false && ($dimensions[0] > 8000 || $dimensions[1] > 8000)) {
                throw new Exception("Image dimensions exceed the maximum allowed limit of 8000x8000 pixels.");
            }

            $image = $this->manager->read($path);
            $image->core(); // No-op, in v3 EXIF is dropped on encode by default


            if ($crop && $width && $height) {
                // cover() = crop to fill exactly (was fit() in v2)
                $image->cover($width, $height);
            } elseif ($width || $height) {
                // scaleDown() = scale with aspect ratio, prevent upscaling
                $image->scaleDown(width: $width, height: $height);
            }

            return $this->encode($image, $format, $quality);

        } catch (Exception $e) {
            Log::error("Thumbnail generation failed: " . $e->getMessage());
            throw new Exception('Thumbnail generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Encode the image to the requested format using the v3 API.
     */
    private function encode(
        \Intervention\Image\Interfaces\ImageInterface $image,
        ?string $format,
        int $quality,
    ): string {
        return match (strtolower($format ?? '')) {
            'webp'        => $image->toWebp($quality)->toString(),
            'jpg', 'jpeg' => $image->toJpeg($quality)->toString(),
            'png'         => $image->toPng()->toString(),
            'gif'         => $image->toGif()->toString(),
            'avif'        => $image->toAvif($quality)->toString(),
            default       => $image->encode()->toString(),
        };
    }
}
