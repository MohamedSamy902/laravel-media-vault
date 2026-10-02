<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Support\TrashPath;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class TrashLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-vault.database.enabled', true);
        $app['config']->set('media-vault.processing.image.enabled', false);
        $app['config']->set('media-vault.thumbnails.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function test_soft_delete_hides_file_from_library_and_shows_in_trash(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $repo = $this->app->make(FileRepositoryContract::class);

        $this->assertTrue($repo->delete($upload->path, false));

        $library = $repo->getFilteredFiles(['filter' => 'all'], 50);
        $trash = $repo->getFilteredFiles(['filter' => 'deleted'], 50);

        $libraryPaths = collect($library->items())->pluck('path')->all();
        $trashPaths = collect($trash->items())->pluck('path')->all();

        $this->assertNotContains($upload->path, $libraryPaths);
        $this->assertContains($upload->path, $trashPaths);
        $this->assertTrue(collect($trash->items())->firstWhere('path', $upload->path)->trashed());
    }

    public function test_soft_delete_moves_physical_file_to_trash_path(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $repo = $this->app->make(FileRepositoryContract::class);

        $repo->delete($upload->path, false);

        Storage::disk('public')->assertMissing($upload->path);
        Storage::disk('public')->assertExists(TrashPath::toTrash($upload->path));

        $this->assertSoftDeleted('file_uploads', ['id' => $upload->id, 'path' => $upload->path]);
    }

    public function test_restore_returns_db_record_and_physical_file(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $repo = $this->app->make(FileRepositoryContract::class);

        $repo->delete($upload->path, false);
        $this->assertTrue($repo->restore($upload->path));

        $this->assertDatabaseHas('file_uploads', ['id' => $upload->id, 'deleted_at' => null]);
        Storage::disk('public')->assertExists($upload->path);
        Storage::disk('public')->assertMissing(TrashPath::toTrash($upload->path));

        $library = $repo->getFilteredFiles(['filter' => 'all'], 50);
        $this->assertContains($upload->path, collect($library->items())->pluck('path')->all());
    }

    public function test_hard_delete_removes_db_and_disk_permanently(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $repo = $this->app->make(FileRepositoryContract::class);

        $repo->delete($upload->path, false);
        $this->assertTrue($repo->delete($upload->path, true));

        $this->assertDatabaseMissing('file_uploads', ['id' => $upload->id]);
        Storage::disk('public')->assertMissing($upload->path);
        Storage::disk('public')->assertMissing(TrashPath::toTrash($upload->path));
    }

    public function test_facade_trash_restore_force_delete_parity(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();

        $trashResult = MediaVault::trash($upload->path);
        $this->assertTrue($trashResult['status']);
        Storage::disk('public')->assertExists(TrashPath::toTrash($upload->path));
        $this->assertSoftDeleted('file_uploads', ['id' => $upload->id]);

        $restoreResult = MediaVault::restore($upload->path);
        $this->assertTrue($restoreResult['status']);
        Storage::disk('public')->assertExists($upload->path);

        $forceResult = MediaVault::forceDelete($upload->path);
        $this->assertTrue($forceResult['status']);
        $this->assertDatabaseMissing('file_uploads', ['id' => $upload->id]);
        Storage::disk('public')->assertMissing($upload->path);
    }

    public function test_orphaned_scan_skips_trashed_physical_files(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $repo = $this->app->make(FileRepositoryContract::class);
        $repo->delete($upload->path, false);

        // Drop the soft-deleted DB row so only the trash bytes remain on disk.
        FileUpload::withTrashed()->where('id', $upload->id)->forceDelete();

        $orphans = $repo->getOrphanedFiles(50);
        $orphanPaths = collect($orphans->items())->pluck('path')->all();

        $this->assertNotContains(TrashPath::toTrash($upload->path), $orphanPaths);
        $this->assertNotContains($upload->path, $orphanPaths);
    }

    public function test_dashboard_soft_delete_and_restore_endpoints(): void
    {
        Storage::fake('public');

        $upload = $this->storeSampleFile();
        $encoded = base64_encode($upload->path);

        $this->deleteJson(route('media-vault.media.destroy', ['encodedPath' => $encoded]))
            ->assertOk()
            ->assertJson(['status' => true]);

        $library = $this->get(route('media-vault.media', ['filter' => 'all']));
        $library->assertOk();
        $libraryPaths = collect($library->viewData('files')->items())->pluck('path')->all();
        $this->assertNotContains($upload->path, $libraryPaths);

        $trash = $this->get(route('media-vault.media', ['filter' => 'deleted']));
        $trash->assertOk();
        $trashItems = collect($trash->viewData('files')->items());
        $this->assertTrue($trashItems->contains(fn ($file) => $file->path === $upload->path && $file->trashed()));

        $this->postJson(route('media-vault.media.restore', ['encodedPath' => $encoded]))
            ->assertOk()
            ->assertJson(['status' => true]);

        $this->assertDatabaseHas('file_uploads', ['id' => $upload->id, 'deleted_at' => null]);
        Storage::disk('public')->assertExists($upload->path);
    }

    public function test_restores_from_legacy_flat_trash_layout(): void
    {
        Storage::fake('public');

        $logical = 'uploads/default/legacy.pdf';
        Storage::disk('public')->put('uploads/.trash/legacy.pdf', 'legacy-bytes');

        $upload = FileUpload::create([
            'original_name' => 'legacy.pdf',
            'name' => 'legacy.pdf',
            'path' => $logical,
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 12,
            'is_used' => false,
        ]);
        $upload->delete();

        $repo = $this->app->make(FileRepositoryContract::class);
        $this->assertTrue($repo->restore($logical));

        Storage::disk('public')->assertExists($logical);
        Storage::disk('public')->assertMissing('uploads/.trash/legacy.pdf');
        $this->assertDatabaseHas('file_uploads', ['id' => $upload->id, 'deleted_at' => null]);
    }

    private function storeSampleFile(): FileUpload
    {
        Storage::disk('public')->put('uploads/default/photo.pdf', 'dummy-bytes');

        return FileUpload::create([
            'original_name' => 'photo.pdf',
            'name' => 'photo.pdf',
            'path' => 'uploads/default/photo.pdf',
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 11,
            'is_used' => false,
            'metadata' => null,
        ]);
    }
}
