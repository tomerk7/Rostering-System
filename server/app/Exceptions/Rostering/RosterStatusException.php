<?php

declare(strict_types=1);

namespace App\Exceptions\Rostering;

use App\Enums\RosterStatus;
use RuntimeException;

/**
 * Thrown when a roster status transition is invalid.
 */
final class RosterStatusException extends RuntimeException
{
    /**
     * Thrown when a draft roster is attempted to be published.
     * 
     * @param RosterStatus $current
     * @return self
     */
    public static function cannotPublishNonDraft(RosterStatus $current): self
    {
        return new self(
            "Only a draft roster can be published; current status is '{$current->value}'.",
        );
    }
}
