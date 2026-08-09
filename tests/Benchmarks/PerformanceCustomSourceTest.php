<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Benchmarks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MohamedSamy902\LaravelMediaVault\Sources\CustomMediaSource;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PerformanceDeleteTestUser extends Model
{
    protected $table = 'perf_test_users';
    protected $guarded = [];
    public $timestamps = false;
}

class PerformanceCustomSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        
        Schema::create('perf_test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
        });
    }

    public function test_custom_source_delete_does_not_exhaust_memory()
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/1.jpg', 'content');
        
        $source = new CustomMediaSource(PerformanceDeleteTestUser::class, [
            'avatar' => ['disk' => 'public', 'multiple' => false]
        ]);

        // Insert 2000 users with 'avatar' => 'uploads/1.jpg'
        $data = [];
        for ($i = 0; $i < 2000; $i++) {
            $data[] = ['name' => "test{$i}", 'avatar' => 'uploads/1.jpg'];
        }
        PerformanceDeleteTestUser::insert($data);

        // Before delete, memory usage
        $memBefore = memory_get_usage();

        $source->delete('uploads/1.jpg');

        $memAfter = memory_get_usage();

        // Memory diff should be small (less than 20MB)
        // If it loaded 2000 models, it would take a lot of memory.
        $this->assertLessThan(20 * 1024 * 1024, $memAfter - $memBefore, "Memory usage exceeded limits! (Possible N+1 or loading all models into memory)");
        
        // Assert we actually did something
        $this->assertEquals(0, PerformanceDeleteTestUser::whereNotNull('avatar')->count());
        $this->assertFalse(Storage::disk('public')->exists('uploads/1.jpg'));
    }
}
