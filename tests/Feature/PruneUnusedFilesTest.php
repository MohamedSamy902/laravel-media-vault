<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class PruneUnusedFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('media-vault.database.enabled', true);
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

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    public function test_prune_skips_trashed_and_soft_deleted_records(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        $eligible = FileUpload::create([
            'name' => 'old-unused',
            'path' => 'uploads/old.jpg',
            'original_name' => 'old.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 100,
            'is_used' => false,
        ]);
        $eligible->forceFill(['created_at' => now()->subDays(60)])->save();

        $inTrash = FileUpload::create([
            'name' => 'trash-path',
            'path' => 'uploads/.trash/old2.jpg',
            'original_name' => 'old2.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 100,
            'is_used' => false,
        ]);
        $inTrash->forceFill(['created_at' => now()->subDays(60)])->save();

        $softDeleted = FileUpload::create([
            'name' => 'soft',
            'path' => 'uploads/soft.jpg',
            'original_name' => 'soft.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 100,
            'is_used' => false,
        ]);
        $softDeleted->forceFill(['created_at' => now()->subDays(60)])->save();
        $softDeleted->delete();

        $this->artisan('media-vault:prune-unused', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Found 1 unused files')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_prune_respects_null_prune_after_without_days_option(): void
    {
        config(['media-vault.database.prune_after' => null]);

        $this->artisan('media-vault:prune-unused')
            ->expectsOutputToContain('Pruning is disabled')
            ->assertExitCode(0);
    }
}
