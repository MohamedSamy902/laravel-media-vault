<?php
namespace MohamedSamy902\LaravelMediaVault\Tests\Benchmarks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImportOrphansPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
    }
    
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function test_import_orphans_does_not_have_n_plus_one_queries()
    {
        Storage::fake('public');
        
        // Create 2500 files on disk
        for ($i = 0; $i < 2500; $i++) {
            Storage::disk('public')->put("uploads/test{$i}.jpg", 'content');
        }

        DB::enableQueryLog();

        $this->artisan('media-vault:import-orphans', ['disk' => 'public'])
             ->assertExitCode(0);

        $queries = DB::getQueryLog();
        
        // Before fix: 2500+ queries. After fix: < 20 queries (e.g. chunks of 500 = 5 selects + 5 inserts).
        $this->assertLessThan(20, count($queries), "Too many queries executed! N+1 problem detected.");
        $this->assertEquals(2500, FileUpload::count());
    }
}
