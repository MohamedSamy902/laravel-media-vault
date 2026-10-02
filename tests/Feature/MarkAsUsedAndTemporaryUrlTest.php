<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Support\TemporaryUrl;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class MarkAsUsedAndTemporaryUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.temp_url.enabled', true);
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

    public function test_mark_as_used_and_unused_via_facade(): void
    {
        $upload = FileUpload::create([
            'name' => 'x',
            'path' => 'uploads/x.jpg',
            'original_name' => 'x.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 10,
            'is_used' => false,
        ]);

        $used = MediaVault::markAsUsed($upload->id);
        $this->assertTrue($used['status']);
        $this->assertTrue($upload->fresh()->is_used);

        $unused = MediaVault::markAsUnused($upload->id);
        $this->assertTrue($unused['status']);
        $this->assertFalse($upload->fresh()->is_used);
    }

    public function test_temporary_url_signed_route_fallback(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('uploads/demo.jpg', 'demo');

        $url = app(TemporaryUrl::class)->for('uploads/demo.jpg', 120, 'public');

        $this->assertNotNull($url);
        $this->assertTrue(
            str_contains($url, 'signature=') || str_contains($url, 'expiration='),
            'Expected a temporary or signed URL, got: ' . $url
        );
    }
}
