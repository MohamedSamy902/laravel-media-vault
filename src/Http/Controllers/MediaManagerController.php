<?php

declare(strict_types=1);

    namespace MohamedSamy902\LaravelMediaVault\Http\Controllers;

    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Controller;
    use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
    use MohamedSamy902\LaravelMediaVault\Models\UploadSession;
    use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
    use Illuminate\Pagination\LengthAwarePaginator;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\Auth;
    use MohamedSamy902\LaravelMediaVault\Security\BulkDeletionGuard;
    use MohamedSamy902\LaravelMediaVault\Services\ResumableUploadService;

    class MediaManagerController extends Controller
    {
        public function __construct(protected FileRepositoryContract $repository)
        {
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
            $stats['disk_total'] = null; // Can be added via disk_free_space if needed
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
                // Duplicates return an array of arrays, we flatten it for simple pagination for now
                // or just render the groups directly. Since it's a paginated view, we'll wrap it.
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

            // We mark disk_exists true for DTOs by default because Disk Repository scans real disk.
            // For Database repository, it might be false. DTOs should carry this logic.
            $files->through(function ($file) {
                $file->disk_exists = true; // In Disk Mode it's always true. For DB mode it should be validated.
                // Encoded path for safe URLs
                $file->encoded_path = base64_encode($file->path);
                
                // Detect if this file is likely a thumbnail based on naming convention
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
            $models = []; // Only available in DB mode natively, skipped for simplicity in Disk mode
            $stats = $this->repository->getStats($filters);
            
            // Format size
            $totalBytes = $stats['total_size'] ?? 0;
            $stats['size'] = $totalBytes < 1024 ? "{$totalBytes} B" :
                    ($totalBytes < 1048576 ? round($totalBytes / 1024, 1) . ' KB' :
                    ($totalBytes < 1073741824 ? round($totalBytes / 1048576, 1) . ' MB' : 
                    round($totalBytes / 1073741824, 1) . ' GB'));
            
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
            // Keep existing logic
            return back()->with('success', 'Configuration saved.');
        }

        public function sessions(Request $request): \Illuminate\Contracts\View\View
        {
            $status = $request->input('status', 'all');

            if (!config('media-vault.database.enabled', true)) {
                $sessions = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]);
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

            if ($this->isUnauthorizedToModify($path)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
            }

            $deleted = $this->repository->delete($path, false); // soft delete

            return response()->json(['status' => $deleted, 'message' => $deleted ? 'File moved to trash.' : 'Failed to delete file.']);
        }

        public function forceDestroy(string $encodedPath): JsonResponse
        {
            $path = base64_decode((string) $encodedPath);

            if ($this->isUnauthorizedToModify($path)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
            }

            $deleted = $this->repository->delete($path, true); // hard delete

            return response()->json(['status' => $deleted, 'message' => $deleted ? 'File permanently deleted.' : 'Failed to delete file.']);
        }

        public function bulkDestroyPreview(Request $request, BulkDeletionGuard $guard): JsonResponse
        {
            $encodedPaths = $request->input('ids', []);
            if (empty($encodedPaths)) {
                return response()->json(['status' => true, 'preview' => ['count' => 0, 'token' => null]]);
            }

            $paths = array_map(fn($encoded) => base64_decode((string) $encoded), $encodedPaths);
            $paths = array_filter($paths, fn($path) => !str_contains($path, '../') && !str_contains($path, '..\\') && !str_contains($path, "\0"));

            if (config('media-vault.database.enabled', true)) {
                $query = FileUpload::withTrashed()->whereIn('path', $paths);
                $paths = $query->pluck('path')->toArray();
            }

            $preview = $guard->preview($paths, 'dashboard');
            
            return response()->json([
                'status' => true,
                'preview' => $preview
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
                return response()->json(['status' => true, 'message' => "{$result['deleted']} files permanently deleted."]);
            } catch (\Exception $e) {
                return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
            }
        }

        public function restore(string $encodedPath): JsonResponse
        {
            $path = base64_decode((string) $encodedPath);
            
            if ($this->isUnauthorizedToModify($path)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
            }

            $restored = $this->repository->restore($path);

            return response()->json(['status' => $restored, 'message' => $restored ? 'File restored successfully.' : 'Failed to restore file.']);
        }

        public function scan(): JsonResponse
        {

            // Run the scan command in the background to prevent timeouts on large disks.
            // The command will update the cache, and the UI will read from it on next load.
            \Illuminate\Support\Facades\Artisan::queue('media-vault:scan');
            
            return response()->json([
                'status' => true,
                'orphaned_count' => 'Scanning...',
                'message' => 'Background scan dispatched successfully. Refresh the page in a few minutes to see updated results.',
                'checked' => 'all disks',
            ]);
        }

        public function upload(Request $request, \MohamedSamy902\LaravelMediaVault\Contracts\MediaVaultContract $uploader): JsonResponse
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

        private function isUnauthorizedToModify(string $path): bool
        {
            // Prevent basic path traversal and null byte injection
            if (str_contains($path, '../') || str_contains($path, '..\\') || str_contains($path, "\0")) {
                return true;
            }

            if (config('media-vault.database.enabled', true)) {
                $file = FileUpload::withTrashed()->where('path', $path)->first();
                
                // File must exist in the database to be managed
                if (!$file) {
                    return true;
                }
            }

            return false;
        }
    }
