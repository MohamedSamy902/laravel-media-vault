<?php

namespace MohamedSamy902\LaravelMediaVault\Services;

use MohamedSamy902\LaravelMediaVault\Contracts\QuotaManagerContract;
use MohamedSamy902\LaravelMediaVault\Exceptions\QuotaExceededException;
use MohamedSamy902\LaravelMediaVault\ValueObjects\QuotaInfo;

/**
 * Manages per-user (or per-tenant) storage quotas backed by the database.
 *
 * The key column is configurable via 'media-vault.quota.key_column',
 * defaulting to 'user_id'. For multi-tenant apps set it to 'tenant_id'.
 */
final class QuotaManager implements QuotaManagerContract
{
    #[\Override]
    public function check(int $userId, int $bytes): void
    {
        $info = $this->usage($userId);

        if (($info->used + $bytes) > $info->limit) {
            $maxMB = round($info->limit / (1024 * 1024), 2);
            throw new QuotaExceededException(
                "Storage quota exceeded for user [{$userId}]. Maximum allowed: {$maxMB}MB."
            );
        }
    }

    /**
     * QuotaManager works through the DB model — consumption is implicit
     * via file record creation. This method is a no-op hook for
     * implementations that use a separate quota table.
     */
    #[\Override]
    public function consume(int $userId, int $bytes): void
    {
        // No-op in the default database implementation.
        // The quota is calculated live from file_uploads.size sum.
    }

    /**
     * Release is a no-op in the default implementation because quota is
     * recalculated live. Override this if you cache quota elsewhere.
     */
    #[\Override]
    public function release(int $userId, int $bytes): void
    {
        // No-op — see consume() note above.
    }

    #[\Override]
    public function remaining(int $userId): int
    {
        $info = $this->usage($userId);
        return $info->remaining;
    }

    #[\Override]
    public function usage(int $userId): QuotaInfo
    {
        $config     = config('media-vault.quota');
        $limit      = (int) ($config['max_size_per_user'] ?? 1073741824);
        $keyColumn  = $config['key_column'] ?? 'user_id';
        $modelClass = config('media-vault.database.model');

        // ✅ Fixed: if DB tracking is disabled, return zero usage instead of crashing
        if (!config('media-vault.database.enabled', false)) {
            return new QuotaInfo(used: 0, limit: $limit, remaining: $limit, percentage: 0.0);
        }

        $used      = (int) $modelClass::where($keyColumn, $userId)->sum('size');
        $remaining = max(0, $limit - $used);
        $percentage = $limit > 0 ? round($used / $limit, 4) : 0.0;

        return new QuotaInfo(
            used:       $used,
            limit:      $limit,
            remaining:  $remaining,
            percentage: $percentage,
        );
    }

    public function hasAvailableSpace(int|string $userId, int $bytes): bool
    {
        $info = $this->usage((int) $userId);
        return ($info->used + $bytes) <= $info->limit;
    }
}
