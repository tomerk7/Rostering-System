<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    /**
     * Get the backing values for every source.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
