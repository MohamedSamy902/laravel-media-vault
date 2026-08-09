<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Benchmarks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class OverlapCustomSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        \Illuminate\Support\Facades\Schema::create('test_users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
        });
        
        \Illuminate\Support\Facades\Schema::create('test_products', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->json('gallery')->nullable();
        });
    }
    
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.media_models', [
            MultiSourceTestUser2::class => (new MultiSourceTestUser2)->getMediaFieldsDefinition(),
            MultiSourceTestProduct2::class => (new MultiSourceTestProduct2)->getMediaFieldsDefinition(),
        ]);
    }
    
    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->app->make(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class)->loadModels();
    }

    public function test_deleting_shared_file_does_not_delete_physical_file_if_used_elsewhere()
    {
        $sharedPath = 'shared/image.png';
        Storage::disk('public')->put($sharedPath, 'content');

        // Shared between Central and Custom! This is the most realistic overlap scenario!
        // We will mock it being in Central DB (but not deleted yet).
        // Wait, if it's in Central, repo->delete() will delete it from Central too!
        // Because repo->delete() iterates ALL sources.
        
        // Instead, let's manually call CustomMediaSource->delete() to simulate deleting from JUST the custom source!
        $user = MultiSourceTestUser2::create([
            'name' => 'John',
            'avatar' => $sharedPath,
        ]);
        
        $product = MultiSourceTestProduct2::create([
            'name' => 'Prod',
            'gallery' => json_encode([$sharedPath])
        ]);

        $customSource = new \MohamedSamy902\LaravelMediaVault\Sources\CustomMediaSource(MultiSourceTestUser2::class, (new MultiSourceTestUser2)->getMediaFieldsDefinition());
        $customSource->delete($sharedPath, true);

        // It should detach from User, but NOT delete the physical file because it's used in Product!
        $user->refresh();
        $product->refresh();
        
        $this->assertNull($user->avatar);
        $this->assertEquals([$sharedPath], json_decode($product->gallery, true));
        $this->assertTrue(Storage::disk('public')->exists($sharedPath));
    }

    public function test_deleting_unshared_file_deletes_physical_file()
    {
        $uniquePath = 'unique/image.png';
        Storage::disk('public')->put($uniquePath, 'content');

        $user = MultiSourceTestUser2::create([
            'name' => 'John',
            'avatar' => $uniquePath,
        ]);

        $repo = clone $this->app->make(FileRepositoryContract::class);
        $repo->delete($uniquePath, true);

        $user->refresh();
        $this->assertNull($user->avatar);
        $this->assertFalse(Storage::disk('public')->exists($uniquePath));
    }
}

class MultiSourceTestUser2 extends \Illuminate\Database\Eloquent\Model
{
    use \MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;
    protected $table = 'test_users';
    protected $guarded = [];
    public $timestamps = false;
    protected array $mediaFields = ['avatar' => ['disk' => 'public']];
}

class MultiSourceTestProduct2 extends \Illuminate\Database\Eloquent\Model
{
    use \MohamedSamy902\LaravelMediaVault\Traits\HasMediaFields;
    protected $table = 'test_products';
    protected $guarded = [];
    public $timestamps = false;
    protected array $mediaFields = ['gallery' => ['disk' => 'public', 'multiple' => true]];
}
