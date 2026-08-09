<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Support\Str;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class SessionStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('media-vault.database.enabled', true);
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    /** @test */
    public function it_returns_session_status_and_received_chunks_for_valid_active_session(): void
    {
        $sessionId = Str::uuid()->toString();

        UploadSession::create([
            'session_id'      => $sessionId,
            'original_name'   => 'video_presentation.mp4',
            'disk'            => 'public',
            'folder'          => 'uploads',
            'mime_type'       => 'video/mp4',
            'total_size'      => 50000000,
            'total_chunks'    => 10,
            'received_chunks' => [0 => true, 1 => true, 2 => true],
            'status'          => 'pending',
            'expires_at'      => now()->addHours(24),
        ]);

        $route = route('media-vault.sessions.status', ['sessionId' => $sessionId]);

        $response = $this->getJson($route);

        $response->assertStatus(200);
        $response->assertJson([
            'status'          => true,
            'session_id'      => $sessionId,
            'original_name'   => 'video_presentation.mp4',
            'total_size'      => 50000000,
            'total_chunks'    => 10,
            'received_count'  => 3,
            'received_chunks' => [0, 1, 2],
            'missing_chunks'  => [3, 4, 5, 6, 7, 8, 9],
        ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_session(): void
    {
        $fakeSessionId = Str::uuid()->toString();
        $route = route('media-vault.sessions.status', ['sessionId' => $fakeSessionId]);

        $response = $this->getJson($route);

        $response->assertStatus(404);
        $response->assertJson([
            'status'     => false,
            'is_expired' => true,
        ]);
    }

    /** @test */
    public function it_returns_404_for_expired_session(): void
    {
        $sessionId = Str::uuid()->toString();

        UploadSession::create([
            'session_id'      => $sessionId,
            'original_name'   => 'old_file.zip',
            'disk'            => 'public',
            'folder'          => 'uploads',
            'mime_type'       => 'application/zip',
            'total_size'      => 100000,
            'total_chunks'    => 2,
            'received_chunks' => [0 => true],
            'status'          => 'pending',
            'expires_at'      => now()->subMinute(), // Expired in the past
        ]);

        $route = route('media-vault.sessions.status', ['sessionId' => $sessionId]);

        $response = $this->getJson($route);

        $response->assertStatus(404);
        $response->assertJson([
            'status'     => false,
            'is_expired' => true,
        ]);
    }
}
