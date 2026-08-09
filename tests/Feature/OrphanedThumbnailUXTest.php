<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class OrphanedThumbnailUXTest extends TestCase
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
        $app['config']->set('media-vault.storage.path', '');
    }

    public function test_orphaned_thumbnails_are_tagged_in_controller()
    {
        Storage::fake('public');
        Storage::disk('public')->put('thumb_small_photo.jpg', 'content');
        Storage::disk('public')->put('regular_photo.jpg', 'content');

        $response = $this->get(route('media-vault.media', ['filter' => 'unused']));
        $response->assertOk();

        // Get the paginator from the view data
        $files = $response->original->getData()['files'];
        $items = collect($files->items())->keyBy('name');

        $this->assertTrue($items->has('regular_photo.jpg'));
        $this->assertTrue($items->has('thumb_small_photo.jpg'));

        $regular = $items->get('regular_photo.jpg');
        $this->assertArrayNotHasKey('is_thumbnail_guess', $regular->metadata ?? []);

        $thumb = $items->get('thumb_small_photo.jpg');
        $this->assertTrue($thumb->metadata['is_thumbnail_guess']);
        $this->assertEquals('small', $thumb->metadata['guessed_size']);
        $this->assertEquals('photo.jpg', $thumb->metadata['guessed_parent']);
    }
}
