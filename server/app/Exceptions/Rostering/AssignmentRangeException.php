<?php

declare(strict_types=1);

namespace App\Exceptions\Rostering;

use RuntimeException;

/**
 * Thrown when an assignment date range falls outside the roster month.
 */
final class AssignmentRangeException extends RuntimeException
{
    public static function outsideRosterMonth(): self
    {
        return new self('The assignment range must be within the roster month.');
    }
}
