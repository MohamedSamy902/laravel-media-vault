<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;

class UploadResultTest extends TestCase
{
    public function test_upload_result_json_format_matches_documentation(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100);
        
        $service = $this->app->make(\MohamedSamy902\LaravelMediaVault\Contracts\MediaVaultContract::class);
        $result = $service->upload($file);

        $this->assertInstanceOf(UploadResult::class, $result);
        
        $json = $result->toJson();
        $array = json_decode($json, true);

        // Verify structure matches README
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('database_id', $array); // For backward compat
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('original_name', $array);
        $this->assertArrayHasKey('path', $array);
        $this->assertArrayHasKey('url', $array);
        $this->assertArrayHasKey('mime_type', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('size', $array);
        $this->assertArrayHasKey('disk', $array);

        // Test property access
        $this->assertEquals($result->id, $result->databaseId);
        $this->assertEquals('public', $result->disk);
    }
}
