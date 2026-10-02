<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class OwnershipAndSessionLockTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.ui.enforce_ownership', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
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

    public function test_dashboard_delete_rejects_non_owner(): void
    {
        FileUpload::create([
            'name' => 'owned',
            'path' => 'uploads/owned.jpg',
            'original_name' => 'owned.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 10,
            'is_used' => false,
            'user_id' => 99,
        ]);

        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('check')->andReturn(true);

        $encoded = rtrim(strtr(base64_encode('uploads/owned.jpg'), '+/', '-_'), '=');
        $response = $this->deleteJson(route('media-vault.media.destroy', ['encodedPath' => $encoded]));

        $response->assertStatus(403);
    }

    public function test_complete_session_rejects_assembling_status(): void
    {
        $session = UploadSession::create([
            'session_id' => 'sess-lock-1',
            'user_id' => null,
            'original_name' => 'a.bin',
            'disk' => 'public',
            'folder' => 'uploads',
            'mime_type' => 'application/octet-stream',
            'total_size' => 10,
            'total_chunks' => 1,
            'received_chunks' => [true],
            'status' => 'assembling',
            'expires_at' => now()->addHour(),
        ]);

        $service = $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\ResumableUploadService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already complete or assembling');
        $service->completeSession($session->session_id);
    }
}
