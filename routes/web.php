<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MohamedSamy902\LaravelMediaVault\Http\Controllers\MediaManagerController;

$prefix     = config('media-vault.ui.route_prefix', 'media-vault');
$middleware = config('media-vault.ui.middleware', ['web']);

if (config('media-vault.ui.require_auth', false)) {
    $middleware = array_values(array_unique([...$middleware, 'auth']));
}

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('media-vault.')
    ->group(function () {
        Route::get('/',              [MediaManagerController::class, 'index'])->name('index');
        Route::get('/media',         [MediaManagerController::class, 'media'])->name('media');
        Route::get('/config',        [MediaManagerController::class, 'config'])->name('config');
        Route::post('/config',       [MediaManagerController::class, 'saveConfig'])->name('config.save');
        Route::get('/sessions',      [MediaManagerController::class, 'sessions'])->name('sessions');
        Route::get('/sessions/{sessionId}/status', [MediaManagerController::class, 'sessionStatus'])->name('sessions.status');

        Route::post('/upload',       [MediaManagerController::class, 'upload'])->name('upload')->middleware('throttle:media-vault-api');

        Route::post('/media/bulk-destroy/preview', [MediaManagerController::class, 'bulkDestroyPreview'])->name('media.bulk-destroy-preview');
        Route::post('/media/bulk-destroy',       [MediaManagerController::class, 'bulkDestroy'])->name('media.bulk-destroy')->middleware(['throttle:10,1', 'throttle:media-vault-api']);
        Route::post('/media/bulk-force-destroy', [MediaManagerController::class, 'bulkForceDestroy'])->name('media.bulk-force-destroy')->middleware(['throttle:10,1', 'throttle:media-vault-api']);

        Route::delete('/media/{encodedPath}',           [MediaManagerController::class, 'destroy'])->name('media.destroy')->middleware('throttle:media-vault-api');
        Route::delete('/media/{encodedPath}/force',     [MediaManagerController::class, 'forceDestroy'])->name('media.force-destroy')->middleware('throttle:media-vault-api');
        Route::post('/media/{encodedPath}/restore',     [MediaManagerController::class, 'restore'])->name('media.restore');
        Route::post('/media/bulk-restore',             [MediaManagerController::class, 'bulkRestore'])->name('media.bulk-restore')->middleware('throttle:media-vault-api');
        Route::post('/scan',                           [MediaManagerController::class, 'scan'])->name('scan');
        Route::get('/scan/status',                     [MediaManagerController::class, 'scanStatus'])->name('scan.status');
    });

$tempPrefix = trim((string) config('media-vault.temp_url.route_prefix', 'media-vault-urls'), '/');
$tempMiddleware = config('media-vault.temp_url.middleware', ['web']);

if (config('media-vault.temp_url.enabled', true)) {
    Route::prefix($tempPrefix)
        ->middleware($tempMiddleware)
        ->group(function () {
            Route::get('/file', \MohamedSamy902\LaravelMediaVault\Http\Controllers\TemporaryUrlController::class)
                ->name('media-vault.temp');
        });
}