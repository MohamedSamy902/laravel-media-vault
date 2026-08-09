<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault;

use Illuminate\Support\ServiceProvider;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaVaultContract;
use MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract;
use MohamedSamy902\LaravelMediaVault\Contracts\QuotaManagerContract;
use MohamedSamy902\LaravelMediaVault\Contracts\SsrfValidatorContract;
use MohamedSamy902\LaravelMediaVault\Security\SsrfValidator;
use MohamedSamy902\LaravelMediaVault\Services\MediaVaultService;
use MohamedSamy902\LaravelMediaVault\Services\FileValidator;
use MohamedSamy902\LaravelMediaVault\Services\ImageProcessor;
use MohamedSamy902\LaravelMediaVault\Services\MimeTypeResolver;
use MohamedSamy902\LaravelMediaVault\Services\QuotaManager;
use MohamedSamy902\LaravelMediaVault\Services\ResumableUploadService;
use MohamedSamy902\LaravelMediaVault\Services\StorageManager;
use MohamedSamy902\LaravelMediaVault\Services\UrlDownloader;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use MohamedSamy902\LaravelMediaVault\Security\VirusScanner;

/**
 * Registers and bootstraps the Advanced File Upload package services.
 *
 * All service bindings use the contract interfaces as keys so that
 * application code can override any implementation by rebinding the
 * contract in the application's own service provider.
 */
class LaravelMediaVaultServiceProvider extends ServiceProvider
{
    /**
     * Registers all package bindings into the service container.
     *
     * Bindings are declared in dependency order: low-level utilities first,
     * then services that depend on them, then the top-level orchestrator.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media-vault.php', 'media-vault');

        $this->registerInfrastructure();
        $this->registerServices();
        $this->registerOrchestrator();
    }

    /**
     * Publishes package assets and loads view/route paths.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        // Config is always publishable
        $this->publishes([
            __DIR__ . '/../config/media-vault.php' => config_path('media-vault.php'),
        ], 'media-vault-config');

        // Assets are always publishable (not gated by runningInConsole)
        $this->publishes([
            __DIR__ . '/../resources/js/media-vault.js'   => public_path('vendor/media-vault/media-vault.js'),
            __DIR__ . '/../resources/css/media-vault.css' => public_path('vendor/media-vault/media-vault.css'),
        ], 'assets');

        // Views are always publishable
        $this->publishes([
            __DIR__ . '/../resources/views/media-vault' =>
                resource_path('views/vendor/media-vault'),
        ], 'views');

        if ($this->app->runningInConsole()) {
            $this->publishMigrations();
            $this->commands([
                \MohamedSamy902\LaravelMediaVault\Console\Commands\PruneUnusedFiles::class,
                \MohamedSamy902\LaravelMediaVault\Console\Commands\PruneExpiredSessions::class,
                \MohamedSamy902\LaravelMediaVault\Console\Commands\ScanFilesCommand::class,
                \MohamedSamy902\LaravelMediaVault\Console\Commands\DiscoverModelsCommand::class,
                \MohamedSamy902\LaravelMediaVault\Console\Commands\RegenerateThumbnails::class,
                \MohamedSamy902\LaravelMediaVault\Console\Commands\ImportOrphans::class,
            ]);
        }

        $this->loadViewsFrom(
            __DIR__ . '/../resources/views/media-vault',
            'media-vault'
        );

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load the dashboard UI routes
        if (file_exists(__DIR__ . '/../routes/web.php')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }
    }

    /**
     * Registers low-level infrastructure: SSRF validator, MIME resolver, image processor.
     *
     * These are stateless utilities with no dependencies on other package services.
     * They are registered as singletons to avoid redundant instantiation.
     */
    private function registerInfrastructure(): void
    {
        $this->app->singleton(\MohamedSamy902\LaravelMediaVault\Services\MediaSourceManager::class);

        $this->app->singleton(MimeTypeResolver::class);

        $this->app->singleton(SsrfValidatorContract::class, SsrfValidator::class);

        $this->app->singleton(ImageProcessorContract::class, fn () => ImageProcessor::fromConfig());
    }

    /**
     * Registers mid-level services: file validator, URL downloader, storage manager, quota manager.
     *
     * Each service depends only on infrastructure bindings registered above.
     */
    private function registerServices(): void
    {
        $this->app->singleton(\MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract::class, function ($app) {
            if (config('media-vault.database.enabled', true)) {
                return $app->make(\MohamedSamy902\LaravelMediaVault\Repositories\MultiSourceFileRepository::class);
            }
            return $app->make(\MohamedSamy902\LaravelMediaVault\Repositories\DiskFileRepository::class);
        });

        $this->app->singleton(FileValidator::class);

        $this->app->singleton(UrlDownloader::class, fn ($app) => new UrlDownloader(
            ssrfValidator: $app->make(SsrfValidatorContract::class),
            fileValidator: $app->make(FileValidator::class),
            mimeResolver:  $app->make(MimeTypeResolver::class),
        ));

        $this->app->singleton(StorageManager::class, fn ($app) => new StorageManager(
            imageProcessor: $app->make(ImageProcessorContract::class),
            mimeResolver:   $app->make(MimeTypeResolver::class),
        ));

        $this->app->singleton(QuotaManagerContract::class, QuotaManager::class);
        $this->app->singleton(VirusScanner::class);

        $this->app->singleton(ResumableUploadService::class, fn ($app) => new ResumableUploadService(
            storageManager: $app->make(StorageManager::class),
            fileValidator:  $app->make(FileValidator::class),
        ));
    }

    /**
     * Registers the top-level MediaVaultService orchestrator and its contract alias.
     *
     * The service is bound as a singleton since all its dependencies are
     * themselves singletons and it holds no mutable state.
     */
    private function registerOrchestrator(): void
    {
        $this->app->singleton(MediaVaultService::class, fn ($app) => new MediaVaultService(
            urlDownloader: $app->make(UrlDownloader::class),
            fileValidator: $app->make(FileValidator::class),
            storageManager: $app->make(StorageManager::class),
            quotaManager:  $app->make(QuotaManagerContract::class),
            virusScanner:  $app->make(VirusScanner::class),
        ));

        $this->app->bind(MediaVaultContract::class, MediaVaultService::class);
    }

    /**
     * Configures the rate limiter for package API endpoints.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('media-vault-api', function (Request $request) {
            $config = config('media-vault.security.rate_limit', []);

            if (!($config['enabled'] ?? false)) {
                return Limit::none();
            }

            $maxUploads = (int) ($config['max_uploads'] ?? 60);
            $perMinutes = (int) ($config['per_minutes'] ?? 1);

            return Limit::perMinutes($perMinutes, $maxUploads)
                ->by($request->ip() ?: 'anonymous')
                ->response(function () {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Too Many Requests. Rate limit exceeded for file operations.',
                    ], 429);
                });
        });
    }

    /**
     * Publishes the package migration files to the application's migrations directory.
     *
     * Uses a sequenced suffix (_01, _02) to guarantee correct execution order
     * even when both migrations share the same timestamp prefix.
     */
    private function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His', time());

        $this->publishes([
            __DIR__ . '/../database/migrations/create_file_uploads_table.php' =>
                database_path("migrations/{$timestamp}_01_create_file_uploads_table.php"),
            __DIR__ . '/../database/migrations/create_upload_sessions_table.php' =>
                database_path("migrations/{$timestamp}_02_create_upload_sessions_table.php"),
        ], 'media-vault-migrations');
    }
}
