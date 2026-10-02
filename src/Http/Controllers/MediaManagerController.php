<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Contracts\MediaVaultContract;
use MohamedSamy902\LaravelMediaVault\Jobs\ScanOrphansJob;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
use MohamedSamy902\LaravelMediaVault\Security\BulkDeletionGuard;
use MohamedSamy902\LaravelMediaVault\Services\ResumableUploadService;
use MohamedSamy902\LaravelMediaVault\Support\FileAuthorization;
use MohamedSamy902\LaravelMediaVault\Support\FileCategories;

class MediaManagerController extends Controller
{
    public function __construct(
        protected FileRepositoryContract $repository,
        protected FileAuthorization $authorization,
    ) {
    }

    /**
     * Dashboard overview with stats.
     */
    public function index(): \Illuminate\Contracts\View\View
    {
        $config = config('media-vault');
        $dbEnabled = $config['database']['enabled'] ?? false;

        $stats = $this->repository->getStats();
        $stats['db_enabled'] = $dbEnabled;
        $stats['disk'] = $config['storage']['disk'] ?? 'public';
        $stats['disk_total'] = null;
        $stats['disk_used'] = null;

        if ($dbEnabled) {
            $stats['active_sessions'] = UploadSession::whereIn('status', ['pending', 'assembling'])->count();
            $stats['failed_sessions'] = UploadSession::where('status', 'failed')->count();
        } else {
            $stats['active_sessions'] = 0;
            $stats['failed_sessions'] = 0;
        }

        return view('media-vault::dashboard.index', compact('stats', 'config'));
    }

    /**
     * Media library with filtering.
     */
    public function media(Request $request): \Illuminate\Contracts\View\View
    {
        $config    = config('media-vault');
        $dbEnabled = $config['database']['enabled'] ?? false;

        $filter = $request->input('filter', 'all');
        $disk   = $request->input('disk', $config['storage']['disk'] ?? 'public');
        $search = $request->input('search');

        $filters = [
            'filter' => $filter,
            'disk' => $disk,
            'search' => $search,
            'model_type' => $request->input('model_type'),
        ];

        if ($filter === 'unused') {
            $files = $this->repository->getOrphanedFiles(24);
        } elseif ($filter === 'duplicates') {
            $duplicateGroups = $this->repository->getDuplicateFiles();
            $flat = [];
            foreach ($duplicateGroups as $group) {
                foreach ($group as $file) {
                    $flat[] = $file;
                }
            }
            $page = $request->input('page', 1);
            $perPage = 24;
            $items = array_slice($flat, ($page - 1) * $perPage, $perPage);
            $files = new LengthAwarePaginator($items, count($flat), $perPage, $page, ['path' => request()->url()]);
        } else {
            $files = $this->repository->getFilteredFiles($filters, 24);
        }

        $files->through(function ($file) {
            $file->encoded_path = base64_encode($file->path);

            $basename = basename($file->path);
            if (preg_match('/^thumb_([^_]+)_(.+)$/', $basename, $matches)) {
                $meta = $file->metadata ?? [];
                $meta['is_thumbnail_guess'] = true;
                $meta['guessed_size'] = $matches[1];
                $meta['guessed_parent'] = $matches[2];
                $file->metadata = $meta;
            }

            return $file;
        });

        $disks = array_keys(config('filesystems.disks', []));
        $models = [];
        $stats = $this->repository->getStats($filters);

        $stats['size'] = FileCategories::formatBytes((int) ($stats['total_size'] ?? 0));
        $stats['total'] = $stats['total_files'] ?? 0;
        $stats['used'] = $stats['used_files'] ?? 0;
        $stats['unused'] = $stats['unused_files'] ?? 0;

        $modelFilter = null;

        return view('media-vault::dashboard.media', compact(
            'files', 'filter', 'disk', 'search', 'disks', 'dbEnabled', 'models', 'modelFilter', 'stats'
        ));
    }

    public function config(): \Illuminate\Contracts\View\View
    {
        $config = config('media-vault');
        $configPath = config_path('media-vault.php');
        $isPublished = file_exists($configPath);

        return view('media-vault::dashboard.config', compact('config', 'configPath', 'isPublished'));
    }

    public function saveConfig(Request $request): RedirectResponse
    {
        return back()->with(
            'warning',
            'Dashboard configuration is read-only. Update config/media-vault.php or your .env file directly.'
        );
    }

    public function sessions(Request $request): \Illuminate\Contracts\View\View
    {
        $status = $request->input('status', 'all');

        if (!config('media-vault.database.enabled', true)) {
            $sessions = new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]);

