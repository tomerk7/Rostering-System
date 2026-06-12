<?php

declare(strict_types=1);

namespace App\Exceptions\Rostering;

use RuntimeException;

/**
 * Thrown when a roster benchmark cannot run.
 */
final class BenchmarkException extends RuntimeException
{
    public static function noContracts(): self
    {
        return new self('No contracts found — add some workers first.');
    }
}
