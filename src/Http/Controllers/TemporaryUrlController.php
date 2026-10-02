<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemporaryUrlController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $path = base64_decode((string) $request->query('path', ''), true);
        $disk = (string) $request->query('disk', config('media-vault.storage.disk', 'public'));
        $allowedDisks = array_unique(array_filter([
            (string) config('media-vault.storage.disk', 'public'),
            'public',
            'local',
            's3',
        ]));

        if ($path === false || $path === '' || str_contains($path, '..')) {
            abort(404);
        }

        if (!in_array($disk, $allowedDisks, true)) {
            abort(403);
        }

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $filesystem */
        $filesystem = Storage::disk($disk);

        return $filesystem->response($path);
    }
}
