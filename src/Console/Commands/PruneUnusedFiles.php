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
    protected $signature = 'media-vault:prune-unused {--days=30 : The number of days after which unused files are deleted} {--force : Force hard deletion without confirmation} {--dry-run : Show what would be deleted without actually deleting}';
    protected $description = 'Prune unused file uploads older than the specified number of days.';

    public function handle(BulkDeletionGuard $guard): int
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days);

        $paths = FileUpload::unused()->where('created_at', '<', $threshold)->pluck('path')->toArray();
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
