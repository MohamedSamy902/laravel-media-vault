<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class ConfigLoadingTest extends TestCase
{
    public function test_all_expected_config_keys_are_loaded()
    {
        $config = config('media-vault');

        $this->assertIsArray($config);
        
        $this->assertArrayHasKey('media_models', $config);
        $this->assertArrayHasKey('max_orphan_scan_limit', $config);
        $expectedKeys = [
            'storage',
            'processing',
            'thumbnails',
            'database',
            'security',
            'chunked',
            'temp_url',
            'compression',
            'logging',
            'ui.route_prefix',
            'ui.middleware',
        ];
        
        $this->assertArrayHasKey('security', $config);
        $this->assertArrayHasKey('bulk_delete_warning_threshold', $config['security']);
        
        $this->assertArrayHasKey('ui', $config);
        
        // Assert some default values
        $this->assertIsArray($config['media_models']);
        $this->assertEquals(100000, $config['max_orphan_scan_limit']);
        $this->assertEquals(100, $config['security']['bulk_delete_warning_threshold']);
    }
}
