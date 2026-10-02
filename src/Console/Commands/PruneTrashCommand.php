<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

class PruneTrashCommand extends Command
{
    protected $signature = 'media-vault:prune-trash {--days=30 : Days in trash before permanent deletion} {--force : Skip confirmation} {--dry-run : List candidates only}';

    protected $description = 'Permanently delete soft-trashed uploads older than the given number of days.';

    public function handle(): int
    {
        if (!config('media-vault.database.enabled', false)) {
            $this->warn('Database tracking is disabled; prune-trash requires media-vault.database.enabled.');
            return 0;
        }

        $days = max(1, (int) $this->option('days'));
        $threshold = Carbon::now()->subDays($days);

        $records = FileUpload::onlyTrashed()
            ->where('deleted_at', '<', $threshold)
            ->get(['id', 'path']);

        if ($records->isEmpty()) {
            $this->info("No trashed files older than {$days} days found.");
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info("[DRY RUN] Found {$records->count()} trashed files older than {$days} days.");
            foreach ($records->take(5) as $record) {
                $this->line("- {$record->path}");
            }
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Permanently delete {$records->count()} trashed files?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $deleted = 0;
        foreach ($records as $record) {
            $result = MediaVault::forceDelete($record->id);
            if (($result['status'] ?? false) === true) {
                $deleted++;
            }
        }

        $this->info("Permanently deleted {$deleted} trashed files.");
        return 0;
    }
}
