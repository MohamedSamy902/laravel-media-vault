<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class FileCompressionTest extends TestCase
{
    private MediaVaultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = $this->app->make(MediaVaultService::class);
    }

    /** @test */
    public function image_compression_reduces_file_size(): void
    {
        $this->app['config']->set('media-vault.compression', [
            'enabled' => true,
            'level'   => 30,
            'types'   => ['image'],
        ]);

        // Create a large 1000x1000 image
        $file = UploadedFile::fake()->image('hd_photo.jpg', 1000, 1000);
        $originalSize = $file->getSize();

        $result = $this->service->upload($file);

        $this->assertTrue($result->status);
        Storage::disk('public')->assertExists($result->path);

        $compressedSize = Storage::disk('public')->size($result->path);

        // Compressed file size must be less than or equal to original size
        $this->assertLessThan($originalSize, $compressedSize, 'Compressed image size must be smaller than original image size.');
    }

    /** @test */
    public function compressed_image_remains_valid_and_readable(): void
    {
        $this->app['config']->set('media-vault.compression', [
            'enabled' => true,
            'level'   => 50,
            'types'   => ['image'],
        ]);

        $file = UploadedFile::fake()->image('valid_sample.png', 500, 500);
        $result = $this->service->upload($file);

        $this->assertTrue($result->status);

        $fullPath = Storage::disk('public')->path($result->path);
        $dimensions = getimagesize($fullPath);

        $this->assertNotFalse($dimensions, 'Compressed file must be a valid readable image.');
        $this->assertGreaterThan(0, $dimensions[0]);
        $this->assertGreaterThan(0, $dimensions[1]);
    }
}
