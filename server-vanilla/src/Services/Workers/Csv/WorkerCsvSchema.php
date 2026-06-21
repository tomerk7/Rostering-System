<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Support\DB;
use PDO;
use RuntimeException;

/**
 * Shared worker CSV column schema and shift-column helpers.
 *
 * Fixed columns 0-6 hold worker/contract fields. Columns 7+ are shift columns
 * identified by header label (e.g. 08:00-16:00). Each shift cell holds a
 * cron-style day expression using days 1-7 (1=Sunday .. 7=Saturday).
 */
class WorkerCsvSchema
{
    public const int FULL_NAME = 0;
    public const int ISRAELI_ID = 1;
    public const int ROLE = 2;
    public const int STATUS = 3;
    public const int HOURLY_COST = 4;
    public const int MIN_MONTHLY_HOURS = 5;
    public const int MAX_MONTHLY_HOURS = 6;
    public const int SHIFT_COLUMN_OFFSET = 7;

    /** In-cell separator for day tokens (pipe so commas never trigger CSV quoting). */
    public const string VALUE_SEPARATOR = '|';
    public const string DAY_RANGE_SEPARATOR = '-';

    public const string STATUS_ACTIVE = 'Active';
    public const string STATUS_INACTIVE = 'Inactive';
    public const string DEFAULT_STATUS = self::STATUS_ACTIVE;

    /** @var list<string> */
    public const array FIXED_HEADERS = [
        'full_name',
        'israeli_id',
        'role',
        'status',
        'hourly_cost',
        'min_monthly_hours',
        'max_monthly_hours',
    ];

    private PDO $pdo;

    /** @var list<array{id: int, code: string, start_time: string, end_time: string}>|null */
    private ?array $orderedShifts = null;

    /** @var array<string, string>|null */
    private ?array $shiftCodeByColumnLabel = null;

    /**
     * Class constructor.
     *
     * @param PDO|null $pdo
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? DB::connect();
    }

    /**
     * Format a shift's time window as the CSV column header (e.g. 08:00-16:00).
     *
     * @param  array{start_time: string, end_time: string, ...}  $shift
     */
    public static function shiftColumnLabel(array $shift): string
    {
        return self::hm($shift['start_time']) . '-' . self::hm($shift['end_time']);
    }

    /**
     * Shifts ordered by start_time (the CSV shift-column order).
     *
     * @return list<array{id: int, code: string, start_time: string, end_time: string}>
     */
    public function orderedShifts(): array
    {
        if ($this->orderedShifts === null) {
            $rows = $this->pdo
                ->query('SELECT id, code, start_time, end_time FROM shifts ORDER BY start_time')
                ->fetchAll();

            $this->orderedShifts = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'code' => $r['code'],
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time'],
            ], $rows);
        }

        return $this->orderedShifts;
    }

    /**
     * Map each shift's column label to its code (errors on a duplicate label).
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
                        "Two shifts share the CSV column label \"{$label}\" (codes {$map[$label]} and {$shift['code']}).",
                    );
                }
                $map[$label] = $shift['code'];
            }
            $this->shiftCodeByColumnLabel = $map;
        }

        return $this->shiftCodeByColumnLabel;
    }

    /** "HH:MM:SS" -> "HH:MM". */
    /**
     * @param string $time
     * @return string
     */
    private static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }
}
