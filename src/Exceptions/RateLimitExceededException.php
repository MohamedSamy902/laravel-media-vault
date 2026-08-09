<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Exceptions;

use RuntimeException;

class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Rate limit exceeded.',
        public readonly int $retryAfterSeconds = 60,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 429, $previous);
    }
}