            return view('media-vault::dashboard.sessions', compact('sessions', 'status'));
        }

        $query = UploadSession::latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $sessions = $query->paginate(20)->withQueryString();

        return view('media-vault::dashboard.sessions', compact('sessions', 'status'));
    }

    public function destroy(string $encodedPath): JsonResponse
    {
        $path = base64_decode((string) $encodedPath);

        if ($this->authorization->isUnauthorizedToModify($path)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
        }

        $deleted = $this->repository->delete($path, false);

        return response()->json(['status' => $deleted, 'message' => $deleted ? 'File moved to trash.' : 'Failed to delete file.']);
    }

    public function forceDestroy(string $encodedPath): JsonResponse
    {
        $path = base64_decode((string) $encodedPath);

        if ($this->authorization->isUnauthorizedToModify($path)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
        }

        $deleted = $this->repository->delete($path, true);

        return response()->json([
            'status' => $deleted,
            'message' => $deleted
                ? 'File permanently deleted from the database and storage (including thumbnails). This cannot be undone.'
                : 'Failed to permanently delete file.',
        ]);
    }

    public function bulkDestroyPreview(Request $request, BulkDeletionGuard $guard): JsonResponse
    {
        $encodedPaths = $request->input('ids', []);
        if (empty($encodedPaths)) {
            return response()->json(['status' => true, 'preview' => ['count' => 0, 'token' => null]]);
        }

        $paths = array_map(fn ($encoded) => base64_decode((string) $encoded), $encodedPaths);
        $paths = array_values(array_filter(
            $paths,
            fn ($path) => is_string($path)
                && $path !== ''
                && !$this->authorization->isUnauthorizedToModify($path)
        ));

        $preview = $guard->preview($paths, 'dashboard');

        return response()->json([
            'status' => true,
            'preview' => $preview,
        ]);
    }

    public function bulkDestroy(Request $request, BulkDeletionGuard $guard): JsonResponse
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['status' => false, 'message' => 'Missing bulk deletion token. Please preview first.'], 400);
        }

        try {
            $result = $guard->execute($token, false);

            return response()->json(['status' => true, 'message' => "{$result['deleted']} files moved to trash."]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function bulkForceDestroy(Request $request, BulkDeletionGuard $guard): JsonResponse
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['status' => false, 'message' => 'Missing bulk deletion token. Please preview first.'], 400);
        }

        try {
            $result = $guard->execute($token, true);

            return response()->json([
                'status' => true,
                'message' => "{$result['deleted']} files permanently deleted from the database and storage. This cannot be undone.",
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function restore(string $encodedPath): JsonResponse
    {
        $path = base64_decode((string) $encodedPath);

        if ($this->authorization->isUnauthorizedToModify($path)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
        }

        $restored = $this->repository->restore($path);

        return response()->json(['status' => $restored, 'message' => $restored ? 'File restored successfully.' : 'Failed to restore file.']);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $encodedPaths = $request->input('ids', []);
        if (!is_array($encodedPaths) || $encodedPaths === []) {
            return response()->json(['status' => false, 'message' => 'No files selected.'], 400);
        }

        $restored = 0;
        foreach ($encodedPaths as $encoded) {
            $path = base64_decode((string) $encoded);
            if ($this->authorization->isUnauthorizedToModify($path)) {
                continue;
            }
            if ($this->repository->restore($path)) {
                $restored++;
            }
        }

        return response()->json([
            'status' => $restored > 0,
            'restored' => $restored,
            'message' => $restored > 0
                ? "{$restored} file(s) restored successfully."
                : 'No files could be restored.',
        ]);
    }

    public function scan(): JsonResponse
    {
        Cache::put('media-vault:scan-status', [
            'state' => 'queued',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        ScanOrphansJob::dispatch();

        return response()->json([
            'status' => true,
            'state' => 'queued',
            'message' => 'Orphan scan queued. Results will appear when processing finishes.',
            'checked' => 'all disks',
        ]);
    }

    public function scanStatus(): JsonResponse
    {
        $scan = Cache::get('media-vault:scan-status', ['state' => 'idle']);

        if (($scan['state'] ?? '') === 'complete') {
            $orphans = Cache::get('media-vault:orphans', []);
            $scan['orphaned_count'] = is_array($orphans) ? count($orphans) : (int) ($scan['orphaned_count'] ?? 0);
        }

        return response()->json([
            'status' => true,
            'state' => $scan['state'] ?? 'idle',
            'orphaned_count' => $scan['orphaned_count'] ?? null,
            'started_at' => $scan['started_at'] ?? null,
            'completed_at' => $scan['completed_at'] ?? null,
        ]);
    }

    public function upload(Request $request, MediaVaultContract $uploader): JsonResponse
    {
        try {
            $result = $uploader->upload($request);
            if (is_array($result) && isset($result['status'])) {
                return response()->json($result);
            }

            return response()->json([
                'status' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function sessionStatus(string $sessionId, ResumableUploadService $resumableService): JsonResponse
    {
        try {
            $statusData = $resumableService->getSession($sessionId);

            return response()->json(array_merge(['status' => true], $statusData));
        } catch (\Exception $e) {
            return response()->json([
                'status'     => false,
                'is_expired' => true,
                'message'    => $e->getMessage(),
            ], 404);
        }
    }
}
