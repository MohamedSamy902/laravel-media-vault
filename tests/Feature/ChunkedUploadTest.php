<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class ChunkedUploadTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
    }
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->assertSuccessful();
    }

    public function test_can_upload_file_in_chunks(): void
    {
        Storage::fake('public');

        // Create a fake file and chunk it
        $file = UploadedFile::fake()->create('test-video.mp4', 3000); // 3MB
        $fileContent = file_get_contents($file->getRealPath());

        // Split into 2 chunks
        $chunk1 = substr($fileContent, 0, 1500 * 1024);
        $chunk2 = substr($fileContent, 1500 * 1024);

        $tmp1 = tmpfile();
        fwrite($tmp1, $chunk1);
        $meta1 = stream_get_meta_data($tmp1);
        $uploadedChunk1 = new UploadedFile($meta1['uri'], 'test-video.mp4', 'video/mp4', null, true);

        // Upload chunk 1 (this should start the session)
        // In reality, this requires route testing. But we don't have a built-in upload route in the package!
        // The package expects the USER to create their own route that calls FileUpload::upload($request).
        // So we will call MediaVaultService->upload($request) directly but simulating a Request.
        
        $request1 = \Illuminate\Http\Request::create('/upload', 'POST', [
            'chunkNumber' => 1,
            'totalChunks' => 2,
            'originalName' => 'test-video.mp4',
        ], [], [
            'file' => $uploadedChunk1
        ]);

        $service = $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\MediaVaultService::class);
        $response1 = $service->upload($request1);

        // response1 should be an array because chunk is not finished
        $this->assertIsArray($response1);
        
        $this->assertTrue($response1['status']);
        $this->assertArrayHasKey('sessionId', $response1);
        $sessionId = $response1['sessionId'];

        // Upload chunk 2
        $tmp2 = tmpfile();
        fwrite($tmp2, $chunk2);
        $meta2 = stream_get_meta_data($tmp2);
        $uploadedChunk2 = new UploadedFile($meta2['uri'], 'test-video.mp4', 'video/mp4', null, true);

        $request2 = \Illuminate\Http\Request::create('/upload', 'POST', [
            'chunkNumber' => 2,
            'totalChunks' => 2,
            'originalName' => 'test-video.mp4',
            'sessionId' => $sessionId,
        ], [], [
            'file' => $uploadedChunk2
        ]);

        $response2 = $service->upload($request2, ['validation_rules' => ['file' => 'required']]);

        // response2 should be UploadResult since it's finished
        $this->assertInstanceOf(\MohamedSamy902\LaravelMediaVault\ValueObjects\UploadResult::class, $response2);
        $this->assertEquals('test-video.mp4', $response2->originalName);
        
        // Assert session is complete in DB
        $session = UploadSession::where('session_id', $sessionId)->first();
        $this->assertEquals('complete', $session->status);
    }

    public function test_chunked_upload_throws_exception_when_database_disabled(): void
    {
        config(['media-vault.database.enabled' => false]);

        $file = UploadedFile::fake()->create('test.mp4', 100);
        $request = \Illuminate\Http\Request::create('/upload', 'POST', [
            'chunkNumber' => 1,
            'totalChunks' => 2,
            'originalName' => 'test.mp4',
        ], [], ['file' => $file]);

        $service = $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\MediaVaultService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resumable chunked uploads require database tracking to be enabled (media-vault.database.enabled = true).');

        $service->upload($request);
    }
}
