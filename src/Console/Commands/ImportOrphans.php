<?php

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Services\MimeTypeResolver;

class ImportOrphans extends Command
{
    protected $signature = 'media-vault:import-orphans {disk? : The disk to scan (defaults to config)}';
    protected $description = 'Import existing files from the physical disk into the central database to prevent them from being marked as orphans.';

    public function handle(MimeTypeResolver $mimeResolver): int
    {
        $config = config('media-vault');
        if (!($config['database']['enabled'] ?? false)) {
            $this->warn('Database tracking is currently disabled in configuration.');
            return 1;
        }

        $diskName = (string) ($this->argument('disk') ?: ($config['storage']['disk'] ?? 'public'));
        $this->info("Scanning disk [{$diskName}] for missing files...");

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);
        $driver = $disk->getDriver();
        $listing = $driver->listContents('', true);
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        $batch = [];
        $batchSize = 500;

        foreach ($listing as $item) {
            if ($item->type() !== 'file') continue;
            
            $path = $item->path();
            
            // Skip hidden or system files
            if (str_starts_with(basename($path), '.')) continue;
            
            // Skip known thumbnails to avoid importing them as main files
            if (str_starts_with(basename($path), 'thumb_')) continue;

            $batch[] = $path;
            
            if (count($batch) >= $batchSize) {
                $this->processBatch($batch, $diskName, $mimeResolver, $imported, $skipped, $errors);
                $batch = [];
            }
        }
        
        if (count($batch) > 0) {
            $this->processBatch($batch, $diskName, $mimeResolver, $imported, $skipped, $errors);
        }

        $this->newLine();
        $this->info("Import Complete!");
        $this->line("- Imported: {$imported}");
        $this->line("- Skipped (Already in DB): {$skipped}");
        $this->line("- Errors: {$errors}");

        return 0;
    }

    /**
     * @param array<int, string> $paths
     */
    protected function processBatch(array $paths, string $diskName, MimeTypeResolver $mimeResolver, int &$imported, int &$skipped, int &$errors): void
    {
        try {
            $existingPaths = FileUpload::withTrashed()->whereIn('path', $paths)->pluck('path')->toArray();
            $existingSet = array_flip($existingPaths);
            
            $insertData = [];
            $now = now();
            
            foreach ($paths as $path) {
                if (isset($existingSet[$path])) {
                    $skipped++;
                    continue;
                }
                
                /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
                $disk = Storage::disk($diskName);
                $size = $disk->size($path);
                $mime = $disk->mimeType($path);
                
                if (!$mime || $mime === 'application/octet-stream') {
                    $ext = pathinfo($path, PATHINFO_EXTENSION);
                    if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                        $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                    } elseif (in_array(strtolower($ext), ['mp4', 'mov', 'avi'])) {
                        $mime = 'video/' . $ext;
                    }
                }
                
                $insertData[] = [
                    'original_name' => basename($path),
                    'name'          => basename($path),
                    'path'          => $path,
                    'disk'          => $diskName,
                    'mime_type'     => $mime,
                    'type'          => $mimeResolver->toFileType((string) $mime),
                    'size'          => $size,
                    'is_used'       => true,
                    'user_id'       => null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
            
            if (!empty($insertData)) {
                FileUpload::insert($insertData);
                $imported += count($insertData);
            }
            
        } catch (\Exception $e) {
            $errors += count($paths);
            $this->error("Failed to process batch: " . $e->getMessage());
        }
    }
}
