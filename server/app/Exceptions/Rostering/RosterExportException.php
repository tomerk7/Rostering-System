<?php

declare(strict_types=1);

namespace App\Exceptions\Rostering;

use RuntimeException;

/**
 * Thrown when a roster cannot be exported.
 */
final class RosterExportException extends RuntimeException
{
    public static function coverageShortages(): self
    {
        return new self('Roster has coverage shortages and cannot be exported.');
    }
}
