<?php

declare(strict_types=1);

namespace MohamedSamy902\LaravelMediaVault\Exceptions;

use RuntimeException;

/**
 * Thrown when a URL upload is blocked due to SSRF protection.
 */
final class SsrfException extends RuntimeException {}
