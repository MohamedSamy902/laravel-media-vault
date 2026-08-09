<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Exception;

class MediaVaultServiceTest extends TestCase
{
    protected MediaVaultService $service;
    protected MediaVaultService $urlService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Default service uses the real SSRF validator (for non-URL tests)
        $this->service    = $this->app->make(MediaVaultService::class);
        // URL service bypasses DNS resolution — suitable for faked HTTP calls
        $this->urlService = $this->makeService($this->noopSsrf());
    }

    // =========================================================================
    // Upload — Direct File
    // =========================================================================

    public function test_uploads_single_image_file(): void
    {
        $file   = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');
        $result = $this->service->upload($file);

        $this->assertInstanceOf(UploadResult::class, $result);
        $this->assertTrue($result->status);
        $this->assertNotEmpty($result->path);
        $this->assertNotEmpty($result->url);
        $this->assertEquals('photo.jpg', $result->originalName);
        $this->assertEquals('image', $result->type);
        Storage::disk('public')->assertExists($result->path);
    }

    public function test_upload_result_implements_array_access_for_backward_compat(): void
    {
        $file   = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');
        $result = $this->service->upload($file);

        // ArrayAccess backward-compatibility — $result['path'] must still work
        $this->assertInstanceOf(UploadResult::class, $result);
        $this->assertNotEmpty($result['path']);
        $this->assertNotEmpty($result['url']);
        $this->assertTrue($result['status']);
        $this->assertEquals('photo.jpg', $result['original_name']);
    }

    public function test_uploads_multiple_files_as_array(): void
    {
        $files = [
            UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('b.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('c.jpg', 50, 'image/jpeg'),
        ];

        $results = $this->service->upload($files);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(UploadResult::class, $result);
            $this->assertTrue($result->status);
            Storage::disk('public')->assertExists($result->path);
        }
    }

    public function test_upload_uses_uuid_filename_not_original(): void
    {
        $file   = UploadedFile::fake()->create('my-secret-name.jpg', 50, 'image/jpeg');
        $result = $this->service->upload($file);

        $storedName = basename($result->path);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z]+$/',
            $storedName
        );
        $this->assertEquals('my-secret-name.jpg', $result->originalName);
    }

    // =========================================================================
    // Upload — Custom Options
    // =========================================================================

    public function test_upload_to_custom_disk(): void
    {
        Storage::fake('s3');
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        // Storage::fake('s3') creates a mock disk so no real driver is needed.
        // We still need to bypass the cloud dependency guard since the real adapter
        // class is not installed in the dev environment.
        $service = new class(
            $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\UrlDownloader::class),
            $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\FileValidator::class),
            $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\StorageManager::class),
            $this->app->make(\MohamedSamy902\LaravelMediaVault\Contracts\QuotaManagerContract::class),
        ) extends MediaVaultService {
            protected function assertCloudDependenciesInstalled(string $disk): void {}
        };

        $result = $service->upload($file, ['disk' => 's3']);

        Storage::disk('s3')->assertExists($result->path);
        Storage::disk('public')->assertMissing($result->path);
    }


    public function test_upload_to_custom_folder(): void
    {
        $file   = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file, ['folder_name' => 'reports']);

        $this->assertStringContainsString('reports/', $result->path);
        Storage::disk('public')->assertExists($result->path);
    }

    public function test_upload_with_no_folder_uses_base_path_only(): void
    {
        $this->app['config']->set('media-vault.storage.default_folder', null);
        $file   = UploadedFile::fake()->create('flat.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file, ['folder_name' => null]);

        // Path should be uploads/<uuid>.pdf — only 1 slash
        $this->assertEquals(1, substr_count(trim($result->path, '/'), '/'));
    }

    // =========================================================================
    // Upload — File Types & MIME Detection
    // =========================================================================

    public function test_detects_pdf_type_correctly(): void
    {
        $file   = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertEquals('pdf', $result->type);
    }

    public function test_detects_video_type_correctly(): void
    {
        $file   = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:mp4,mov,avi'],
        ]);

        $this->assertEquals('video', $result->type);
    }

    public function test_detects_audio_type_correctly(): void
    {
        $file   = UploadedFile::fake()->create('song.mp3', 200, 'audio/mpeg');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:mp3,wav,ogg'],
        ]);

        $this->assertEquals('audio', $result->type);
    }

    public function test_detects_document_type_correctly(): void
    {
        $file   = UploadedFile::fake()->create(
            'word.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:doc,docx,pdf'],
        ]);

        $this->assertEquals('document', $result->type);
    }

    // =========================================================================
    // Upload — Validation
    // =========================================================================

    public function test_rejects_oversized_file(): void
    {
        $this->expectException(Exception::class);

        $this->app['config']->set('media-vault.validation.custom_fields.file', 'required|file|max:1');
        $file = UploadedFile::fake()->create('big.pdf', 5000, 'application/pdf');

        $this->service->upload($file);
    }

    public function test_accepts_file_with_custom_validation_rules(): void
    {
        $file   = UploadedFile::fake()->create('data.csv', 50, 'text/csv');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:csv,txt'],
        ]);

        $this->assertTrue($result->status);
    }

    public function test_rejects_disallowed_mime_from_custom_rules(): void
    {
        $this->expectException(Exception::class);

        $file = UploadedFile::fake()->create('script.sh', 10, 'application/x-sh');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:jpg,png,pdf'],
        ]);
    }

    // =========================================================================
    // Upload — URL Download
    // =========================================================================

    private function urlTestOptions(array $extra = []): array
    {
        return array_merge([
            'validation_rules' => ['file' => 'required|file|max:51200'],
        ], $extra);
    }

    public function test_downloads_and_uploads_from_url(): void
    {
        Http::fake([
            'https://cdn.example.com/photo.jpg' => Http::response(
                str_repeat('x', 1024), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        // uses urlService
        $result  = $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/photo.jpg']));

        $this->assertInstanceOf(UploadResult::class, $result);
        $this->assertTrue($result->status);
        Storage::disk('public')->assertExists($result->path);
    }

    public function test_url_upload_preserves_original_filename_from_url(): void
    {
        Http::fake([
            'https://cdn.example.com/profile-picture.jpg' => Http::response(
                str_repeat('y', 512), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        // uses urlService
        $result  = $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/profile-picture.jpg']));

        $this->assertEquals('profile-picture.jpg', $result->originalName);
    }

    public function test_url_upload_uses_fallback_filename_when_url_has_none(): void
    {
        Http::fake([
            'https://cdn.example.com/' => Http::response(
                str_repeat('z', 256), 200, ['Content-Type' => 'image/png']
            ),
        ]);

        // uses urlService
        $result  = $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/']));

        $this->assertStringEndsWith('.png', $result->originalName);
    }

    public function test_url_upload_fails_on_404(): void
    {
        $this->expectException(Exception::class);

        Http::fake([
            'https://cdn.example.com/missing.jpg' => Http::response('', 404),
        ]);

        // uses urlService
        $this->urlService->upload([], ['url' => 'https://cdn.example.com/missing.jpg']);
    }

    public function test_url_upload_fails_fast_on_429(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded (HTTP 429) when accessing [https://cdn.example.com/rate-limited.jpg].');

        Http::fake([
            'https://cdn.example.com/rate-limited.jpg' => Http::response('', 429),
        ]);

        // uses urlService
        $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/rate-limited.jpg']));
    }

    public function test_url_upload_fails_when_exceeds_max_size(): void
    {
        $this->expectException(Exception::class);

        $this->app['config']->set('media-vault.url_upload.max_size_bytes', 100);

        Http::fake([
            'https://cdn.example.com/huge.jpg' => Http::response(
                str_repeat('x', 10000), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        // uses urlService
        $this->urlService->upload([], ['url' => 'https://cdn.example.com/huge.jpg']);
    }

    public function test_url_upload_blocks_disallowed_mime_type(): void
    {
        $this->expectException(Exception::class);

        Http::fake([
            'https://cdn.example.com/script.php' => Http::response(
                '<?php echo "hi";', 200, ['Content-Type' => 'application/x-php']
            ),
        ]);

        // uses urlService
        $this->urlService->upload([], ['url' => 'https://cdn.example.com/script.php']);
    }

    public function test_url_upload_handles_content_type_with_charset(): void
    {
        Http::fake([
            'https://cdn.example.com/image.jpg' => Http::response(
                str_repeat('b', 512), 200, ['Content-Type' => 'image/jpeg; charset=binary']
            ),
        ]);

        // uses urlService
        $result  = $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/image.jpg']));

        $this->assertTrue($result->status);
    }

    public function test_multiple_url_uploads(): void
    {
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response(str_repeat('a', 256), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/b.jpg' => Http::response(str_repeat('b', 256), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        // uses urlService
        $results = $this->urlService->upload([], $this->urlTestOptions([
            'url' => [
                'https://cdn.example.com/a.jpg',
                'https://cdn.example.com/b.jpg',
            ],
        ]));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);
        $this->assertTrue($results[1]->status);
    }

    public function test_multiple_url_uploads_continue_on_partial_failure(): void
    {
        Http::fake([
            'https://cdn.example.com/ok.jpg'   => Http::response(str_repeat('c', 256), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/fail.jpg' => Http::response('', 500),
        ]);

        // uses urlService
        $results = $this->urlService->upload([], $this->urlTestOptions([
            'url' => [
                'https://cdn.example.com/ok.jpg',
                'https://cdn.example.com/fail.jpg',
            ],
        ]));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);
        // Failed uploads return plain arrays (not UploadResult)
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
    }

    // =========================================================================
    // Upload — Simple vs Chunked Download Mode
    // =========================================================================

    public function test_simple_download_mode_works(): void
    {
        $this->app['config']->set('media-vault.url_download.chunked', false);

        Http::fake([
            'https://cdn.example.com/photo.jpg' => Http::response(str_repeat('d', 1024), 200, [
                'Content-Type'   => 'image/jpeg',
                'Content-Length' => '1024',
            ]),
        ]);

        // uses urlService
        $result  = $this->urlService->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/photo.jpg']));

        $this->assertTrue($result->status);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function test_deletes_file_by_path_without_database(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        $file   = UploadedFile::fake()->create('todelete.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->service->delete($result->path);

        Storage::disk('public')->assertMissing($result->path);
    }

    public function test_delete_returns_success_status(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        $file         = UploadedFile::fake()->create('todelete2.pdf', 50, 'application/pdf');
        $result       = $this->service->upload($file);
        $deleteResult = $this->service->delete($result->path);

        $this->assertTrue($deleteResult['status']);
        $this->assertArrayHasKey('message', $deleteResult);
    }

    public function test_deletes_multiple_files(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        $paths = [];
        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
            $file    = UploadedFile::fake()->create($name, 50, 'application/pdf');
            $paths[] = $this->service->upload($file)->path;
        }

        $results = $this->service->delete($paths);

        $this->assertCount(3, $results);
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_delete_throws_on_nonexistent_file_without_database(): void
    {
        $this->expectException(Exception::class);
        $this->app['config']->set('media-vault.database.enabled', false);

        $this->service->delete('uploads/nonexistent/ghost.jpg');
    }

    public function test_deletes_thumbnails_even_if_thumbnails_disabled(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);
        $this->app['config']->set('media-vault.thumbnails.enabled', true);

        $file = UploadedFile::fake()->image('test.jpg', 600, 600);
        $result = $this->service->upload($file, ['folder_name' => 'uploads']);
        
        $this->assertTrue($result->status);
        
        // Assert they exist
        Storage::disk('public')->assertExists($result->path);
        
        Storage::disk('public')->assertExists(dirname($result->path) . '/thumb_small_' . basename($result->path));

        // Now disable thumbnails in config
        $this->app['config']->set('media-vault.thumbnails.enabled', false);

        // Delete the file
        $this->service->delete($result->path);

        // Both original and thumbnail should be deleted
        Storage::disk('public')->assertMissing($result->path);
        Storage::disk('public')->assertMissing(dirname($result->path) . '/thumb_small_' . basename($result->path));
    }

    public function test_delete_without_database_requires_string_path(): void
    {
        $this->expectException(Exception::class);
        $this->app['config']->set('media-vault.database.enabled', false);

        $this->service->delete(999); // numeric ID not allowed without DB
    }

    // =========================================================================
    // CDN URL Generation
    // =========================================================================

    public function test_returns_cdn_url_when_cdn_enabled(): void
    {
        $this->app['config']->set('media-vault.storage.cdn.enabled', true);
        $this->app['config']->set('media-vault.storage.cdn.url', 'https://cdn.myapp.com');

        $file   = UploadedFile::fake()->create('asset.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertStringStartsWith('https://cdn.myapp.com/', $result->url);
    }

    public function test_returns_storage_url_when_cdn_disabled(): void
    {
        $this->app['config']->set('media-vault.storage.cdn.enabled', false);

        $file   = UploadedFile::fake()->create('asset2.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertStringNotContainsString('cdn.myapp.com', $result->url);
    }

    // =========================================================================
    // UploadResult — Value Object
    // =========================================================================

    public function test_upload_result_to_array_has_correct_shape(): void
    {
        $file   = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file);

        $arr = $result->toArray();

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

    public function test_upload_result_to_json_is_valid(): void
    {
        $file   = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file);

        $json = $result->toJson();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('path', $decoded);
        $this->assertArrayHasKey('status', $decoded);
    }

    // =========================================================================
    // Invalid Input Handling
    // =========================================================================

    public function test_invalid_direct_file_throws_exception(): void
    {
        $this->expectException(Exception::class);

        $this->service->upload('this-is-not-a-file');
    }

    public function test_invalid_file_in_array_returns_error_entry(): void
    {
        $files = [
            UploadedFile::fake()->create('valid.jpg', 50, 'image/jpeg'),
            'not-a-file',
        ];

        $results = $this->service->upload($files);

        $this->assertInstanceOf(UploadResult::class, $results[0]);
        $this->assertTrue($results[0]->status);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
    }

}
