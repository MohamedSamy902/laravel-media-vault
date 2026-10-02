<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class RegenerateThumbnailsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $options Artisan options (e.g. --disk, --force)
     */
    public function __construct(public array $options = [])
    {
    }

    public function handle(): void
    {
        Artisan::call('media-vault:regenerate-thumbnails', $this->options);
    }
}
