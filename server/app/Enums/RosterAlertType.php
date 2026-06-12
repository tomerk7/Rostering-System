<?php

declare(strict_types=1);

namespace App\Enums;

enum RosterAlertType: string
{
    case HoursShortfall = 'hours_shortfall';

    /**
     * Get the backing values for every alert type.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
