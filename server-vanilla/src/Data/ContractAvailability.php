<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One allowed (weekday, shift) pair for a contract.
 *
 * The `shift` relation defaults to null (not loaded). Note the shift times are
 * serialized as full ISO datetimes with *today's* UTC date — this reproduces
 * the API resource, where the datetime cast (`datetime:H:i:s`) is embedded
 * raw and json-encoded to `Y-m-dTH:i:s.uZ` rather than a plain time string.
 */
final readonly class ContractAvailability
{
    /**
     * Class constructor.
     * 
     * @param int $dayOfWeek
     * @param Shift|null $shift
     */
    public function __construct(
        public int $dayOfWeek,
        public ?Shift $shift = null,
    ) {}

    /**
     * @return array{day_of_week: int, shift: array{id: int, code: string, start_time: string, end_time: string, duration_hours: int}|null}
     */
    public function toArray(): array
    {
        return [
            'day_of_week' => $this->dayOfWeek,
            'shift' => $this->shift === null ? null : [
                'id' => $this->shift->id,
                'code' => $this->shift->code,
                'start_time' => self::isoTime($this->shift->startTime),
                'end_time' => self::isoTime($this->shift->endTime),
                'duration_hours' => $this->shift->durationHours,
            ],
        ];
    }

    /**
     * Turn a "H:i:s" shift time into today's-date ISO8601 UTC, matching
     * Carbon serialization (e.g. "00:00:00" -> "2026-06-18T00:00:00.000000Z").
     */
    private static function isoTime(string $time): string
    {
        return gmdate('Y-m-d') . 'T' . $time . '.000000Z';
    }
}
