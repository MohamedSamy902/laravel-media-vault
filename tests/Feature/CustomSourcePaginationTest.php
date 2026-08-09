<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class CustomSourcePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        \Illuminate\Support\Facades\Schema::create('test_products', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->json('gallery')->nullable();
        });
    }
    
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.media_models', [
            MultiSourceTestProduct3::class => (new MultiSourceTestProduct3)->getMediaFieldsDefinition(),
        ]);
    }

    public function test_pagination_uses_db_pagination()
    {
        $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class)->loadModels();
        Storage::fake('public');

        // Create 25 products, each with 2 images
        for ($i = 0; $i < 25; $i++) {
            MultiSourceTestProduct3::create([
                'gallery' => json_encode(["image_{$i}_1.jpg", "image_{$i}_2.jpg"])
            ]);
        }

        $repo = $this->app->make(FileRepositoryContract::class);
        
        // When we get page 1 (20 per page), it should return 20 ROWS.
        // Each row has 2 images, so it should return 40 FileDto objects in the paginator's items!
        $paginator = $repo->getFilteredFiles(['source' => MultiSourceTestProduct3::class], 20);
        
        // Assert total rows is 25
        $this->assertEquals(25, $paginator->total());
        
        // Assert items count is 40
        $this->assertCount(40, $paginator->items());
    }
}

class MultiSourceTestProduct3 extends \Illuminate\Database\Eloquent\Model
{
    use \MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;
    protected $table = 'test_products';
    protected $guarded = [];
    public $timestamps = false;
    protected array $mediaFields = ['gallery' => ['disk' => 'public', 'multiple' => true]];
}
