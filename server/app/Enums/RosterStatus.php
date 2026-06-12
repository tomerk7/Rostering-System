<?php

declare(strict_types=1);

namespace App\Enums;

enum RosterStatus: string
{
    case Published = 'published';

    /**
     * Get the backing values for every status.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
