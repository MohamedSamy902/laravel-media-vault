<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Exceptions\QuotaExceededException;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Services\QuotaManager;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class QuotaManagerTest extends TestCase
{
    public function test_has_available_space_returns_true_when_within_limit(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->app['config']->set('media-vault.quota.enabled', false);
        $this->app['config']->set('media-vault.quota.max_size_per_user', 10485760); // 10MB

        $quotaManager = new QuotaManager();
        $this->assertTrue($quotaManager->hasAvailableSpace(1, 1024));
    }

    public function test_has_available_space_returns_false_when_exceeding_limit(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->app['config']->set('media-vault.quota.enabled', false);
        $this->app['config']->set('media-vault.quota.max_size_per_user', 1000);

        $quotaManager = new QuotaManager();
        $this->assertFalse($quotaManager->hasAvailableSpace(1, 5000));
    }

    public function test_check_enforces_quota_against_tracked_usage(): void
    {
        $this->app['config']->set('media-vault.database.enabled', true);
        $this->app['config']->set('media-vault.quota.enabled', true);
        $this->app['config']->set('media-vault.quota.max_size_per_user', 1000);
        $this->app['config']->set('media-vault.quota.key_column', 'user_id');
        $this->app['config']->set('media-vault.database.model', FileUpload::class);

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->run();

        FileUpload::create([
            'name' => 'used',
            'path' => 'uploads/used.bin',
            'original_name' => 'used.bin',
            'disk' => 'public',
            'mime_type' => 'application/octet-stream',
            'type' => 'other',
            'size' => 800,
            'is_used' => true,
            'user_id' => 7,
        ]);

        $quotaManager = new QuotaManager();

        $this->assertTrue($quotaManager->hasAvailableSpace(7, 100));

        $this->expectException(QuotaExceededException::class);
        $quotaManager->check(7, 300);
    }
}
