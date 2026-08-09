<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @test */
    public function rate_limit_rejects_requests_exceeding_max_allowed_uploads(): void
    {
        $this->app['config']->set('media-vault.security.rate_limit', [
            'enabled'     => true,
            'max_uploads' => 3,
            'per_minutes' => 1,
        ]);

        $route = route('media-vault.upload');

        // First 3 requests should pass or fail validation, but NOT be throttled with 429
        for ($i = 1; $i <= 3; $i++) {
            $file = UploadedFile::fake()->create("file_{$i}.pdf", 50, 'application/pdf');
            $response = $this->postJson($route, ['file' => $file]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        // 4th request must be throttled with 429 Too Many Requests
        $file = UploadedFile::fake()->create('file_4.pdf', 50, 'application/pdf');
        $response = $this->postJson($route, ['file' => $file]);

        $response->assertStatus(429);
        $response->assertJson([
            'status'  => false,
            'message' => 'Too Many Requests. Rate limit exceeded for file operations.',
        ]);
    }
}
