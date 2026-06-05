<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

/**
 * Single source of truth for the worker CSV layout.
 *
 * Columns are matched by position (zero-based index), not header name; the
 * header row is documentation only. See prompts/docs/csv-schema.md.
 */
final class WorkerCsvFormat
{
    public const int FULL_NAME = 0;
    public const int ISRAELI_ID = 1;
    public const int ROLE = 2;
    public const int STATUS = 3;
    public const int HOURLY_COST = 4;
    public const int MIN_MONTHLY_HOURS = 5;
    public const int MAX_MONTHLY_HOURS = 6;
    public const int AVAILABLE_DAYS = 7;
    public const int AVAILABLE_SHIFTS = 8;

    /**
     * Fixed column order, written verbatim as the export header row.
     *
     * @var list<string>
     */
    public const array HEADERS = [
        'full_name',
        'israeli_id',
        'role',
        'status',
        'hourly_cost',
        'min_monthly_hours',
        'max_monthly_hours',
        'available_days',
        'available_shifts',
    ];

    /**
     * In-cell separator for multi-value columns (days, shifts). A pipe is used
     * so commas never trigger Excel quoting.
     */
    public const string VALUE_SEPARATOR = '|';

    public const string STATUS_ACTIVE = 'Active';
    public const string STATUS_INACTIVE = 'Inactive';
    public const string DEFAULT_STATUS = self::STATUS_ACTIVE;

    /**
     * CSV role label (lowercased) to roles.code.
     *
     * @var array<string, string>
     */
    public const array ROLE_CODE_BY_LABEL = [
        'general guard' => 'general_guard',
        'supervisor' => 'supervisor',
        'screener' => 'screener',
    ];

    /**
     * roles.code to CSV role label, used on export.
     *
     * @var array<string, string>
     */
    public const array ROLE_LABEL_BY_CODE = [
        'general_guard' => 'General Guard',
        'supervisor' => 'Supervisor',
        'screener' => 'Screener',
    ];

    /**
     * CSV day token (lowercased) to day_of_week (0 = Sunday .. 6 = Saturday).
     *
     * @var array<string, int>
     */
    public const array DAY_OF_WEEK_BY_TOKEN = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
    ];

    /**
     * day_of_week (0..6) to CSV day token, used on export.
     *
     * @var array<int, string>
     */
    public const array DAY_TOKEN_BY_NUMBER = [
        0 => 'Sun',
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
    ];

    /**
     * Allowed shift codes.
     *
     * @var list<string>
     */
    public const array SHIFT_CODES = ['A', 'B', 'C'];
}
