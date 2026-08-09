<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Benchmarks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PaginationBenchmarkTest extends TestCase
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
            MultiSourceTestProduct4::class => (new MultiSourceTestProduct4)->getMediaFieldsDefinition(),
        ]);
    }

    public function test_benchmark_pagination_with_10k_records()
    {
        $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class)->loadModels();

        // Seed 10K rows using raw inserts for speed
        $data = [];
        for ($i = 0; $i < 10000; $i++) {
            $data[] = [
                'gallery' => json_encode(["image_{$i}_1.jpg", "image_{$i}_2.jpg"])
            ];
            
            // Insert in chunks of 1000
            if (count($data) >= 1000) {
                DB::table('test_products')->insert($data);
                $data = [];
            }
        }

        $repo = $this->app->make(FileRepositoryContract::class);
        
        $start = microtime(true);
        $paginator = $repo->getFilteredFiles(['source' => MultiSourceTestProduct4::class], 20);
        $end = microtime(true);
        
        $time = ($end - $start) * 1000;
        echo "\n[BENCHMARK] DB Pagination for 10K rows took: " . number_format($time, 2) . " ms\n";

        $this->assertLessThan(100, $time, "Pagination should take less than 100ms");
    }
}

class MultiSourceTestProduct4 extends \Illuminate\Database\Eloquent\Model
{
    use \MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;
    protected $table = 'test_products';
    protected $guarded = [];
    public $timestamps = false;
    protected array $mediaFields = ['gallery' => ['disk' => 'public', 'multiple' => true]];
}
