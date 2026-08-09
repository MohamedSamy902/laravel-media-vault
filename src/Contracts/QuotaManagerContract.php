<?php

namespace MohamedSamy902\LaravelMediaVault\Contracts;

use MohamedSamy902\LaravelMediaVault\ValueObjects\QuotaInfo;

interface QuotaManagerContract
{
    /**
     * Check if a user has enough quota for a given number of bytes.
     * Throws QuotaExceededException if the limit would be exceeded.
     *
     * @param  int  $userId
     * @param  int  $bytes
     * @return void
     */
    public function check(int $userId, int $bytes): void;

    /**
     * Record consumption of bytes against a user's quota.
     *
     * @param  int  $userId
     * @param  int  $bytes
     * @return void
     */
    public function consume(int $userId, int $bytes): void;

    /**
     * Release (free) bytes from a user's quota (e.g. after file deletion).
     *
     * @param  int  $userId
     * @param  int  $bytes
     * @return void
     */
    public function release(int $userId, int $bytes): void;

    /**
     * Get the remaining quota in bytes for a user.
     *
     * @param  int  $userId
     * @return int
     */
    public function remaining(int $userId): int;

    /**
     * Get a full quota breakdown for a user.
     *
     * @param  int  $userId
     * @return QuotaInfo
     */
    public function usage(int $userId): QuotaInfo;

    /**
     * Check if a user has enough storage quota remaining for the specified byte size.
     *
     * @param  int|string  $userId
     * @param  int  $bytes
     * @return bool
     */
    public function hasAvailableSpace(int|string $userId, int $bytes): bool;
}
