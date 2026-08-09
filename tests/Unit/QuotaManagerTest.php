<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Services\QuotaManager;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class QuotaManagerTest extends TestCase
{
    public function test_has_available_space_returns_true_when_within_limit(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->app['config']->set('media-vault.quota.max_size_per_user', 10485760); // 10MB

        $quotaManager = new QuotaManager();
        $this->assertTrue($quotaManager->hasAvailableSpace(1, 1024));
    }

    public function test_has_available_space_returns_false_when_exceeding_limit(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->app['config']->set('media-vault.quota.max_size_per_user', 1000);

        $quotaManager = new QuotaManager();
        $this->assertFalse($quotaManager->hasAvailableSpace(1, 5000));
    }
}
