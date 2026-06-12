<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Services\Rostering\Data\OptimizerConfig;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use App\Services\Rostering\RosteringEngine;
use App\Services\Rostering\SimulatedAnnealingOptimizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SimulatedAnnealingOptimizerTest extends TestCase
{
    private const int ROLE_GUARD = 1;

    private const int ROLE_SUPERVISOR = 2;

    private const int SHIFT_A = 1;

    private const int SHIFT_B = 2;

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

    public function test_replaces_an_expensive_worker_with_a_cheaper_eligible_one(): void
    {
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD)];

        $workers = [
            10 => $this->worker(hourlyCost: 60.0),
            20 => $this->worker(hourlyCost: 35.0),
        ];

        // Greedy picks the lowest id on tied shortfalls, i.e. the expensive one.
        $assignments = $this->engine->generate($slots, $workers);
        self::assertSame('10', $assignments[0]['worker_id']);

        $optimized = $this->optimizer(lambda: 0.0)->optimize($slots, $workers, $assignments);

        self::assertSame('20', $optimized[0]['worker_id']);
        self::assertSame(8, $workers[20]->assignedHours);
        self::assertSame(0, $workers[10]->assignedHours);
    }

    public function test_a_cheaper_but_ineligible_worker_never_takes_the_slot(): void
    {
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD)];

        $workers = [
            10 => $this->worker(hourlyCost: 60.0),
            // Cheaper but wrong role.
            20 => $this->worker(role: self::ROLE_SUPERVISOR, hourlyCost: 30.0),
            // Cheaper but unavailable on this weekday.
            21 => $this->worker(hourlyCost: 30.0, days: [($this->dayOfWeek + 1) % 7]),
            // Cheaper but already at max hours.
            22 => $this->worker(hourlyCost: 30.0, maxHours: 8, assignedHours: 8),
        ];

        $assignments = $this->engine->generate($slots, $workers);
        $optimized = $this->optimizer(lambda: 0.0)->optimize($slots, $workers, $assignments);

        self::assertSame('10', $optimized[0]['worker_id']);
    }

    public function test_a_cost_saving_move_that_worsens_a_shortfall_is_rejected_under_high_lambda(): void
    {
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD)];

        $workers = [
            // Expensive, and the slot is exactly what meets their minimum.
            10 => $this->worker(hourlyCost: 60.0, minHours: 8),
            // Cheap with no contracted floor.
            20 => $this->worker(hourlyCost: 35.0),
        ];

        $assignments = $this->engine->generate($slots, $workers);
        self::assertSame('10', $assignments[0]['worker_id']);

        // Saving 200 costs an 8-hour shortfall: at lambda 1000 the move loses.
        $optimized = $this->optimizer(lambda: 1000.0)->optimize($slots, $workers, $assignments);

        self::assertSame('10', $optimized[0]['worker_id']);
        self::assertSame(8, $workers[10]->assignedHours);
        self::assertSame(0, $workers[20]->assignedHours);
    }

    public function test_skips_optimization_when_coverage_is_below_the_threshold(): void
    {
        // Demand 10 positions but only 5 filled: 50% < 85% threshold.
        $slots = [
            $this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 5),
            $this->slot(self::SHIFT_B, self::ROLE_GUARD, required: 5),
        ];

        $workers = [
            10 => $this->worker(hourlyCost: 60.0, assignedHours: 8, shiftsPerDate: [$this->date->toDateString() => 1]),
            20 => $this->worker(hourlyCost: 35.0),
        ];

        $assignments = [
            ['worker_id' => '10', 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => AssignmentSource::Auto->value],
        ];

        // Pad to 5 filled with distinct workers so duplicates stay legal.
        for ($id = 11; $id <= 14; $id++) {
            $workers[$id] = $this->worker(hourlyCost: 60.0, assignedHours: 8, shiftsPerDate: [$this->date->toDateString() => 1]);
            $assignments[] = ['worker_id' => (string) $id, 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => AssignmentSource::Auto->value];
        }

        $optimized = $this->optimizer(lambda: 0.0)->optimize($slots, $workers, $assignments);

        self::assertSame($assignments, $optimized);
        self::assertSame(0, $workers[20]->assignedHours);
        self::assertSame(8, $workers[10]->assignedHours);
    }

    public function test_optimization_preserves_every_hard_constraint_and_coverage(): void
    {
        [$slots, $workers] = $this->monthFixture();

        $assignments = $this->engine->generate($slots, $workers);
        $objectiveBefore = $this->objective($workers, lambda: 50.0);
        $coverageBefore = $this->coverageSignature($assignments, $workers);

        $optimized = $this->optimizer(lambda: 50.0)->optimize($slots, $workers, $assignments);

        // Coverage invariant: the exact multiset of filled positions is unchanged.
        self::assertSame($coverageBefore, $this->coverageSignature($optimized, $workers));

        // Objective never worse than the greedy starting point.
        self::assertLessThanOrEqual($objectiveBefore + 1e-6, $this->objective($workers, lambda: 50.0));

        $this->assertHardConstraintsHold($slots, $optimized, $workers);
    }

    public function test_optimization_is_deterministic_for_identical_input(): void
    {
        [$slotsA, $workersA] = $this->monthFixture();
        [$slotsB, $workersB] = $this->monthFixture();

        $first = $this->optimizer(lambda: 50.0)->optimize($slotsA, $workersA, $this->engine->generate($slotsA, $workersA));
        $second = $this->optimizer(lambda: 50.0)->optimize($slotsB, $workersB, $this->engine->generate($slotsB, $workersB));

        self::assertEquals($first, $second);
        self::assertNotEmpty($first);
    }

    public function test_manual_rows_are_pinned_and_block_duplicate_placement(): void
    {
        $slots = [$this->slot(self::SHIFT_A, self::ROLE_GUARD, required: 2)];

        $workers = [
            // Expensive auto occupant.
            10 => $this->worker(hourlyCost: 60.0, assignedHours: 8, shiftsPerDate: [$this->date->toDateString() => 1]),
            // Cheap manual occupant of the same slot: must stay, and must not be
            // duplicated into the second position.
            30 => $this->worker(hourlyCost: 20.0, assignedHours: 8, shiftsPerDate: [$this->date->toDateString() => 1]),
        ];

        $assignments = [
            ['worker_id' => '30', 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => AssignmentSource::Manual->value],
            ['worker_id' => '10', 'shift_id' => self::SHIFT_A, 'work_date' => $this->date, 'source' => AssignmentSource::Auto->value],
        ];

        $optimized = $this->optimizer(lambda: 0.0)->optimize($slots, $workers, $assignments);

        self::assertSame('30', $optimized[0]['worker_id']);
        self::assertSame(AssignmentSource::Manual->value, $optimized[0]['source']);
        self::assertSame('10', $optimized[1]['worker_id']);
    }

    public function test_worker_counters_stay_consistent_with_the_returned_assignments(): void
    {
        [$slots, $workers] = $this->monthFixture();

        $optimized = $this->optimizer(lambda: 50.0)->optimize($slots, $workers, $this->engine->generate($slots, $workers));

        $recomputedHours = [];

        foreach ($optimized as $assignment) {
            $recomputedHours[(string) $assignment['worker_id']] = ($recomputedHours[(string) $assignment['worker_id']] ?? 0) + 8;
        }

        foreach ($workers as $workerId => $worker) {
            self::assertSame(
                $recomputedHours[(string) $workerId] ?? 0,
                $worker->assignedHours,
                "Worker {$workerId} counter drifted from the returned assignments.",
            );
        }
    }

    /**
     * Assert that every hard constraint holds for the given assignments.
     *
     * @param  list<RosterSlot>  $slots
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  array<int|string, RosterWorker>  $workers
     */
    private function assertHardConstraintsHold(array $slots, array $assignments, array $workers): void
    {
        $roleBySlot = [];

        foreach ($slots as $slot) {
            $roleBySlot[$slot->workDate->toDateString().'|'.$slot->shiftId.'|'.$slot->roleId] = true;
        }

        $hours = [];
        $shiftsPerDay = [];
        $seenSlots = [];

        foreach ($assignments as $assignment) {
            $workerId = (string) $assignment['worker_id'];
            $worker = $workers[$workerId];
            $date = $assignment['work_date'];
            $dateKey = $date->toDateString();

            // Role-matching slot exists for this assignment.
            self::assertArrayHasKey($dateKey.'|'.$assignment['shift_id'].'|'.$worker->roleId, $roleBySlot);

            // Availability for the weekday and shift.
            self::assertTrue(isset($worker->availability[$date->dayOfWeek][$assignment['shift_id']]));

            // Unique (worker, date, shift).
            $slotKey = $workerId.'|'.$dateKey.'|'.$assignment['shift_id'];
            self::assertArrayNotHasKey($slotKey, $seenSlots);
            $seenSlots[$slotKey] = true;

            $hours[$workerId] = ($hours[$workerId] ?? 0) + 8;
            $shiftsPerDay[$workerId][$dateKey] = ($shiftsPerDay[$workerId][$dateKey] ?? 0) + 1;
        }

        foreach ($hours as $workerId => $assignedHours) {
            self::assertLessThanOrEqual($workers[$workerId]->maxHours, $assignedHours);
        }

        foreach ($shiftsPerDay as $days) {
            foreach ($days as $count) {
                self::assertLessThanOrEqual(RosteringEngine::MAX_SHIFTS_PER_DAY, $count);
            }
        }
    }

    /**
     * Sorted multiset of filled (date, shift, role) positions.
     *
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  array<int|string, RosterWorker>  $workers
     * @return list<string>
     */
    private function coverageSignature(array $assignments, array $workers): array
    {
        $signature = array_map(
            fn (array $assignment): string => $assignment['work_date']->toDateString()
                .'|'.$assignment['shift_id']
                .'|'.$workers[(string) $assignment['worker_id']]->roleId,
            $assignments,
        );

        sort($signature);

        return $signature;
    }

    /**
     * The optimizer's objective recomputed from the live worker counters.
     *
     * @param  array<int|string, RosterWorker>  $workers
     */
    private function objective(array $workers, float $lambda): float
    {
        $total = 0.0;

        foreach ($workers as $worker) {
            $total += $worker->hourlyCost * $worker->assignedHours;
            $total += $lambda * max(0, $worker->minHours - $worker->assignedHours);
        }

        return $total;
    }

    /**
     * Build an optimizer with a test-sized iteration budget and a fixed seed.
     */
    private function optimizer(float $lambda): SimulatedAnnealingOptimizer
    {
        return new SimulatedAnnealingOptimizer($this->engine, new OptimizerConfig(
            lambda: $lambda,
            initialTemperature: 50.0,
            coolingRate: 0.995,
            maxIterations: 2_000,
        ));
    }

    /**
     * A fully-staffable multi-day fixture with a spread of hourly costs so the
     * optimizer has real savings to find.
     *
     * @return array{0: list<RosterSlot>, 1: array<int, RosterWorker>}
     */
    private function monthFixture(): array
    {
        $slots = [];

        for ($day = 0; $day < 5; $day++) {
            $date = $this->date->addDays($day);

            foreach ([self::SHIFT_A, self::SHIFT_B] as $shiftId) {
                $slots[] = $this->slot($shiftId, self::ROLE_GUARD, required: 2, date: $date);
                $slots[] = $this->slot($shiftId, self::ROLE_SUPERVISOR, required: 1, date: $date);
            }
        }

        $allDays = [0, 1, 2, 3, 4, 5, 6];
        $allShifts = [self::SHIFT_A, self::SHIFT_B];

        $workers = [];

        for ($id = 10; $id <= 17; $id++) {
            $workers[$id] = $this->worker(
                hourlyCost: 30.0 + ($id % 5) * 10,
                minHours: 24,
                maxHours: 80,
                days: $allDays,
                shifts: $allShifts,
            );
        }

        for ($id = 20; $id <= 23; $id++) {
            $workers[$id] = $this->worker(
                role: self::ROLE_SUPERVISOR,
                hourlyCost: 50.0 + ($id % 3) * 15,
                minHours: 24,
                maxHours: 80,
                days: $allDays,
                shifts: $allShifts,
            );
        }

        return [$slots, $workers];
    }

    /**
     * Build a single staffing slot for the fixed test date.
     */
    private function slot(
        int $shiftId,
        int $roleId,
        int $required = 1,
        ?CarbonImmutable $date = null,
        int $durationHours = 8,
    ): RosterSlot {
        return new RosterSlot(
            workDate: $date ?? $this->date,
            shiftId: $shiftId,
            roleId: $roleId,
            requiredCount: $required,
            durationHours: $durationHours,
        );
    }

    /**
     * Build a worker working-set entry, defaulting to fully eligible for the
     * fixed test date and shift A.
     *
     * @param  list<int>|null  $days
     * @param  list<int>|null  $shifts
     * @param  array<string, int>  $shiftsPerDate
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
    ): RosterWorker {
        $days ??= [$this->dayOfWeek];
        $shifts ??= [self::SHIFT_A];

        $availability = [];

        foreach ($days as $day) {
            foreach ($shifts as $shift) {
                $availability[$day][$shift] = true;
            }
        }

        return new RosterWorker(
            roleId: $role,
            hourlyCost: $hourlyCost,
            minHours: $minHours,
            maxHours: $maxHours,
            availability: $availability,
            assignedHours: $assignedHours,
            shiftsPerDate: $shiftsPerDate,
        );
    }
}
