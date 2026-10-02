<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class DashboardStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_config_save_returns_read_only_warning(): void
    {
        $response = $this->post(route('media-vault.config.save'));

        $response->assertRedirect();
        $response->assertSessionHas('warning');
    }

    public function test_bulk_restore_endpoint_restores_trashed_files(): void
    {
        $path = 'uploads/default/bulk-restore.pdf';
        Storage::disk('public')->put($path, 'bytes');
        Storage::disk('public')->put(TrashPath::toTrash($path), 'bytes');
        Storage::disk('public')->delete($path);

        $file = FileUpload::create([
            'original_name' => 'bulk-restore.pdf',
            'name' => 'bulk-restore.pdf',
            'path' => $path,
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 5,
            'is_used' => false,
        ]);
        $file->delete();

        $response = $this->postJson(route('media-vault.media.bulk-restore'), [
            'ids' => [base64_encode($path)],
        ]);

        $response->assertOk()->assertJson(['status' => true, 'restored' => 1]);
        $this->assertDatabaseHas('file_uploads', ['id' => $file->id, 'deleted_at' => null]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_scan_status_endpoint_returns_idle_by_default(): void
    {
        $response = $this->getJson(route('media-vault.scan.status'));

        $response->assertOk()->assertJsonPath('state', 'idle');
    }
}
