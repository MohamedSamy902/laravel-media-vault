<?php

namespace MohamedSamy902\LaravelMediaVault\Exceptions;

use RuntimeException;

/**
 * Thrown when a user's upload quota is exceeded.
 */
final class QuotaExceededException extends RuntimeException {}
