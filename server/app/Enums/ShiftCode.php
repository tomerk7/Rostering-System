<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical, immutable business keys for the fixed daily shifts.
 *
 * The backing values mirror the `shifts.code` column. `code` stays a stable
 * contract used by seeders, CSV import/export and the rostering engine; the
 * human-facing display text comes from {@see self::label()} and may change
 * freely. Shift timing lives on the `shifts` table, not here.
 */
enum ShiftCode: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';

    /**
     * Human-readable display name for the shift.
     */
    public function label(): string
    {
        return match ($this) {
            self::A => 'morning',
            self::B => 'day',
            self::C => 'evening',
        };
    }

    /**
     * All shift codes as their backing string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
