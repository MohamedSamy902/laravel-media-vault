<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;
use MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager;
use MohamedSamy902\LaravelMediaVault\Repositories\MultiSourceFileRepository;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;

class MultiSourceTestUser extends Model
{
    use HasMediaFields;
    
    protected $table = 'test_users';
    protected $guarded = [];
    public $timestamps = false;
    
    protected array $mediaFields = [
        'avatar' => ['disk' => 'public'],
    ];
}

class MultiSourceTestProduct extends Model
{
    use HasMediaFields;

    protected $table = 'test_products';
    protected $guarded = [];
    public $timestamps = false;

    protected array $mediaFields = [
        'gallery' => ['disk' => 'public', 'multiple' => true],
    ];
}

class MultiSourceMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
        });

        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->json('gallery')->nullable();
        });
    }
    
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.media_models', [
            MultiSourceTestUser::class => (new MultiSourceTestUser)->getMediaFieldsDefinition(),
            MultiSourceTestProduct::class => (new MultiSourceTestProduct)->getMediaFieldsDefinition(),
        ]);
    }
    
    public function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('public');
        
        // Ensure the manager is loaded with config
        $this->app->make(MediaSourceManager::class)->loadModels();
    }

    public function test_can_read_from_multiple_sources()
    {
        // 1. Central Source
        FileUpload::create([
            'path' => 'central/image.jpg',
            'disk' => 'public',
            'name' => 'image.jpg',
            'original_name' => 'image.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'is_used' => true,
        ]);
        Storage::disk('public')->put('central/image.jpg', 'content');

        // 2. Custom Source (Single)
        MultiSourceTestUser::create([
            'name' => 'John',
            'avatar' => 'users/avatar.png',
        ]);
        Storage::disk('public')->put('users/avatar.png', 'content');

        // 3. Custom Source (Multiple)
        MultiSourceTestProduct::create([
            'name' => 'Product 1',
            'gallery' => json_encode(['products/1.jpg', 'products/2.jpg']),
        ]);
        Storage::disk('public')->put('products/1.jpg', 'content');
        Storage::disk('public')->put('products/2.jpg', 'content');

        /** @var MultiSourceFileRepository $repo */
        $repo = $this->app->make(FileRepositoryContract::class);
        
        // Central source (default)
        $filesCentral = $repo->getFilteredFiles();
        $this->assertEquals(1, $filesCentral->total()); 

        // User source
        $filesUser = $repo->getFilteredFiles(['source' => MultiSourceTestUser::class]);
        $this->assertEquals(1, $filesUser->total());

        // Product source (now paginates rows, so total() is 1, but items contains 2 files)
        $filesProduct = $repo->getFilteredFiles(['source' => MultiSourceTestProduct::class]);
        $this->assertCount(2, $filesProduct->items());
        $this->assertEquals(1, $filesProduct->total());

        $stats = $repo->getStats();
        // Stats for custom sources now count ROWS, not files.
        // Central: 1 file, User: 1 row, Product: 1 row = Total 3 rows/files.
        $this->assertEquals(3, $stats['total_files']);
    }

    public function test_can_calculate_orphaned_files_across_sources()
    {
        // Set storage path to root for this test to match what it writes
        $this->app['config']->set('media-vault.storage.path', '');

        // Path used in central
        FileUpload::create([
            'path' => 'central/used.jpg',
            'disk' => 'public',
            'name' => 'used.jpg',
            'original_name' => 'used.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'is_used' => true,
        ]);
        Storage::disk('public')->put('central/used.jpg', 'content');

        // Path used in custom
        MultiSourceTestUser::create([
            'name' => 'Jane',
            'avatar' => 'users/used.png',
        ]);
        Storage::disk('public')->put('users/used.png', 'content');

        // Orphaned paths (exist on disk, not in any DB)
        Storage::disk('public')->put('orphaned1.jpg', 'content');
        Storage::disk('public')->put('orphaned2.png', 'content');

        /** @var MultiSourceFileRepository $repo */
        $repo = $this->app->make(FileRepositoryContract::class);
        $orphaned = $repo->getOrphanedFiles();

        $this->assertEquals(2, $orphaned->total());
        $paths = collect($orphaned->items())->pluck('path')->toArray();
        $this->assertContains('orphaned1.jpg', $paths);
        $this->assertContains('orphaned2.png', $paths);
    }

    public function test_deleting_from_custom_source_nullifies_column_and_deletes_file()
    {
        $user = MultiSourceTestUser::create([
            'name' => 'Jane',
            'avatar' => 'users/avatar.png',
        ]);
        Storage::disk('public')->put('users/avatar.png', 'content');
        $this->assertTrue(Storage::disk('public')->exists('users/avatar.png'));

        /** @var MultiSourceFileRepository $repo */
        $repo = $this->app->make(FileRepositoryContract::class);
        
        $deleted = $repo->delete('users/avatar.png', true);

        $this->assertTrue($deleted);
        $this->assertFalse(Storage::disk('public')->exists('users/avatar.png'));
        
        $user->refresh();
        $this->assertNull($user->avatar);
    }

    public function test_thumbnails_are_not_flagged_as_orphaned()
    {
        $this->app['config']->set('media-vault.storage.path', '');

        // Path used in central
        FileUpload::create([
            'path' => 'central/used.jpg',
            'disk' => 'public',
            'name' => 'used.jpg',
            'original_name' => 'used.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'is_used' => true,
            'metadata' => [
                'thumbnails' => [
                    'small' => 'http://localhost/storage/central/thumb_small_used.jpg',
                    'medium' => 'http://localhost/storage/central/thumb_medium_used.jpg',
                ]
            ],
        ]);
        Storage::disk('public')->put('central/used.jpg', 'content');
        Storage::disk('public')->put('central/thumb_small_used.jpg', 'content');
        Storage::disk('public')->put('central/thumb_medium_used.jpg', 'content');

        /** @var MultiSourceFileRepository $repo */
        $repo = $this->app->make(FileRepositoryContract::class);
        $orphaned = $repo->getOrphanedFiles();

        $this->assertEquals(0, $orphaned->total(), 'Thumbnails should not be flagged as orphaned.');
    }

    public function test_custom_media_source_get_stats_does_not_hit_disk()
    {
        MultiSourceTestUser::create([
            'name' => 'Jane',
            'avatar' => 'users/avatar.png',
        ]);
        
        // Spy on the Storage facade to ensure no disk I/O methods are called
        Storage::spy();
        
        /** @var MultiSourceFileRepository $repo */
        $repo = $this->app->make(FileRepositoryContract::class);
        $stats = $repo->getStats();
        
        Storage::shouldNotHaveReceived('exists');
        Storage::shouldNotHaveReceived('size');
        Storage::shouldNotHaveReceived('mimeType');

        $this->assertEquals(1, $stats['total_files']);
    }

    public function test_regenerate_thumbnails_command()
    {
        $this->app['config']->set('media-vault.thumbnails.enabled', true);
        $this->app['config']->set('media-vault.thumbnails.sizes', ['small' => ['width' => 100, 'height' => 100]]);
        
        $processor = \Mockery::mock(\MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract::class);
        $processor->shouldReceive('thumbnail')->andReturn('mock_thumbnail_content');
        $this->app->instance(\MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract::class, $processor);
        $this->app->instance(\MohamedSamy902\LaravelMediaVault\Services\ImageProcessor::class, $processor); // Command injects the concrete class
        
        $file = FileUpload::create([
            'path' => 'central/photo.jpg',
            'disk' => 'public',
            'name' => 'photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'is_used' => true,
            'metadata' => []
        ]);
        Storage::disk('public')->put('central/photo.jpg', 'fake_content');
        
        $this->artisan('media-vault:regenerate-thumbnails')
             ->expectsOutputToContain('Complete! Generated: 1, Failed: 0')
             ->assertExitCode(0);
             
        Storage::disk('public')->assertExists('central/thumb_small_photo.jpg');
        
        $file->refresh();
        $this->assertNotEmpty($file->metadata['thumbnails']);
    }
}
