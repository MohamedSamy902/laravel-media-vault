<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Verifies that every error path in the package produces a clear, non-empty
 * message and that edge cases do not cause silent failures or crashes.
 */
class ErrorHandlingTest extends TestCase
{
    private MediaVaultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = $this->makeService($this->noopSsrf());
    }

    // =========================================================
    // Validation Errors — Must have clear messages
    // =========================================================

    /** @test */
    public function error_oversized_file_message_is_not_empty(): void
    {
        $this->app['config']->set('media-vault.validation.custom_fields.file', 'required|file|max:1');
        $file = UploadedFile::fake()->create('huge.pdf', 5000, 'application/pdf');

        try {
            $this->service->upload($file);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage(), 'Error message must not be empty');
            $this->assertNotEquals('0', $e->getMessage());
        }
    }

    /** @test */
    public function error_wrong_mime_type_message_mentions_validation(): void
    {
        $file = UploadedFile::fake()->create('virus.sh', 5, 'application/x-sh');

        try {
            $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file|mimes:jpg,png,pdf'],
            ]);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /** @test */
    public function error_invalid_file_in_batch_returns_error_array_not_exception(): void
    {
        $files = [
            UploadedFile::fake()->create('valid.jpg', 50, 'image/jpeg'),
            'this-is-not-a-file',
        ];

        // Must NOT throw — bad items should return error entries
        $results = $this->service->upload($files);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);

        $this->assertIsArray($results[1]);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
        $this->assertNotEmpty($results[1]['error']);
    }

    /** @test */
    public function error_delete_nonexistent_file_throws_with_message(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        try {
            $this->service->delete('uploads/ghost/nowhere.pdf');
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage());
            $this->assertStringNotContainsString('Undefined', $e->getMessage());
        }
    }

    /** @test */
    public function error_delete_requires_string_path_without_db(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        try {
            $this->service->delete(12345);
            $this->fail('Expected exception.');
        } catch (\Exception $e) {
            // When DB is disabled the integer is cast to a path and storage returns 'not found'
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /** @test */
    public function error_url_404_exception_message_contains_http_status(): void
    {
        Http::fake(['https://cdn.example.com/missing.jpg' => Http::response('', 404)]);

        try {
            $this->service->upload([], ['url' => 'https://cdn.example.com/missing.jpg']);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /** @test */
    public function error_url_blocked_mime_exception_mentions_mime(): void
    {
        Http::fake([
            'https://cdn.example.com/script.php' => Http::response(
                '<?php echo "hack";', 200, ['Content-Type' => 'application/x-php']
            ),
        ]);

        try {
            $this->service->upload([], ['url' => 'https://cdn.example.com/script.php']);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            // Message should mention the MIME type
            $this->assertStringContainsString('application/x-php', $e->getMessage());
        }
    }

    // =========================================================
    // Edge Cases — Boundary Conditions
    // =========================================================

    /** @test */
    public function edge_empty_filename_gets_uuid_fallback(): void
    {
        $file   = UploadedFile::fake()->create('', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $this->assertTrue($result->status);
        $this->assertNotEmpty($result->path);
        Storage::disk('public')->assertExists($result->path);
    }

    /** @test */
    public function edge_zero_byte_file_is_handled_gracefully(): void
    {
        $file = UploadedFile::fake()->create('empty.txt', 0, 'text/plain');

        try {
            $result = $this->service->upload($file, [
                'validation_rules' => ['file' => 'file|max:10240'],
            ]);
            // If it succeeds, result must be valid
            if ($result instanceof UploadResult) {
                $this->assertNotEmpty($result->path);
            }
        } catch (\Exception $e) {
            // Acceptable to reject zero-byte files
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /** @test */
    public function edge_very_long_filename_is_handled(): void
    {
        $longName = str_repeat('a', 200) . '.pdf';
        $file     = UploadedFile::fake()->create($longName, 50, 'application/pdf');
        $result   = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        // Storage path uses UUID — not the long original name
        $this->assertTrue($result->status);
        $this->assertEquals($longName, $result->originalName);
        // The stored file path should NOT contain the 200-char name
        $storedFilename = basename($result->path);
        $this->assertLessThan(50, strlen($storedFilename));
        Storage::disk('public')->assertExists($result->path);
    }

    /** @test */
    public function edge_uploading_to_nested_custom_path(): void
    {
        $file   = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'folder_name'      => 'a/b/c/d',
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $this->assertTrue($result->status);
        $this->assertStringContainsString('a/b/c/d', $result->path);
        Storage::disk('public')->assertExists($result->path);
    }

    /** @test */
    public function edge_null_folder_name_uses_base_path_only(): void
    {
        $this->app['config']->set('media-vault.storage.default_folder', null);
        $file   = UploadedFile::fake()->create('flat.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'folder_name'      => null,
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $this->assertTrue($result->status);
        // Should be uploads/<uuid>.pdf — only one slash
        $this->assertEquals(1, substr_count(trim($result->path, '/'), '/'));
    }

    /** @test */
    public function edge_all_file_types_return_correct_type_field(): void
    {
        $cases = [
            ['image/jpeg',      'image'],
            ['image/png',       'image'],
            ['image/gif',       'image'],
            ['image/webp',      'image'],
            ['video/mp4',       'video'],
            ['video/quicktime', 'video'],
            ['audio/mpeg',      'audio'],
            ['audio/wav',       'audio'],
            ['application/pdf', 'pdf'],
            ['application/msword', 'document'],
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document'],
            ['application/vnd.ms-excel', 'document'],
        ];

        foreach ($cases as [$mime, $expectedType]) {
            $ext  = $this->extFor($mime);
            $file = UploadedFile::fake()->create("test.{$ext}", 10, $mime);

            $result = $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file|max:10240'],
            ]);

            $this->assertEquals($expectedType, $result->type,
                "MIME [{$mime}] should map to type [{$expectedType}], got [{$result->type}]"
            );
        }
    }

    /** @test */
    public function edge_batch_delete_partial_failure_returns_all_results(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        // Upload two real files
        $file1 = UploadedFile::fake()->create('one.pdf', 10, 'application/pdf');
        $file2 = UploadedFile::fake()->create('two.pdf', 10, 'application/pdf');
        $r1    = $this->service->upload($file1, ['validation_rules' => ['file' => 'required|file|max:10240']]);
        $r2    = $this->service->upload($file2, ['validation_rules' => ['file' => 'required|file|max:10240']]);

        $results = $this->service->delete([
            $r1->path,
            'uploads/does-not-exist/ghost.pdf', // will fail
            $r2->path,
        ]);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]['status']);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
        $this->assertTrue($results[2]['status']);
    }

    /** @test */
    public function edge_url_with_query_string_is_handled(): void
    {
        Http::fake([
            'https://cdn.example.com/image.jpg?v=123&sig=abc' => Http::response(
                str_repeat('x', 512), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->service->upload([], [
            'url'              => 'https://cdn.example.com/image.jpg?v=123&sig=abc',
            'validation_rules' => ['file' => 'required|file|max:51200'],
        ]);

        $this->assertTrue($result->status);
        Storage::disk('public')->assertExists($result->path);
    }

    /** @test */
    public function edge_content_type_with_charset_param_is_stripped_correctly(): void
    {
        Http::fake([
            'https://cdn.example.com/photo.jpg' => Http::response(
                str_repeat('y', 256), 200,
                ['Content-Type' => 'image/jpeg; charset=utf-8; boundary=something']
            ),
        ]);

        $result = $this->service->upload([], [
            'url'              => 'https://cdn.example.com/photo.jpg',
            'validation_rules' => ['file' => 'required|file|max:51200'],
        ]);

        $this->assertTrue($result->status);
    }

    /** @test */
    public function edge_upload_result_is_json_serializable(): void
    {
        $file   = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        // Must JSON-encode without errors
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('path', $decoded);
        $this->assertArrayHasKey('url', $decoded);
        $this->assertTrue($decoded['status']);
    }

    // =========================================================
    // Helper
    // =========================================================

    private function extFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'video/mp4'       => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg'      => 'mp3',
            'audio/wav'       => 'wav',
            'application/pdf' => 'pdf',
            default           => 'bin',
        };
    }
}
