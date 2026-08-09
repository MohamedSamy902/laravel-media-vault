<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohamedSamy902\LaravelMediaVault\Services\FileUsageScanner;
use MohamedSamy902\LaravelMediaVault\Services\ModelScanner;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class AutoDiscoveryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        
        // Ensure database connection is set for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->string('image')->nullable();
        });

        Schema::create('test_product_images', function (Blueprint $table) {
            $table->id();
            $table->integer('product_id');
            $table->string('path');
        });
    }

    public function test_it_discovers_used_paths_from_columns_and_pivot_tables()
    {
        // 1. Insert dummy data
        TestProduct::create(['image' => 'products/main.jpg']);
        TestProduct::create(['image' => 'products/secondary.jpg']);
        
        DB::table('test_product_images')->insert([
            ['product_id' => 1, 'path' => 'gallery/1.jpg'],
            ['product_id' => 2, 'path' => 'gallery/2.jpg'],
        ]);

        // 2. Mock MediaSourceManager
        $sourceManager = $this->createMock(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class);
        $sourceManager->method('getRegisteredModels')->willReturn([TestProduct::class]);
        $sourceManager->method('getModelFields')->willReturn(TestProduct::uploadableFiles());

        // 3. Run Usage Scanner
        $scanner = new FileUsageScanner($sourceManager);
        $usedPaths = iterator_to_array($scanner->getAllUsedPaths());

        // 4. Assert
        $this->assertCount(8, $usedPaths, 'Scanner should find exactly 8 used paths (including thumbnails)');
        $this->assertContains('products/main.jpg', $usedPaths);
        $this->assertContains('products/secondary.jpg', $usedPaths);
        $this->assertContains('products/thumb_small_main.jpg', $usedPaths);
        $this->assertContains('products/thumb_small_secondary.jpg', $usedPaths);
    }
}

class TestProduct extends Model
{
    protected $table = 'test_products';
    protected $guarded = [];
    public $timestamps = false;

    public static function uploadableFiles(): array
    {
        return [
            // Standard column
            'image' => '',
            // Pivot/Related table
            'tables' => [
                'test_product_images' => [
                    'path' => '',
                ]
            ]
        ];
    }
}
