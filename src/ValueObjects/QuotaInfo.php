<?php

namespace MohamedSamy902\LaravelMediaVault\ValueObjects;

/**
 * Immutable value object representing a user's quota breakdown.
 */
readonly class QuotaInfo
{
    public function __construct(
        public int   $used,
        public int   $limit,
        public int   $remaining,
        public float $percentage,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'used'       => $this->used,
            'limit'      => $this->limit,
            'remaining'  => $this->remaining,
            'percentage' => $this->percentage,
        ];
    }
}
