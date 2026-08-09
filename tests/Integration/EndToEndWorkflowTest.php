<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Integration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload as MediaVaultModel;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        
        // Fully enable everything to simulate a real-world scenario
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.processing.image.enabled', true);
        $app['config']->set('media-vault.thumbnails.enabled', true);
    }
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // This simulates a fresh user installing the package and running migrations
        $this->artisan('migrate')->assertSuccessful();
    }

    public function test_full_upload_and_delete_workflow(): void
    {
        Storage::fake('public');

        // 1. Upload a file
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $result = MediaVault::upload($file);

        // 2. Assert Result Structure
        $this->assertTrue($result->status);
        $this->assertEquals('avatar.jpg', $result->originalName);
        $this->assertNotNull($result->id);
        $this->assertEquals('public', $result->disk);

        // 3. Assert Database Record
        $record = MediaVaultModel::find($result->id);
        $this->assertNotNull($record, 'Database record was not created.');
        $this->assertEquals($result->path, $record->path);

        // 4. Assert Physical File Exists
        Storage::disk('public')->assertExists($result->path);
        
        // 5. Delete the file
        $deleteResult = MediaVault::delete($result->id);
        
        $this->assertTrue($deleteResult['status']);

        // 6. Assert Database Record Removed
        $this->assertNull(MediaVaultModel::find($result->id), 'Database record was not deleted.');

        // 7. Assert Physical File Removed
        Storage::disk('public')->assertMissing($result->path);
    }
}
