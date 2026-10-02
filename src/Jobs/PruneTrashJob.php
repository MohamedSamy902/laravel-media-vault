<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class PruneTrashJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $days = 30,
        public bool $force = true,
    ) {
    }

    public function handle(): void
    {
        Artisan::call('media-vault:prune-trash', [
            '--days' => $this->days,
            '--force' => $this->force,
        ]);
    }
}
