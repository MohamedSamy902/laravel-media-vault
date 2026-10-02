<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use MohamedSamy902\LaravelMediaVault\Security\BulkDeletionGuard;

class PruneUnusedFiles extends Command
{
    protected $signature = 'media-vault:prune-unused {--days= : Days unused before deletion (defaults to database.prune_after)} {--force : Force hard deletion without confirmation} {--dry-run : Show what would be deleted without actually deleting}';
    protected $description = 'Prune unused file uploads older than the specified number of days.';

    public function handle(BulkDeletionGuard $guard): int
    {
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

        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days);

        $paths = FileUpload::query()
            ->unused()
            ->whereNull('deleted_at')
            ->where('path', 'not like', '%/.trash/%')
            ->where('created_at', '<', $threshold)
            ->pluck('path')
            ->toArray();
        $count = count($paths);

        if ($count === 0) {
            $this->info("No unused files older than {$days} days found.");
            return 0;
        }

        // Preview phase
        $preview = $guard->preview($paths, 'cli');

        if ($dryRun) {
            $this->info("[DRY RUN] Found {$preview['count']} unused files older than {$days} days.");
            if ($preview['requires_extra_warning']) {
                $this->warn("⚠️  WARNING: You are about to delete an unusually large number of files!");
            }
            $this->info("Sample files:");
            foreach ($preview['sample'] as $sample) {
                $this->line("- {$sample}");
            }
            return 0;
        }

        if (!$force) {
            if ($preview['requires_extra_warning']) {
                $this->warn("⚠️  WARNING: You are about to delete {$preview['count']} files, which exceeds the normal threshold.");
            }
            
            if (!$this->confirm("Found {$preview['count']} unused files older than {$days} days. Do you want to permanently delete them?")) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info("Deleting {$preview['count']} unused files...");
        
        // Execute phase
        $result = $guard->execute($preview['token'], true); // force hard delete
        
        $this->info("Successfully deleted {$result['deleted']} files.");
        return 0;
    }
}
