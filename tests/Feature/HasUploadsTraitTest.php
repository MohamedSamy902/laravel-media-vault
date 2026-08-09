<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;
use MohamedSamy902\LaravelMediaVault\Traits\HasUploads;

class DummyModelWithUploads extends Model
{
    use HasUploads;

    protected $table = 'dummy_models';
    protected $guarded = [];
}

class HasUploadsTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->run();

        Schema::create('dummy_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_model_can_retrieve_attached_uploads()
    {
        $dummy = DummyModelWithUploads::create(['name' => 'Test Dummy']);

        $upload = FileUpload::create([
            'name' => 'dummy',
            'path' => 'dummy.jpg',
            'original_name' => 'dummy.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1024,
            'is_used' => true,
            'model_type' => get_class($dummy),
            'model_id' => $dummy->id,
            'metadata' => [
                'thumbnails' => [
                    'small' => '/storage/dummy_small.jpg',
                    'medium' => '/storage/dummy_medium.jpg',
                ]
            ]
        ]);

        $this->assertCount(1, $dummy->uploads);
        $this->assertEquals($upload->id, $dummy->uploads->first()->id);
    }

    public function test_model_can_get_thumbnail_url_via_trait()
    {
        $dummy = DummyModelWithUploads::create(['name' => 'Test Dummy']);

        $upload = FileUpload::create([
            'name' => 'dummy',
            'path' => 'dummy.jpg',
            'original_name' => 'dummy.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1024,
            'is_used' => true,
            'model_type' => get_class($dummy),
            'model_id' => $dummy->id,
            'metadata' => [
                'thumbnails' => [
                    'small' => '/storage/dummy_small.jpg',
                ]
            ]
        ]);

        $this->assertEquals('/storage/dummy_small.jpg', $dummy->getMediaUrl('image', 'small'));
        $this->assertEquals($upload->url, $dummy->getMediaUrl('image', 'original'));
    }

    public function test_model_returns_null_when_no_uploads_exist()
    {
        $dummy = DummyModelWithUploads::create(['name' => 'Test Dummy']);
        $this->assertNull($dummy->getMediaUrl('image'));
    }
}
