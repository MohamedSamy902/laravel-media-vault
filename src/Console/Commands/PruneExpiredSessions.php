<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Models\UploadSession;

class PruneExpiredSessions extends Command
{
    protected $signature = 'media-vault:prune-sessions';
    protected $description = 'Prune expired upload sessions and their associated temporary chunks.';

    public function handle(): int
    {
        if (!config('media-vault.database.enabled', true)) {
            $this->info("Database tracking is disabled. Skipping session pruning.");
            return 0;
        }

        $expiredSessions = UploadSession::where('expires_at', '<', now())
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        $count = $expiredSessions->count();

        if ($count === 0) {
            $this->info("No expired upload sessions found.");
            return 0;
        }

        $this->info("Cleaning up {$count} expired sessions...");

        $deleted = 0;
        foreach ($expiredSessions as $session) {
            /** @var UploadSession $session */
            // Delete temporary chunks directory
            $dir = sys_get_temp_dir() . '/chunks_' . $session->session_id;
            
            if (\Illuminate\Support\Facades\File::isDirectory($dir)) {
                \Illuminate\Support\Facades\File::deleteDirectory($dir);
            }

            // Delete the session record
            $session->delete();
            $deleted++;
        }

        $this->info("Successfully cleaned up {$deleted} expired sessions.");
        return 0;
    }
}
