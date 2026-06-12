<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Models\Shift;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Shared worker CSV column schema and shift column helpers.
 *
 * Fixed columns 0-6 hold worker/contract fields. Columns 7+ are shift columns
 * identified by header label (e.g. 08:00-16:00). Each shift cell holds
 * a cron-style day expression using days 1-7 (1=Sunday .. 7=Saturday).
 */
final class WorkerCsvSchema
{
    public const int FULL_NAME = 0;

    public const int ISRAELI_ID = 1;

    public const int ROLE = 2;

    public const int STATUS = 3;

    public const int HOURLY_COST = 4;

    public const int MIN_MONTHLY_HOURS = 5;

    public const int MAX_MONTHLY_HOURS = 6;

    public const int SHIFT_COLUMN_OFFSET = 7;

    /**
     * In-cell separator for day tokens. A pipe is used so commas never trigger Excel quoting.
     */
    public const string VALUE_SEPARATOR = '|';

    public const string DAY_RANGE_SEPARATOR = '-';

    public const string STATUS_ACTIVE = 'Active';

    public const string STATUS_INACTIVE = 'Inactive';

    public const string DEFAULT_STATUS = self::STATUS_ACTIVE;

    /**
     * Fixed column labels written before the dynamic shift columns.
     *
     * @var list<string>
     */
    public const array FIXED_HEADERS = [
        'full_name',
        'israeli_id',
        'role',
        'status',
        'hourly_cost',
        'min_monthly_hours',
        'max_monthly_hours',
    ];

    /** @var Collection<int, Shift>|null */
    private ?Collection $orderedShifts = null;

    /** @var array<string, string>|null */
    private ?array $shiftCodeByColumnLabel = null;

    /**
     * Format a shift's time window as the CSV column header (e.g. 08:00-16:00).
     */
    public static function shiftColumnLabel(Shift $shift): string
    {
        return $shift->start_time->format('H:i').'-'.$shift->end_time->format('H:i');
    }

    /**
     * Get the ordered shifts.
     *
     * @return Collection<int, Shift>
     */
    public function orderedShifts(): Collection
    {
        if ($this->orderedShifts === null) {
            $this->orderedShifts = Shift::query()->orderBy('start_time')->get();
        }

        return $this->orderedShifts;
    }

    /**
     * Get the shift code by column label.
     *
     * @return array<string, string>
     */
    public function shiftCodeByColumnLabel(): array
    {
        if ($this->shiftCodeByColumnLabel === null) {
            $map = [];

            foreach ($this->orderedShifts() as $shift) {
                $label = self::shiftColumnLabel($shift);

                if (isset($map[$label])) {
                    throw new RuntimeException(
                        "Two shifts share the CSV column label \"{$label}\" (codes {$map[$label]} and {$shift->code}).",
                    );
                }

                $map[$label] = $shift->code;
            }

            $this->shiftCodeByColumnLabel = $map;
        }

        return $this->shiftCodeByColumnLabel;
    }
}
