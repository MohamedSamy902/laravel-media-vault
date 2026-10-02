<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Facades\MediaVault;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;

class PruneUnusedFiles extends Command
{
    protected $signature = 'media-vault:prune-unused {--days= : Days unused before deletion (defaults to database.prune_after)} {--force : Force hard deletion without confirmation} {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Permanently delete unused (is_used=false) uploads older than the configured retention window.';

    public function handle(): int
    {
        if (!config('media-vault.database.enabled', false)) {
            $this->warn('Database tracking is disabled; prune-unused requires media-vault.database.enabled and usage tracking (attachUpload / markAsUsed).');
            return 0;
        }

        $configuredDays = config('media-vault.database.prune_after');
        $daysOption = $this->option('days');

        if ($daysOption === null || $daysOption === '') {
            if ($configuredDays === null) {
                $this->warn('Pruning is disabled (database.prune_after is null). Pass --days to run anyway.');
                return 0;
            }
            $days = (int) $configuredDays;
        } else {
            $days = (int) $daysOption;
        }

        if ($days < 1) {
            $this->error('Days must be at least 1.');
            return 1;
        }

        $threshold = Carbon::now()->subDays($days);

        $records = FileUpload::query()
            ->unused()
            ->whereNull('deleted_at')
            ->where('path', 'not like', '%/.trash/%')
            ->where('created_at', '<', $threshold)
            ->get(['id', 'path']);

        $count = $records->count();

        if ($count === 0) {
            $this->info("No unused files older than {$days} days found.");
            return 0;
        }

        $warningThreshold = (int) config('media-vault.security.bulk_delete_warning_threshold', 100);
        $requiresExtraWarning = $count >= $warningThreshold;

        if ($this->option('dry-run')) {
            $this->info("[DRY RUN] Found {$count} unused files older than {$days} days.");
            if ($requiresExtraWarning) {
                $this->warn('WARNING: Candidate count exceeds the bulk delete warning threshold.');
            }
            foreach ($records->take(5) as $record) {
                $this->line("- {$record->path}");
            }
            return 0;
        }

        if (!$this->option('force')) {
            if ($requiresExtraWarning) {
                $this->warn("WARNING: You are about to permanently delete {$count} files.");
            }

            if (!$this->confirm("Permanently delete {$count} unused files older than {$days} days from DB and storage?")) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info("Deleting {$count} unused files...");

        $deleted = 0;
        foreach ($records as $record) {
            $result = MediaVault::forceDelete($record->id);
            if (($result['status'] ?? false) === true) {
                $deleted++;
            }
        }

        $this->info("Successfully deleted {$deleted} files.");
        return 0;
    }
}
