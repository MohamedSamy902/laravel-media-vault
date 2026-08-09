<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use MohamedSamy902\LaravelMediaVault\Tests\TestCase;

class MigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // Use sqlite in-memory for testing
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        $app['config']->set('media-vault.database.enabled', true);
    }

    public function test_migrations_run_automatically_when_loaded(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        
        $this->assertTrue(Schema::hasTable('file_uploads'), 'file_uploads table should exist automatically.');
        $this->assertTrue(Schema::hasTable('upload_sessions'), 'upload_sessions table should exist automatically.');
    }
}
