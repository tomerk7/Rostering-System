<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical role business keys and their CSV display labels. Ported from the
 * RoleCode enum so CSV import/export labels stay identical.
 */
enum RoleCode: string
{
    case GeneralGuard = 'general_guard';
    case Supervisor = 'supervisor';
    case Screener = 'screener';

    /**
     * Human-readable display name for the role.
     *
     * @return string
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
     *
     * @return string
     */
    public function csvLabel(): string
    {
        return strtolower($this->label());
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

    /**
     * Display label for a role code, or the code itself if unknown.
     *
     * @param string $code
     * @return string
     */
    public static function labelForCode(string $code): string
    {
        return (self::tryFrom($code)?->label()) ?? $code;
    }
}
