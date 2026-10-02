<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Services\ResumableUploadService;
use MohamedSamy902\LaravelMediaVault\Support\FileAuthorization;
use MohamedSamy902\LaravelMediaVault\Support\FileCategories;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class PracticalUpgradesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.chunked.max_chunks', 5);
        $app['config']->set('media-vault.chunked.max_total_size', 1024);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    public function test_chunked_session_rejects_excessive_chunk_count(): void
    {
        $service = $this->app->make(ResumableUploadService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured maximum of 5');

        $service->startSession(
            originalName: 'big.bin',
            mimeType: 'application/octet-stream',
            totalSize: 100,
            totalChunks: 6,
            options: ['validation_rules' => ['file' => 'required|file']],
        );
    }

    public function test_chunked_session_rejects_excessive_total_size(): void
    {
        $service = $this->app->make(ResumableUploadService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the chunked upload ceiling');

        $service->startSession(
            originalName: 'big.bin',
            mimeType: 'application/octet-stream',
            totalSize: 2048,
            totalChunks: 2,
            options: ['validation_rules' => ['file' => 'required|file']],
        );
    }

    public function test_file_categories_format_and_detect(): void
    {
        $this->assertSame('image', FileCategories::categoryFromExtension('webp'));
        $this->assertSame('document', FileCategories::categoryFromExtension('pdf'));
        $this->assertTrue(FileCategories::isImagePath('uploads/a.jpg'));
        $this->assertSame('1.5 KB', FileCategories::formatBytes(1536));
    }

    public function test_file_authorization_blocks_path_traversal(): void
    {
        $auth = new FileAuthorization();
        $this->assertTrue($auth->isUnauthorizedToModify('../etc/passwd'));
        $this->assertTrue($auth->isUnauthorizedToModify("uploads/a\0.jpg"));
    }
}
