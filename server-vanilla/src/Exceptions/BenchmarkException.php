<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a roster benchmark cannot run.
 */
final class BenchmarkException extends RuntimeException
{
    /**
     * No contracts exist to benchmark against.
     *
     * @return self
     */
    public static function noContracts(): self
    {
        return new self('No contracts found — add some workers first.');
    }
}
