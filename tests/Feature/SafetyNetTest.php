<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Security\BulkDeletionGuard;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SafetyNetTest extends TestCase
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

    public function test_bulk_destroy_fails_without_preview_token()
    {
        $response = $this->postJson(route('media-vault.media.bulk-destroy'), [
            'ids' => ['test'] // Old API style
        ]);

        $response->assertStatus(400)
                 ->assertJson(['message' => 'Missing bulk deletion token. Please preview first.']);
    }

    public function test_bulk_destroy_preview_returns_token_and_summary()
    {
        $file1 = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        
        $response = $this->postJson(route('media-vault.media.bulk-destroy-preview'), [
            'ids' => [base64_encode($file1->path)]
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('preview.token'));
        $this->assertEquals(1, $response->json('preview.count'));
    }

    public function test_threshold_warning_is_triggered_on_large_deletions()
    {
        $this->app['config']->set('media-vault.security.bulk_delete_warning_threshold', 2);
        
        $file1 = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        $file2 = FileUpload::create(['name' => 'f2', 'path' => 'f2.jpg', 'original_name' => 'f2', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);

        $response = $this->postJson(route('media-vault.media.bulk-destroy-preview'), [
            'ids' => [base64_encode($file1->path), base64_encode($file2->path)]
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('preview.requires_extra_warning'));
    }

    public function test_guard_rejects_invalid_token()
    {
        $guard = $this->app->make(BulkDeletionGuard::class);
        $this->expectException(\RuntimeException::class);
        $guard->execute('invalid-token', false);
    }

    public function test_token_is_bound_to_user_and_ip()
    {
        $file = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        
        // Simulate a specific IP and generate token
        $previewResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
                                ->postJson(route('media-vault.media.bulk-destroy-preview'), [
            'ids' => [base64_encode($file->path)]
        ]);
        
        $token = $previewResponse->json('preview.token');

        // Try to execute from a different IP
        $executeResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.2'])
                                ->postJson(route('media-vault.media.bulk-destroy'), [
            'token' => $token
        ]);

        $executeResponse->assertStatus(400)
                        ->assertJson(['message' => 'Unauthorized token usage. Token context mismatch.']);
    }

    public function test_rate_limiting_on_bulk_destroy()
    {
        // Route has throttle:10,1 (10 requests per minute)
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('media-vault.media.bulk-destroy'), ['token' => 'fake-token']);
        }
        
        // The 11th request should be rate limited (429)
        $response = $this->postJson(route('media-vault.media.bulk-destroy'), ['token' => 'fake-token']);
        $response->assertStatus(429);
    }

    public function test_token_is_one_time_use()
    {
        $file = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        
        $previewResponse = $this->postJson(route('media-vault.media.bulk-destroy-preview'), [
            'ids' => [base64_encode($file->path)]
        ]);
        
        $token = $previewResponse->json('preview.token');

        // First use should succeed
        $response1 = $this->postJson(route('media-vault.media.bulk-destroy'), ['token' => $token]);
        $response1->assertStatus(200);

        // Second use should fail
        $response2 = $this->postJson(route('media-vault.media.bulk-destroy'), ['token' => $token]);
        $response2->assertStatus(400)
                  ->assertJson(['message' => 'Invalid or expired bulk deletion token. Please preview again.']);
    }

    public function test_token_is_a_static_snapshot()
    {
        $file1 = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        
        // Preview with only file1
        $previewResponse = $this->postJson(route('media-vault.media.bulk-destroy-preview'), [
            'ids' => [base64_encode($file1->path)]
        ]);
        
        $token = $previewResponse->json('preview.token');

        // Now, another file is added that theoretically fits some criteria (but wasn't in the preview)
        $file2 = FileUpload::create(['name' => 'f2', 'path' => 'f2.jpg', 'original_name' => 'f2', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);

        // Execute using the old token
        $response = $this->postJson(route('media-vault.media.bulk-destroy'), ['token' => $token]);
        $response->assertStatus(200);

        // Only file1 should be deleted, file2 remains
        $this->assertSoftDeleted('file_uploads', ['id' => $file1->id]);
        $this->assertDatabaseHas('file_uploads', ['id' => $file2->id, 'deleted_at' => null]);
    }
}
