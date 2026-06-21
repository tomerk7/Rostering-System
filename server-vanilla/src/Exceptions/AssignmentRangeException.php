<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an assignment date range falls outside the roster month. The
 * controller renders this as a 422 in the standard response, with the message
 * under the `date_range` error key.
 */
final class AssignmentRangeException extends RuntimeException
{
    /**
     * @return self
     */
    public static function outsideRosterMonth(): self
    {
        return new self('The assignment range must be within the roster month.');
    }
}
