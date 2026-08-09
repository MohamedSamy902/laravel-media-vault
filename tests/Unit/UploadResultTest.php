<?php

namespace MohamedSamy902\LaravelMediaVault\Tests\Unit;

use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;

class UploadResultTest extends TestCase
{
    private UploadResult $result;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->result = new UploadResult(
            status:        true,
            originalName:  'photo.jpg',
            path:          'uploads/default/abc-123.webp',
            url:           'https://example.com/storage/uploads/default/abc-123.webp',
            mimeType:      'image/jpeg',
            type:          'image',
            size:          204800,
            thumbnailUrls: ['small' => 'https://example.com/thumb_small.webp'],
            id:    42,
        );
    }

    // =========================================================================
    // toArray()
    // =========================================================================

    public function test_to_array_contains_all_keys(): void
    {
        $arr = $this->result->toArray();

        $this->assertArrayHasKey('status', $arr);
        $this->assertArrayHasKey('original_name', $arr);
        $this->assertArrayHasKey('path', $arr);
        $this->assertArrayHasKey('url', $arr);
        $this->assertArrayHasKey('mime_type', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertArrayHasKey('size', $arr);
        $this->assertArrayHasKey('thumbnail_urls', $arr);
        $this->assertArrayHasKey('database_id', $arr);
    }

    public function test_to_array_values_match_constructor(): void
    {
        $arr = $this->result->toArray();

        $this->assertTrue($arr['status']);
        $this->assertEquals('photo.jpg', $arr['original_name']);
        $this->assertEquals('uploads/default/abc-123.webp', $arr['path']);
        $this->assertEquals('image/jpeg', $arr['mime_type']);
        $this->assertEquals('image', $arr['type']);
        $this->assertEquals(204800, $arr['size']);
        $this->assertEquals(['small' => 'https://example.com/thumb_small.webp'], $arr['thumbnail_urls']);
        $this->assertEquals(42, $arr['database_id']);
    }

    // =========================================================================
    // toJson()
    // =========================================================================

    public function test_to_json_is_valid_json(): void
    {
        $json = $this->result->toJson();

        $this->assertJson($json);
    }

    public function test_to_json_decodes_to_correct_shape(): void
    {
        $decoded = json_decode($this->result->toJson(), true);

        $this->assertTrue($decoded['status']);
        $this->assertEquals('photo.jpg', $decoded['original_name']);
        $this->assertEquals('uploads/default/abc-123.webp', $decoded['path']);
        $this->assertEquals(204800, $decoded['size']);
        $this->assertEquals(42, $decoded['database_id']);
    }

    // =========================================================================
    // ArrayAccess — backward compatibility
    // =========================================================================

    public function test_array_access_reads_status(): void
    {
        $this->assertTrue($this->result['status']);
    }

    public function test_array_access_reads_path(): void
    {
        $this->assertEquals('uploads/default/abc-123.webp', $this->result['path']);
    }

    public function test_array_access_reads_original_name(): void
    {
        $this->assertEquals('photo.jpg', $this->result['original_name']);
    }

    public function test_array_access_reads_thumbnail_urls(): void
    {
        $this->assertIsArray($this->result['thumbnail_urls']);
        $this->assertArrayHasKey('small', $this->result['thumbnail_urls']);
    }

    public function test_array_access_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->result['nonexistent_key']);
    }

    public function test_offset_exists_returns_true_for_known_key(): void
    {
        $this->assertTrue(isset($this->result['path']));
        $this->assertTrue(isset($this->result['status']));
    }

    public function test_offset_exists_returns_false_for_unknown_key(): void
    {
        $this->assertFalse(isset($this->result['nonexistent']));
    }

    public function test_offset_set_is_silently_ignored(): void
    {
        // Value object is immutable — writes are silently ignored
        $this->result['path'] = 'hacked';
        $this->assertEquals('uploads/default/abc-123.webp', $this->result['path']);
    }

    // =========================================================================
    // Readonly properties
    // =========================================================================

    public function test_properties_are_directly_accessible(): void
    {
        $this->assertTrue($this->result->status);
        $this->assertEquals('photo.jpg', $this->result->originalName);
        $this->assertEquals('uploads/default/abc-123.webp', $this->result->path);
        $this->assertEquals('image/jpeg', $this->result->mimeType);
        $this->assertEquals(204800, $this->result->size);
        $this->assertEquals(42, $this->result->databaseId);
    }

    public function test_nullable_database_id_defaults_to_null(): void
    {
        $result = new UploadResult(
            status:       true,
            originalName: 'test.pdf',
            path:         'uploads/test.pdf',
            url:          'https://example.com/test.pdf',
            mimeType:     'application/pdf',
            type:         'pdf',
            size:         1024,
        );

        $this->assertNull($result->databaseId);
        $this->assertEmpty($result->thumbnailUrls);
    }

    // =========================================================================
    // JsonSerializable
    // =========================================================================

    public function test_json_encode_uses_json_serializable(): void
    {
        $json    = json_encode($this->result);
        $decoded = json_decode($json, true);

        $this->assertEquals('photo.jpg', $decoded['original_name']);
        $this->assertTrue($decoded['status']);
    }
}
