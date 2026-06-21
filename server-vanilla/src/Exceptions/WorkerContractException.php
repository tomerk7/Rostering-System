<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a worker contract change conflicts with existing roster
 * assignments (lowering max monthly hours below already-assigned hours).
 * The controller renders this as a 422 in the standard response envelope.
 */
final class WorkerContractException extends RuntimeException
{
    /**
     * Class constructor.
     * 
     * @param int $maxMonthlyHours
     * @param  list<array{period_label: string, assigned_hours: int}>  $conflicts
     */
    public static function maxHoursBelowAssignedHours(int $maxMonthlyHours, array $conflicts): self
    {
        $details = implode(', ', array_map(
            static fn (array $conflict): string => sprintf(
                '%s (%d hours assigned)',
                $conflict['period_label'],
                $conflict['assigned_hours'],
            ),
            $conflicts,
        ));

        return new self(
            "Cannot lower max monthly hours to {$maxMonthlyHours}. Remove this worker from the roster(s) first: {$details}.",
        );
    }
}
