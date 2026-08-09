<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_index_loads_without_fatal_error(): void
    {
        $response = $this->get('/media-vault');
        
        $response->assertStatus(200);
        $response->assertSee('Dashboard — Advanced File Upload', false);
    }
}
