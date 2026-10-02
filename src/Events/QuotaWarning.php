<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when storage usage crosses the configured quota warning threshold.
 */
class QuotaWarning
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $ownerId,
        public int $used,
        public int $limit,
        public float $percentage,
    ) {
    }
}
