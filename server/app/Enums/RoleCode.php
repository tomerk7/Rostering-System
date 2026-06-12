<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical, immutable business keys for worker roles.
 *
 * The backing values mirror the `roles.code` column. `code` stays a stable
 * contract used by seeders, CSV import/export and API filters; the human-facing
 * display text comes from {@see self::label()} and may change freely.
 */
enum RoleCode: string
{
    case GeneralGuard = 'general_guard';
    case Supervisor = 'supervisor';
    case Screener = 'screener';

    /**
     * Human-readable display name for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::GeneralGuard => 'General Guard',
            self::Supervisor => 'Supervisor',
            self::Screener => 'Screener',
        };
    }

    /**
     * Lowercased CSV import label for this role.
     */
    public function csvLabel(): string
    {
        return strtolower($this->label());
    }

    /**
     * All role codes as their backing string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Map lowercased CSV role labels to role codes.
     *
     * @return array<string, string>
     */
    public static function codeByCsvLabel(): array
    {
        $map = [];

        foreach (self::cases() as $case) {
            $map[$case->csvLabel()] = $case->value;
        }

        return $map;
    }
}
