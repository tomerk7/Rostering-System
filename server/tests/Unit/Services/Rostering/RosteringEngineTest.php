<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Services\Rostering\RosteringEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RosteringEngineTest extends TestCase
{
    private const int ROLE_GUARD = 1;

    private const int ROLE_SUPERVISOR = 2;

    private const int SHIFT_A = 1;

    private const int SHIFT_B = 2;

    private const int SHIFT_C = 3;

    private RosteringEngine $engine;

    private CarbonImmutable $date;

    private int $dayOfWeek;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new RosteringEngine;
        $this->date = CarbonImmutable::create(2026, 6, 1)->startOfDay();
        $this->dayOfWeek = $this->date->dayOfWeek;
    }

    public function test_available_worker_ids_enforces_every_hard_constraint(): void
    {
        $slot = $this->slot(self::SHIFT_A, self::ROLE_GUARD);

        $workers = [
            // Eligible in every respect.
            10 => $this->worker(role: self::ROLE_GUARD),
            // Wrong role.
            11 => $this->worker(role: self::ROLE_SUPERVISOR),
            // Not available on this weekday.
            12 => $this->worker(role: self::ROLE_GUARD, days: [$this->otherDayOfWeek()]),
            // Not available for this shift.
            13 => $this->worker(role: self::ROLE_GUARD, shifts: [self::SHIFT_B]),
            // Already at the two-shift daily ceiling.
            14 => $this->worker(role: self::ROLE_GUARD, shiftsPerDate: [$this->date->toDateString() => 2]),
            // Adding this shift would exceed max hours.
            15 => $this->worker(role: self::ROLE_GUARD, maxHours: 8, assignedHours: 8),
        ];

        self::assertSame([10], $this->engine->availableWorkerIds($slot, $workers));
    }

    public function test_available_worker_ids_excludes_workers_already_in_the_slot(): void
    {
        $slot = $this->slot(self::SHIFT_A, self::ROLE_GUARD);
        $workers = [
            10 => $this->worker(role: self::ROLE_GUARD),
            11 => $this->worker(role: self::ROLE_GUARD),
        ];

        self::assertSame([11], $this->engine->availableWorkerIds($slot, $workers, [10 => true]));
    }

    public function test_best_candidate_prefers_the_worker_furthest_below_minimum_hours(): void
    {
        $workers = [
            10 => $this->worker(minHours: 100, assignedHours: 80), // deficit 20
            11 => $this->worker(minHours: 100, assignedHours: 40), // deficit 60 (furthest below)
            12 => $this->worker(minHours: 100, assignedHours: 96), // deficit 4
        ];

        self::assertSame(11, $this->engine->bestCandidate([10, 11, 12], $workers));
    }

    public function test_best_candidate_breaks_equal_deficit_by_lowest_cost(): void
    {
        $workers = [
            10 => $this->worker(hourlyCost: 90.0, minHours: 100, assignedHours: 50),
            11 => $this->worker(hourlyCost: 40.0, minHours: 100, assignedHours: 50),
        ];

        self::assertSame(11, $this->engine->bestCandidate([10, 11], $workers));
    }

    public function test_best_candidate_breaks_equal_deficit_and_cost_by_lowest_id(): void
    {
        $workers = [
            21 => $this->worker(hourlyCost: 50.0, minHours: 100, assignedHours: 50),
            20 => $this->worker(hourlyCost: 50.0, minHours: 100, assignedHours: 50),
        ];

        self::assertSame(20, $this->engine->bestCandidate([21, 20], $workers));
    }

    public function test_order_slots_fills_scarcest_role_first_then_date_then_shift(): void
    {
        $day1 = $this->date;
        $day2 = $this->date->addDay();

        $slots = [
            $this->slot(self::SHIFT_B, self::ROLE_GUARD, required: 6, date: $day2),
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 6, date: $day1),
            $this->slot(self::SHIFT_A, self::ROLE_SUPERVISOR, required: 1, date: $day2),
            $this->slot(self::SHIFT_A, self::ROLE_SUPERVISOR, required: 1, date: $day1),
            $this->slot(self::SHIFT_C, self::ROLE_GUARD, required: 2, date: $day1), // screener-like scarcity 2
        ];

        $ordered = $this->engine->orderSlots($slots);

        $signature = array_map(
            static fn (array $slot): string => $slot['required_count']
                .':'.$slot['work_date']->toDateString()
                .':'.$slot['shift_id'],
            $ordered,
        );

        self::assertSame([
            '1:2026-06-01:'.self::SHIFT_A,
            '1:2026-06-02:'.self::SHIFT_A,
            '2:2026-06-01:'.self::SHIFT_C,
            '6:2026-06-01:'.self::SHIFT_A,
            '6:2026-06-02:'.self::SHIFT_B,
        ], $signature);
    }

    public function test_generate_is_deterministic_for_identical_input(): void
    {
        $slots = $this->monthlySlots();

        $workersA = $this->workforce();
        $workersB = $this->workforce();

        $first = $this->engine->generate($slots, $workersA);
        $second = $this->engine->generate($slots, $workersB);

        self::assertEquals($first, $second);
        self::assertNotEmpty($first);
    }

    public function test_generate_never_assigns_more_than_two_shifts_per_day(): void
    {
        // Three single-position slots on one date, one worker eligible for all.
        $slots = [
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 1),
            $this->slot(self::SHIFT_B, self::ROLE_GUARD, required: 1),
            $this->slot(self::SHIFT_C, self::ROLE_GUARD, required: 1),
        ];

        $workers = [
            10 => $this->worker(
                role: self::ROLE_GUARD,
                shifts: [self::SHIFT_A, self::SHIFT_B, self::SHIFT_C],
                maxHours: 240,
            ),
        ];

        $assignments = $this->engine->generate($slots, $workers);

        self::assertCount(2, $assignments);
        self::assertSame(2, $workers[10]['shifts_per_date'][$this->date->toDateString()]);
        self::assertSame(16, $workers[10]['assigned_hours']);
    }

    public function test_generate_never_exceeds_max_monthly_hours(): void
    {
        // Max 16 hours => at most two 8-hour shifts across the whole month.
        $slots = [
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 1, date: $this->date),
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 1, date: $this->date->addDay()),
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 1, date: $this->date->addDays(2)),
        ];

        $workers = [
            10 => $this->worker(
                role: self::ROLE_GUARD,
                days: [0, 1, 2, 3, 4, 5, 6],
                maxHours: 16,
            ),
        ];

        $assignments = $this->engine->generate($slots, $workers);

        self::assertCount(2, $assignments);
        self::assertSame(16, $workers[10]['assigned_hours']);
    }

    public function test_generate_marks_every_assignment_as_auto(): void
    {
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 1)];
        $workers = [10 => $this->worker(role: self::ROLE_GUARD)];

        $assignments = $this->engine->generate($slots, $workers);

        self::assertCount(1, $assignments);
        self::assertSame(AssignmentSource::Auto->value, $assignments[0]['source']);
        self::assertSame(10, $assignments[0]['worker_id']);
        self::assertSame(self::SHIFT_A, $assignments[0]['shift_id']);
        self::assertTrue($assignments[0]['work_date']->equalTo($this->date));
    }

    public function test_validate_coverage_reports_only_understaffed_slots(): void
    {
        $slots = [
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 2),
            $this->slot(self::SHIFT_A, self::ROLE_SUPERVISOR, required: 1),
        ];

        $workers = [
            10 => $this->worker(role: self::ROLE_GUARD),
            11 => $this->worker(role: self::ROLE_GUARD),
            20 => $this->worker(role: self::ROLE_SUPERVISOR),
        ];

        // Guard slot only one of two filled; supervisor slot fully covered.
        $assignments = [
            ['worker_id' => 10, 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => 'auto'],
            ['worker_id' => 20, 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => 'auto'],
        ];

        $shortages = $this->engine->validateCoverage($slots, $assignments, $workers);

        self::assertCount(1, $shortages);
        self::assertSame(self::ROLE_GUARD, $shortages[0]['role_id']);
        self::assertSame(2, $shortages[0]['required']);
        self::assertSame(1, $shortages[0]['assigned']);
    }

    public function test_report_hours_shortfalls_lists_workers_below_minimum(): void
    {
        $workers = [
            10 => $this->worker(minHours: 100, assignedHours: 100), // met
            11 => $this->worker(minHours: 120, assignedHours: 80),  // short by 40
            12 => $this->worker(minHours: 90, assignedHours: 96),   // above min
        ];

        $shortfalls = $this->engine->reportHoursShortfalls($workers);

        self::assertCount(1, $shortfalls);
        self::assertSame(11, $shortfalls[0]['worker_id']);
        self::assertSame(120, $shortfalls[0]['min_hours']);
        self::assertSame(80, $shortfalls[0]['scheduled_hours']);
    }

    public function test_generate_leaves_unfillable_positions_for_coverage_to_report(): void
    {
        // Demand for two guards but only one eligible worker exists.
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 2)];
        $workers = [10 => $this->worker(role: self::ROLE_GUARD)];

        $assignments = $this->engine->generate($slots, $workers);
        $shortages = $this->engine->validateCoverage($slots, $assignments, $workers);

        self::assertCount(1, $assignments);
        self::assertCount(1, $shortages);
        self::assertSame(1, $shortages[0]['assigned']);
        self::assertSame(2, $shortages[0]['required']);
    }

    /**
     * Build a single staffing slot for the fixed test date.
     *
     * @return array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}
     */
    private function slot(
        int $shiftId,
        int $roleId,
        int $required = 1,
        ?CarbonImmutable $date = null,
        int $durationHours = 8,
    ): array {
        return [
            'work_date' => $date ?? $this->date,
            'shift_id' => $shiftId,
            'role_id' => $roleId,
            'required_count' => $required,
            'duration_hours' => $durationHours,
        ];
    }

    /**
     * Build a worker working-set entry, defaulting to fully eligible for the
     * fixed test date and shift A.
     *
     * @param  list<int>|null  $days
     * @param  list<int>|null  $shifts
     * @param  array<string, int>  $shiftsPerDate
     * @return array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>}
     */
    private function worker(
        int $role = self::ROLE_GUARD,
        float $hourlyCost = 50.0,
        int $minHours = 0,
        int $maxHours = 240,
        ?array $days = null,
        ?array $shifts = null,
        int $assignedHours = 0,
        array $shiftsPerDate = [],
    ): array {
        $days ??= [$this->dayOfWeek];
        $shifts ??= [self::SHIFT_A];

        return [
            'role_id' => $role,
            'hourly_cost' => $hourlyCost,
            'min_hours' => $minHours,
            'max_hours' => $maxHours,
            'days' => array_fill_keys($days, true),
            'shifts' => array_fill_keys($shifts, true),
            'assigned_hours' => $assignedHours,
            'shifts_per_date' => $shiftsPerDate,
        ];
    }

    /**
     * A small but non-trivial set of slots for the determinism test.
     *
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
     */
    private function monthlySlots(): array
    {
        $slots = [];

        for ($day = 0; $day < 3; $day++) {
            $date = $this->date->addDays($day);

            foreach ([self::SHIFT_A, self::SHIFT_B] as $shiftId) {
                $slots[] = $this->slot($shiftId, self::ROLE_GUARD, required: 2, date: $date);
                $slots[] = $this->slot($shiftId, self::ROLE_SUPERVISOR, required: 1, date: $date);
            }
        }

        return $slots;
    }

    /**
     * A workforce with overlapping availability so scoring tie-breaks matter.
     *
     * @return array<int, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>}>
     */
    private function workforce(): array
    {
        $allDays = [0, 1, 2, 3, 4, 5, 6];
        $allShifts = [self::SHIFT_A, self::SHIFT_B, self::SHIFT_C];

        $workers = [];

        for ($id = 1; $id <= 6; $id++) {
            $workers[$id] = $this->worker(
                role: self::ROLE_GUARD,
                hourlyCost: 40.0 + $id,
                minHours: 40,
                maxHours: 240,
                days: $allDays,
                shifts: $allShifts,
            );
        }

        for ($id = 7; $id <= 9; $id++) {
            $workers[$id] = $this->worker(
                role: self::ROLE_SUPERVISOR,
                hourlyCost: 60.0 + $id,
                minHours: 40,
                maxHours: 240,
                days: $allDays,
                shifts: $allShifts,
            );
        }

        return $workers;
    }

    private function otherDayOfWeek(): int
    {
        return ($this->dayOfWeek + 1) % 7;
    }
}
