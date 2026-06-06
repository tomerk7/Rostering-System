<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Services\Rostering\FeasibilityChecker;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FeasibilityCheckerTest extends TestCase
{
    private const int ROLE_GUARD = 1;

    private const int SHIFT_A = 1;

    private const int SHIFT_B = 2;

    private const int SHIFT_C = 3;

    private FeasibilityChecker $checker;

    private CarbonImmutable $date;

    private int $dayOfWeek;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new FeasibilityChecker;
        $this->date = CarbonImmutable::create(2026, 6, 1)->startOfDay();
        $this->dayOfWeek = $this->date->dayOfWeek;
    }

    public function test_check_passes_when_the_pool_can_cover_peak_daily_demand(): void
    {
        // 18 guard positions/day (6 per shift x 3) need ceil(18/2)=9 distinct
        // workers. Provide exactly nine available guards.
        $slots = $this->guardSlotsForOneDay();
        $workers = $this->guards(9);

        self::assertSame([], $this->checker->check($slots, $workers));
    }

    public function test_check_flags_a_role_with_too_small_a_pool(): void
    {
        // Same 18 positions but only eight available guards: short by one.
        $slots = $this->guardSlotsForOneDay();
        $workers = $this->guards(8);

        $issues = $this->checker->check($slots, $workers);

        self::assertCount(1, $issues);
        self::assertSame(self::ROLE_GUARD, $issues[0]['role_id']);
        self::assertSame(9, $issues[0]['required_workers']);
        self::assertSame(8, $issues[0]['available_workers']);
        self::assertTrue($issues[0]['work_date']->equalTo($this->date));
    }

    public function test_check_ignores_workers_unavailable_on_the_demand_weekday(): void
    {
        $slots = $this->guardSlotsForOneDay();

        // Nine guards exist but only eight are available on the demand weekday.
        $workers = $this->guards(8);
        $workers[99] = [
            'role_id' => self::ROLE_GUARD,
            'days' => array_fill_keys([($this->dayOfWeek + 3) % 7], true),
        ];

        $issues = $this->checker->check($slots, $workers);

        self::assertCount(1, $issues);
        self::assertSame(8, $issues[0]['available_workers']);
    }

    /**
     * The full guard demand for a single day: 6 positions in each of three shifts.
     *
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
     */
    private function guardSlotsForOneDay(): array
    {
        $slots = [];

        foreach ([self::SHIFT_A, self::SHIFT_B, self::SHIFT_C] as $shiftId) {
            $slots[] = [
                'work_date' => $this->date,
                'shift_id' => $shiftId,
                'role_id' => self::ROLE_GUARD,
                'required_count' => 6,
                'duration_hours' => 8,
            ];
        }

        return $slots;
    }

    /**
     * Build a pool of guards all available on the demand weekday.
     *
     * @return array<int, array{role_id: int, days: array<int, true>}>
     */
    private function guards(int $count): array
    {
        $workers = [];

        for ($id = 1; $id <= $count; $id++) {
            $workers[$id] = [
                'role_id' => self::ROLE_GUARD,
                'days' => array_fill_keys([$this->dayOfWeek], true),
            ];
        }

        return $workers;
    }
}
