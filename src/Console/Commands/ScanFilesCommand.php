<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Contracts\FileRepositoryContract;
use MohamedSamy902\LaravelMediaVault\Repositories\DiskFileRepository;

class ScanFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media-vault:scan';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans files on disk to find orphans and duplicates, caching the results for the UI.';

    /**
     * Execute the console command.
     */
    public function handle(FileRepositoryContract $repository): int
    {
        if (!($repository instanceof DiskFileRepository) && !($repository instanceof \MohamedSamy902\LaravelMediaVault\Repositories\MultiSourceFileRepository)) {
            $this->warn('This command is only useful when using Disk or Multi-Source repositories.');
            return 0;
        }

        $this->info('Starting background file scan...');
        
        // Clearing cache to force the repository to recompute
        app('cache')->forget('media-vault:orphans');
        app('cache')->forget('media-vault:orphans_count');
        app('cache')->forget('media-vault:duplicates');

        // Triggering the methods will automatically compute and cache
        $this->info('Scanning for orphans (this might take a while)...');
        $orphans = $repository->getOrphanedFiles(1); // 1 per page just to trigger cache

        $this->info('Scanning for duplicates (grouping by size first)...');
        $duplicates = $repository->getDuplicateFiles();

        $this->info('Scan complete! The UI cache has been updated.');
        return 0;
    }
}
