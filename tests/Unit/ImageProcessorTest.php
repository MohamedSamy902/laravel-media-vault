<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Services\ImageProcessor;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Exception;

/**
 * Tests for ImageProcessor using Intervention/Image v3.
 *
 * These tests require the GD extension to be available.
 * They are skipped automatically if GD is not installed.
 */
class ImageProcessorTest extends TestCase
{
    private ImageProcessor $processor;
    private string $testImagePath;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for ImageProcessor tests.');
        }

        $manager         = new ImageManager(new GdDriver());
        $this->processor = new ImageProcessor($manager);

        // Create a real test image in memory and write to temp file
        $this->testImagePath = sys_get_temp_dir() . '/test-image-' . uniqid() . '.jpg';
        $this->createTestJpeg($this->testImagePath, 800, 600);
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testImagePath)) {
            unlink($this->testImagePath);
        }
    }

    // =========================================================================
    // process() — basic encode
    // =========================================================================

    public function test_process_returns_binary_string(): void
    {
        $output = $this->processor->process($this->testImagePath, [], []);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function test_process_converts_to_webp(): void
    {
        $output = $this->processor->process($this->testImagePath, [
            'convert_to' => 'webp',
            'quality'    => 80,
        ], []);

        // WebP magic bytes: RIFF....WEBP
        $this->assertStringContainsString('WEBP', substr($output, 0, 16));
    }

    public function test_process_converts_to_jpeg(): void
    {
        $output = $this->processor->process($this->testImagePath, [
            'convert_to' => 'jpg',
            'quality'    => 85,
        ], []);

        // JPEG magic bytes: FF D8 FF
        $this->assertEquals("\xFF\xD8\xFF", substr($output, 0, 3));
    }

    public function test_process_converts_to_png(): void
    {
        $output = $this->processor->process($this->testImagePath, [
            'convert_to' => 'png',
        ], []);

        // PNG magic bytes: 89 50 4E 47
        $this->assertEquals("\x89PNG", substr($output, 0, 4));
    }

    // =========================================================================
    // process() — resize
    // =========================================================================

    public function test_process_downscales_with_aspect_ratio(): void
    {
        $output = $this->processor->process($this->testImagePath, [
            'resize' => [
                'width'                 => 400,
                'height'                => 400,
                'maintain_aspect_ratio' => true,
                'upsize'                => false,
            ],
            'convert_to' => 'jpg',
        ], []);

        // Read back and check dimensions — should be at most 400x400
        $manager   = new ImageManager(new GdDriver());
        $img       = $manager->read($output);
        $this->assertLessThanOrEqual(400, $img->width());
        $this->assertLessThanOrEqual(400, $img->height());
    }

    public function test_process_prevents_upscaling_when_upsize_false(): void
    {
        // Image is 800x600 — requesting 1600x1200 with upsize=false should keep original size
        $output = $this->processor->process($this->testImagePath, [
            'resize' => [
                'width'                 => 1600,
                'height'                => 1200,
                'maintain_aspect_ratio' => true,
                'upsize'                => false,
            ],
            'convert_to' => 'jpg',
        ], []);

        $manager = new ImageManager(new GdDriver());
        $img     = $manager->read($output);
        $this->assertLessThanOrEqual(800, $img->width());
        $this->assertLessThanOrEqual(600, $img->height());
    }

    public function test_options_override_config_convert_to(): void
    {
        // Config says webp, options say jpg — options win
        $output = $this->processor->process($this->testImagePath, [
            'convert_to' => 'webp',
        ], [
            'convert_to' => 'jpg',
        ]);

        $this->assertEquals("\xFF\xD8\xFF", substr($output, 0, 3));
    }

    // =========================================================================
    // thumbnail() — cover (crop)
    // =========================================================================

    public function test_thumbnail_cover_produces_exact_dimensions(): void
    {
        $output = $this->processor->thumbnail($this->testImagePath, 150, 150, true);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);

        $manager = new ImageManager(new GdDriver());
        $img     = $manager->read($output);
        $this->assertEquals(150, $img->width());
        $this->assertEquals(150, $img->height());
    }

    public function test_thumbnail_scale_preserves_aspect_ratio(): void
    {
        // Source: 800x600 → scale to max 300 wide
        $output = $this->processor->thumbnail($this->testImagePath, 300, null, false);

        $manager = new ImageManager(new GdDriver());
        $img     = $manager->read($output);

        $this->assertEquals(300, $img->width());
        // Height should be proportional: 600 * (300/800) = 225
        $this->assertEquals(225, $img->height());
    }

    public function test_thumbnail_prevents_upscaling(): void
    {
        // Source: 800x600 → request 2000 wide → should stay at 800
        $output = $this->processor->thumbnail($this->testImagePath, 2000, null, false);

        $manager = new ImageManager(new GdDriver());
        $img     = $manager->read($output);

        $this->assertLessThanOrEqual(800, $img->width());
    }

    // =========================================================================
    // fromConfig() factory
    // =========================================================================

    public function test_from_config_creates_gd_processor(): void
    {
        $this->app['config']->set('media-vault.image_driver', 'gd');
        $processor = ImageProcessor::fromConfig();

        $this->assertInstanceOf(ImageProcessor::class, $processor);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createTestJpeg(string $path, int $width, int $height): void
    {
        $manager = new ImageManager(new GdDriver());
        $image   = $manager->create($width, $height)->fill('8b5cf6');
        file_put_contents($path, $image->toJpeg(90)->toString());
    }
}
