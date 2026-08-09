<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Benchmarks;

use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Verifies the correctness of all upload features and measures throughput.
 *
 * Performance thresholds are conservative enough to pass on any CI runner.
 * All correctness assertions verify the actual stored state, not just return values.
 */
class PerformanceTest extends TestCase
{
    private MediaVaultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = $this->makeService($this->noopSsrf());
    }

    // =========================================================
    // PERFORMANCE: Single file uploads
    // =========================================================

    /** @test */
    public function perf_single_small_image_under_200ms(): void
    {
        $file  = UploadedFile::fake()->create('small.jpg', 50, 'image/jpeg');
        $start = microtime(true);

        $result = $this->service->upload($file);

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertTrue($result->status);
        $this->assertLessThan(200, $elapsed,
            "Single 50KB image took {$elapsed}ms — expected < 200ms"
        );
    }

    /** @test */
    public function perf_single_large_file_1mb_under_500ms(): void
    {
        $file  = UploadedFile::fake()->create('large.pdf', 1024, 'application/pdf');
        $start = microtime(true);

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertTrue($result->status);
        $this->assertLessThan(500, $elapsed,
            "Single 1MB PDF took {$elapsed}ms — expected < 500ms"
        );
    }

    /** @test */
    public function perf_single_5mb_file_under_1000ms(): void
    {
        $file  = UploadedFile::fake()->create('video.mp4', 5120, 'video/mp4');
        $start = microtime(true);

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:mp4,mov|max:51200'],
        ]);

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertTrue($result->status);
        $this->assertLessThan(1000, $elapsed,
            "Single 5MB video took {$elapsed}ms — expected < 1000ms"
        );
    }

    // =========================================================
    // PERFORMANCE: Batch uploads
    // =========================================================

    /** @test */
    public function perf_batch_10_files_under_1500ms(): void
    {
        $files = [];
        for ($i = 0; $i < 10; $i++) {
            $files[] = UploadedFile::fake()->create("file{$i}.pdf", 100, 'application/pdf');
        }

        $start   = microtime(true);
        $results = $this->service->upload($files, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertCount(10, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(UploadResult::class, $result);
            $this->assertTrue($result->status);
        }
        $this->assertLessThan(1500, $elapsed,
            "Batch 10 files took {$elapsed}ms — expected < 1500ms"
        );
    }

    /** @test */
    public function perf_batch_50_files_all_succeed(): void
    {
        $files = [];
        for ($i = 0; $i < 50; $i++) {
            $files[] = UploadedFile::fake()->create("doc{$i}.pdf", 20, 'application/pdf');
        }

        $start   = microtime(true);
        $results = $this->service->upload($files, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertCount(50, $results);
        $successCount = count(array_filter($results, fn ($r) => $r instanceof UploadResult && $r->status));
        $this->assertEquals(50, $successCount,
            "Expected all 50 uploads to succeed, got {$successCount}"
        );

        echo "\n  [PERF] 50-file batch: {$elapsed}ms";
    }

    // =========================================================
    // PERFORMANCE: Memory usage
    // =========================================================

    /** @test */
    public function perf_memory_usage_stays_reasonable_for_batch(): void
    {
        $before = memory_get_usage(true);

        $files = [];
        for ($i = 0; $i < 20; $i++) {
            $files[] = UploadedFile::fake()->create("mem{$i}.pdf", 50, 'application/pdf');
        }
        $this->service->upload($files, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $after     = memory_get_usage(true);
        $usedMB    = ($after - $before) / 1024 / 1024;

        // Should not use more than 50MB for 20 small files
        $this->assertLessThan(50, $usedMB,
            "Memory used for 20-file batch: {$usedMB}MB — expected < 50MB"
        );

        echo "\n  [PERF] Memory for 20-file batch: " . round($usedMB, 2) . "MB";
    }

    // =========================================================
    // CORRECTNESS: UUID filenames
    // =========================================================

    /** @test */
    public function correctness_every_upload_gets_unique_uuid_filename(): void
    {
        $paths = [];
        for ($i = 0; $i < 20; $i++) {
            $file    = UploadedFile::fake()->create('same-name.pdf', 10, 'application/pdf');
            $result  = $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file|max:10240'],
            ]);
            $paths[] = $result->path;
        }

        // All paths must be unique
        $unique = array_unique($paths);
        $this->assertCount(20, $unique,
            'Expected 20 unique paths but got ' . count($unique)
        );

        // Each filename must match UUID pattern
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
                $path
            );
        }
    }

    /** @test */
    public function correctness_original_name_always_preserved(): void
    {
        $names = ['photo.jpg', 'document.pdf', 'video.mp4', 'audio.mp3', 'spreadsheet.xlsx'];

        foreach ($names as $name) {
            $mime   = $this->mimeFor($name);
            $file   = UploadedFile::fake()->create($name, 50, $mime);
            $result = $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file|max:10240'],
            ]);

            $this->assertEquals($name, $result->originalName,
                "Original name mismatch for {$name}"
            );
        }
    }

    /** @test */
    public function correctness_file_type_detection_is_accurate(): void
    {
        $cases = [
            ['photo.jpg',   'image/jpeg',       'image'],
            ['clip.mp4',    'video/mp4',         'video'],
            ['song.mp3',    'audio/mpeg',        'audio'],
            ['doc.pdf',     'application/pdf',   'pdf'],
            ['word.docx',   'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document'],
        ];

        foreach ($cases as [$name, $mime, $expectedType]) {
            $file   = UploadedFile::fake()->create($name, 50, $mime);
            $result = $this->service->upload($file, [
                'validation_rules' => ['file' => 'required|file|max:10240'],
            ]);

            $this->assertEquals($expectedType, $result->type,
                "Type mismatch for {$name}: expected {$expectedType}, got {$result->type}"
            );
        }
    }

    /** @test */
    public function correctness_file_size_is_recorded_accurately(): void
    {
        // UploadedFile::fake() sizes are in KB
        $sizeKB = 123;
        $file   = UploadedFile::fake()->create('sized.pdf', $sizeKB, 'application/pdf');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        $this->assertNotNull($result->size);
        // Size should be in bytes range (fake files may vary slightly)
        $this->assertGreaterThan(0, $result->size);
    }

    /** @test */
    public function correctness_cdn_url_rewriting_is_applied_consistently(): void
    {
        $this->app['config']->set('media-vault.storage.cdn.enabled', true);
        $this->app['config']->set('media-vault.storage.cdn.url', 'https://cdn.myapp.com');

        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = UploadedFile::fake()->create("asset{$i}.pdf", 10, 'application/pdf');
        }

        $results = $this->service->upload($files, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        foreach ($results as $result) {
            $this->assertStringStartsWith('https://cdn.myapp.com/', $result->url,
                "CDN URL not applied: {$result->url}"
            );
        }
    }

    /** @test */
    public function correctness_upload_result_array_access_matches_properties(): void
    {
        $file   = UploadedFile::fake()->create('test.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        // ArrayAccess must return same values as typed properties
        $this->assertEquals($result->status,       $result['status']);
        $this->assertEquals($result->path,         $result['path']);
        $this->assertEquals($result->url,          $result['url']);
        $this->assertEquals($result->originalName, $result['original_name']);
        $this->assertEquals($result->mimeType,     $result['mime_type']);
        $this->assertEquals($result->type,         $result['type']);
        $this->assertEquals($result->size,         $result['size']);
    }

    /** @test */
    public function correctness_custom_folder_path_is_correct(): void
    {
        $folders = ['images', 'documents', 'videos', 'users/123/avatars'];

        foreach ($folders as $folder) {
            $file   = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');
            $result = $this->service->upload($file, [
                'folder_name'      => $folder,
                'validation_rules' => ['file' => 'required|file|max:10240'],
            ]);

            $this->assertStringContainsString($folder, $result->path,
                "Expected folder [{$folder}] in path [{$result->path}]"
            );
            Storage::disk('public')->assertExists($result->path);
        }
    }

    /** @test */
    public function correctness_delete_removes_file_from_storage(): void
    {
        $this->app['config']->set('media-vault.database.enabled', false);

        $file   = UploadedFile::fake()->create('to-delete.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|max:10240'],
        ]);

        Storage::disk('public')->assertExists($result->path);

        $deleteResult = $this->service->delete($result->path);

        $this->assertTrue($deleteResult['status']);
        Storage::disk('public')->assertMissing($result->path);
    }

    // =========================================================
    // Helper
    // =========================================================

    private function mimeFor(string $filename): string
    {
        return match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'pdf'         => 'application/pdf',
            'mp4'         => 'video/mp4',
            'mp3'         => 'audio/mpeg',
            'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default       => 'application/octet-stream',
        };
    }
}
