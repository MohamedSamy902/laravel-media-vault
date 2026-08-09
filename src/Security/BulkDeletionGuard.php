<?php
declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Security;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;

class BulkDeletionGuard
{
    public function __construct(protected FileRepositoryContract $repository)
    {
    }

    /**
     * Generates a preview and a token for bulk deletion.
     *
     * @param array<int, string> $paths
     * @param string $source
     * @return array<string, mixed>
     */
    public function preview(array $paths, string $source = 'dashboard'): array
    {
        $count = count($paths);
        $token = Str::random(60);
        
        $context = [
            'paths' => $paths,
            'source' => $source,
            'user_id' => Auth::id(),
            'ip' => request()->ip(),
        ];

        Cache::put("bulk_delete_{$token}", $context, 3600); // 1 hour validity

        return [
            'token' => $token,
            'count' => $count,
            'requires_extra_warning' => $count >= config('media-vault.security.bulk_delete_warning_threshold', 100),
            'sample' => array_slice($paths, 0, 5)
        ];
    }

    /**
     * Executes the actual deletion using a valid token.
     *
     * @param string $token
     * @param bool $forceHardDelete
     * @return array<string, mixed>
     */
    public function execute(string $token, bool $forceHardDelete = false): array
    {
        $context = Cache::pull("bulk_delete_{$token}");
        
        if (!is_array($context) || !isset($context['paths'])) {
            throw new \RuntimeException('Invalid or expired bulk deletion token. Please preview again.');
        }

        // Verify context binding (User and IP) if not from CLI
        if ($context['source'] !== 'cli') {
            if ($context['user_id'] !== Auth::id() || $context['ip'] !== request()->ip()) {
                throw new \RuntimeException('Unauthorized token usage. Token context mismatch.');
            }
        }

        $deleted = 0;
        foreach ($context['paths'] as $path) {
            if ($this->repository->delete($path, $forceHardDelete)) {
                $deleted++;
            }
        }

        return [
            'status' => true,
            'deleted' => $deleted,
            'requested' => count($context['paths'])
        ];
    }
}
