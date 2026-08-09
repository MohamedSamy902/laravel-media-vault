<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

class OrphanedThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
    
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.thumbnails.enabled', true);
        $app['config']->set('media-vault.thumbnails.sizes', ['small' => ['width' => 100, 'height' => 100]]);
        $app['config']->set('media-vault.storage.path', '');
    }
    
    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_orphaned_thumbnails_are_detected()
    {
        Storage::disk('public')->put('central/photo.jpg', 'content');
        Storage::disk('public')->put('central/thumb_small_photo.jpg', 'content');

        // We do NOT create a MediaVault record, meaning these are both orphaned.
        // Previously, the thumbnail would be ignored by getOrphanedFiles().

        $repo = $this->app->make(FileRepositoryContract::class);
        $orphaned = $repo->getOrphanedFiles();
        
        $paths = collect($orphaned->items())->pluck('path')->toArray();
        
        // Assert BOTH the main file AND the thumbnail are detected
        $this->assertContains('central/photo.jpg', $paths);
        $this->assertContains('central/thumb_small_photo.jpg', $paths, "Thumbnail was ignored by the orphaned scanner!");
    }
    
    public function test_used_thumbnails_are_not_detected()
    {
        FileUpload::create([
            'path' => 'central/photo.jpg',
            'disk' => 'public',
            'name' => 'photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'is_used' => true,
            'metadata' => [
                'thumbnails' => [
                    'small' => 'http://localhost/storage/central/thumb_small_photo.jpg'
                ]
            ]
        ]);
        Storage::disk('public')->put('central/photo.jpg', 'content');
        Storage::disk('public')->put('central/thumb_small_photo.jpg', 'content');
        
        $repo = $this->app->make(FileRepositoryContract::class);
        $orphaned = $repo->getOrphanedFiles();
        
        $paths = collect($orphaned->items())->pluck('path')->toArray();
        $this->assertEmpty($paths);
    }
}
