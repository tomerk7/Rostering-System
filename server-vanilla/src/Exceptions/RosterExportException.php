<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a roster cannot be exported.
 */
final class RosterExportException extends RuntimeException
{
    /**
     * The roster has unfilled coverage and cannot be exported.
     *
     * @return self
     */
    public static function coverageShortages(): self
    {
        return new self('Roster has coverage shortages and cannot be exported.');
    }
}
