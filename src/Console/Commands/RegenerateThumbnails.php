<?php

namespace MohamedSamy902\LaravelMediaVault\Console\Commands;

use Illuminate\Console\Command;
use MohamedSamy902\LaravelMediaVault\Models\FileUpload;
use MohamedSamy902\LaravelMediaVault\Contracts\ImageProcessorContract;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class RegenerateThumbnails extends Command
{
    protected $signature = 'media-vault:regenerate-thumbnails {--force : Regenerate even if thumbnails exist}';
    protected $description = 'Regenerate missing thumbnails for all images in the central repository.';

    public function handle(ImageProcessorContract $processor): int
    {
        $config = config('media-vault');
        if (!($config['thumbnails']['enabled'] ?? false)) {
            $this->warn('Thumbnails are disabled in configuration.');
            return 1;
        }

        $sizes = $config['thumbnails']['sizes'] ?? [];
        if (empty($sizes)) {
            $this->warn('No thumbnail sizes configured.');
            return 1;
        }

        $this->warn('Note: This command only regenerates thumbnails for files tracked in the central MediaVault model.');
        $this->warn('It does NOT currently support retroactively generating thumbnails for Custom Models (Multi-Source).');
        $this->newLine();

        $query = FileUpload::query()->where('mime_type', 'like', 'image/%');

        $total = $query->count();
        if ($total === 0) {
            $this->info('No images found.');
            return 0;
        }

        $this->info("Scanning {$total} images for missing thumbnails...");
        $bar = $this->output->createProgressBar($total);

        $force = $this->option('force');
        $generated = 0;
        $failed = 0;

        // Chunking the query to prevent memory exhaustion
        $query->chunk(100, function ($files) use ($sizes, $processor, $force, $bar, &$generated, &$failed) {
            foreach ($files as $file) {
                $existingMetadata = is_array($file->metadata) ? $file->metadata : [];
                $existingThumbnails = $existingMetadata['thumbnails'] ?? [];
                
                // If it already has exactly the sizes configured, skip unless forced
                $needsGeneration = $force || count($existingThumbnails) < count($sizes);
                
                if ($needsGeneration) {
                    try {
                        $disk = $file->disk;
                        $path = $file->path;
                        
                        if (Storage::disk($disk)->exists($path)) {
                            // Download to a temporary file because processor requires a valid file path
                            $tempPath = tempnam(sys_get_temp_dir(), 'advanced_upload_thumb_');
                            file_put_contents($tempPath, Storage::disk($disk)->get($path));
                            
                            $dir = dirname($path);
                            $dir = $dir === '.' ? '' : $dir . '/';
                            $baseName = pathinfo($file->name, PATHINFO_FILENAME);
                            $ext = pathinfo($file->name, PATHINFO_EXTENSION);
                            
                            $paths = [];
                            
                            foreach ($sizes as $sizeName => $sizeConfig) {
                                $width = (int) ($sizeConfig['width'] ?? null);
                                $height = (int) ($sizeConfig['height'] ?? null);
                                $crop = (bool) ($sizeConfig['crop'] ?? false);
                                
                                $thumbContent = $processor->thumbnail($tempPath, $width ?: null, $height ?: null, $crop);
                                $thumbPath = "{$dir}thumb_{$sizeName}_{$baseName}.{$ext}";
                                
                                Storage::disk($disk)->put($thumbPath, $thumbContent);
                                
                                $paths[$sizeName] = $thumbPath;
                            }
                            
                            // Clean up temp file
                            if (file_exists($tempPath)) {
                                try {
                                    unlink($tempPath);
                                } catch (\Exception $e) {
                                    Log::warning("Failed to delete temporary thumbnail file: {$tempPath}");
                                }
                            }
                            
                            $existingMetadata['thumbnails'] = $paths;
                            $file->update(['metadata' => $existingMetadata]);
                            $generated++;
                        } else {
                            $failed++;
                            Log::warning("File not found on disk for thumbnail regeneration: {$path}");
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        Log::error("Failed to regenerate thumbnails for {$file->path}: " . $e->getMessage());
                    }
                }
                
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        
        $this->info("Complete! Generated: {$generated}, Failed: {$failed}");
        return 0;
    }
}
