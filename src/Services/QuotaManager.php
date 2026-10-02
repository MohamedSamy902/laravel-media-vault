<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Services;

use Illuminate\Support\Facades\Cache;
use MohamedSamy902\LaravelMediaVault\Contracts\QuotaManagerContract;
use MohamedSamy902\LaravelMediaVault\Exceptions\QuotaExceededException;
use MohamedSamy902\LaravelMediaVault\ValueObjects\QuotaInfo;
use RuntimeException;

/**
 * Manages per-user (or per-tenant) storage quotas backed by the database.
 *
 * Concurrent checks are serialized with a per-owner cache lock to reduce TOCTOU races.
 */
final class QuotaManager implements QuotaManagerContract
{
    #[\Override]
    public function check(int $userId, int $bytes): void
    {
        $this->withOwnerLock($userId, function () use ($userId, $bytes): void {
            $info = $this->usage($userId);

            $projected = $info->limit > 0 ? ($info->used + $bytes) / $info->limit : 0.0;
            $threshold = (float) (config('media-vault.quota.warning_threshold') ?? 0.9);
            if ($info->limit > 0 && $projected >= $threshold && ($info->used + $bytes) <= $info->limit) {
                \MohamedSamy902\LaravelMediaVault\Events\QuotaWarning::dispatch(
                    $userId,
                    $info->used + $bytes,
                    $info->limit,
                    round($projected, 4),
                );
            }

            if (($info->used + $bytes) > $info->limit) {
                $maxMB = round($info->limit / (1024 * 1024), 2);
                throw new QuotaExceededException(
                    "Storage quota exceeded for user [{$userId}]. Maximum allowed: {$maxMB}MB."
                );
            }
        });
    }

    #[\Override]
    public function consume(int $userId, int $bytes): void
    {
        // No-op in the default database implementation.
    }

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

        if (!config('media-vault.database.enabled', false)) {
            if (config('media-vault.quota.enabled', false)) {
                throw new QuotaExceededException(
                    'Quota enforcement requires media-vault.database.enabled=true.'
                );
            }

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
        try {
            $this->check((int) $userId, $bytes);
            return true;
        } catch (QuotaExceededException) {
            return false;
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withOwnerLock(int $userId, callable $callback): mixed
    {
        $lock = Cache::lock("media-vault:quota:{$userId}", 15);

        try {
            $lock->block(10);
            return $callback();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new RuntimeException('Could not acquire quota lock. Please retry the upload.');
        } finally {
            $lock->release();
        }
    }
}
