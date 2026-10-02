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
            'chunking',
            'temp_url',
            'compression',
            'ui',
            'quota',
            'url_upload',
            'url_download',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $config, "Missing config key [{$key}]");
        }

        $this->assertArrayHasKey('socket', $config['security']['virus_scan']);
        $this->assertArrayHasKey('fail_mode', $config['security']['virus_scan']);
        $this->assertArrayHasKey('require_auth', $config['ui']);
        $this->assertArrayHasKey('enabled', $config['temp_url']);
        $this->assertArrayHasKey('max_chunks', $config['chunked']);
        $this->assertArrayHasKey('max_total_size', $config['chunked']);
        $this->assertSame(10000, $config['chunked']['max_chunks']);
        $this->assertSame(5368709120, $config['chunked']['max_total_size']);
        
        $this->assertArrayHasKey('security', $config);
        $this->assertArrayHasKey('bulk_delete_warning_threshold', $config['security']);
        
        $this->assertArrayHasKey('ui', $config);
        
        // Assert some default values
        $this->assertIsArray($config['media_models']);
        $this->assertEquals(100000, $config['max_orphan_scan_limit']);
        $this->assertEquals(100, $config['security']['bulk_delete_warning_threshold']);
    }
}
