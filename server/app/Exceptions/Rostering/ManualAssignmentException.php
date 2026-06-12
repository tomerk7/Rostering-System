<?php

declare(strict_types=1);

namespace App\Exceptions\Rostering;

use RuntimeException;

/**
 * Thrown when a manual roster assignment violates a hard constraint.
 */
final class ManualAssignmentException extends RuntimeException
{
    public static function assignmentNotInRoster(): self
    {
        return new self('The assignment does not belong to this roster.');
    }

    public static function inactiveWorker(): self
    {
        return new self('Only active workers with a contract can be assigned.');
    }

    public static function dateOutsideRosterMonth(): self
    {
        return new self('The work date must fall within the roster month.');
    }

    public static function unavailableDay(): self
    {
        return new self('The worker is not available on that weekday.');
    }

    public static function unavailableShift(): self
    {
        return new self('The worker is not available for that shift.');
    }

    public static function duplicateSlot(): self
    {
        return new self('This worker is already assigned to that date and shift.');
    }

    public static function exceedsDailyShiftLimit(): self
    {
        return new self('A worker may take at most two shifts per calendar day.');
    }

    public static function exceedsMaxHours(): self
    {
        return new self("This assignment would exceed the worker's maximum monthly hours.");
    }
}
