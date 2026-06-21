<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use App\Services\Rostering\RosteringEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RosteringEngineTest extends TestCase
{
    private const SUPERVISOR = 1;
    private const SCREENER = 2;
    private const SHIFT = 10;

    private CarbonImmutable $date;
    private int $dow;

    protected function setUp(): void
    {
        $this->date = CarbonImmutable::parse('2026-01-01');
        $this->dow = $this->date->dayOfWeek;
    }

    /**
     * @param  array<int, array<int, true>>  $availability
     */
    private function worker(
        int $roleId,
        array $availability,
        int $minHours = 0,
        int $maxHours = 100,
        float $hourlyCost = 0.0,
        int $assignedHours = 0,
        array $shiftsPerDate = [],
    ): RosterWorker {
        return new RosterWorker(
            roleId: $roleId,
            hourlyCost: $hourlyCost,
            minHours: $minHours,
            maxHours: $maxHours,
            availability: $availability,
            assignedHours: $assignedHours,
            shiftsPerDate: $shiftsPerDate,
        );
    }

    private function slot(int $roleId, int $required, int $duration = 8, int $shiftId = self::SHIFT): RosterSlot
    {
        return new RosterSlot(
            workDate: $this->date,
            shiftId: $shiftId,
            roleId: $roleId,
            requiredCount: $required,
            durationHours: $duration,
        );
    }

    public function testAvailableWorkerIds(): void
    {
        $available = [$this->dow => [self::SHIFT => true]];
        $slot = $this->slot(self::SUPERVISOR, 1);

        $workers = [
            'w1' => $this->worker(self::SUPERVISOR, $available),
            'w2' => $this->worker(self::SUPERVISOR, $available),
            'wrongRole' => $this->worker(self::SCREENER, $available),
            'notAvailable' => $this->worker(self::SUPERVISOR, [$this->dow => [99 => true]]),
            'maxedHours' => $this->worker(self::SUPERVISOR, $available, maxHours: 4),
            'atDayCeiling' => $this->worker(self::SUPERVISOR, $available, shiftsPerDate: [$this->date->toDateString() => 2]),
        ];

        $engine = new RosteringEngine();

        // Only the two clean supervisors qualify; every constraint filters the rest.
        $this->assertSame(['w1', 'w2'], $engine->availableWorkerIds($slot, $workers));

        // Already-assigned workers are excluded via the third argument.
        $this->assertSame(['w2'], $engine->availableWorkerIds($slot, $workers, ['w1' => true]));
    }

    public function testBestCandidate(): void
    {
        $available = [$this->dow => [self::SHIFT => true]];
        $engine = new RosteringEngine();

        // Furthest below the contracted minimum (proportionally) wins.
        $workers = [
            'far' => $this->worker(self::SUPERVISOR, $available, minHours: 100, assignedHours: 0),  // shortfall 1.0
            'near' => $this->worker(self::SUPERVISOR, $available, minHours: 100, assignedHours: 50), // shortfall 0.5
        ];
        $this->assertSame('far', $engine->bestCandidate(['near', 'far'], $workers));

        // On a tie (no contracted minimum → shortfall 0), the lowest id wins deterministically.
        $tied = [
            'b' => $this->worker(self::SUPERVISOR, $available),
            'a' => $this->worker(self::SUPERVISOR, $available),
        ];
        $this->assertSame('a', $engine->bestCandidate(['b', 'a'], $tied));
    }

    public function testOrderSlots(): void
    {
        $engine = new RosteringEngine();

        $general = $this->slot(self::SCREENER, 6, shiftId: 1);
        $screener = $this->slot(self::SCREENER, 2, shiftId: 2);
        $supervisor = $this->slot(self::SUPERVISOR, 1, shiftId: 3);

        $ordered = $engine->orderSlots([$general, $screener, $supervisor]);

        // Scarcity-first: smallest required_count drains the shared pool first.
        $this->assertSame([1, 2, 6], array_map(static fn (RosterSlot $s): int => $s->requiredCount, $ordered));
    }

    public function testGenerate(): void
    {
        $available = [$this->dow => [self::SHIFT => true]];

        $workers = [
            'w1' => $this->worker(self::SUPERVISOR, $available),
            'w2' => $this->worker(self::SUPERVISOR, $available),
            'screener' => $this->worker(self::SCREENER, $available),
        ];
        // Demand 2 supervisors (both fillable) and 2 screeners (only 1 worker).
        $slots = [
            $this->slot(self::SUPERVISOR, 2),
            $this->slot(self::SCREENER, 2),
        ];

        $engine = new RosteringEngine();
        $assignments = $engine->generate($slots, $workers);

        // 2 supervisor positions + 1 fillable screener position = 3 rows.
        $this->assertCount(3, $assignments);

        foreach ($assignments as $row) {
            $this->assertSame(self::SHIFT, $row['shift_id']);
            $this->assertSame(AssignmentSource::Auto->value, $row['source']);
            // Every assigned worker matches the slot role and was available.
            $this->assertArrayHasKey($row['worker_id'], $workers);
        }

        // The two supervisors are both placed; the lone screener is placed once.
        $byWorker = array_count_values(array_column($assignments, 'worker_id'));
        $this->assertSame(['w1' => 1, 'w2' => 1, 'screener' => 1], $byWorker);

        // Counters were mutated in place during construction.
        $this->assertSame(8, $workers['w1']->assignedHours);
        $this->assertSame(1, $workers['w1']->shiftsPerDate[$this->date->toDateString()]);

        // Determinism: identical input (fresh worker state) yields identical output.
        $freshWorkers = [
            'w1' => $this->worker(self::SUPERVISOR, $available),
            'w2' => $this->worker(self::SUPERVISOR, $available),
            'screener' => $this->worker(self::SCREENER, $available),
        ];
        $rerun = $engine->generate($slots, $freshWorkers);
        $this->assertSame(
            array_column($assignments, 'worker_id'),
            array_column($rerun, 'worker_id'),
        );
    }

    public function testValidateCoverage(): void
    {
        $available = [$this->dow => [self::SHIFT => true]];
        $workers = [
            'w1' => $this->worker(self::SUPERVISOR, $available),
        ];

        $engine = new RosteringEngine();

        $slots = [$this->slot(self::SUPERVISOR, 2)];
        $assignments = [[
            'worker_id' => 'w1',
            'shift_id' => self::SHIFT,
            'work_date' => $this->date,
            'source' => AssignmentSource::Auto->value,
        ]];

        // Demand 2, filled 1 → one shortage row carrying the deficit.
        $shortages = $engine->validateCoverage($slots, $assignments, $workers);
        $this->assertCount(1, $shortages);
        $this->assertSame(2, $shortages[0]['required']);
        $this->assertSame(1, $shortages[0]['assigned']);
        $this->assertSame(self::SUPERVISOR, $shortages[0]['role_id']);

        // Fully covered demand reports no shortage.
        $covered = [$this->slot(self::SUPERVISOR, 1)];
        $this->assertSame([], $engine->validateCoverage($covered, $assignments, $workers));
    }

    public function testReportHoursShortfalls(): void
    {
        $available = [$this->dow => [self::SHIFT => true]];
        $workers = [
            'under' => $this->worker(self::SUPERVISOR, $available, minHours: 160, assignedHours: 120),
            'met' => $this->worker(self::SUPERVISOR, $available, minHours: 100, assignedHours: 100),
            'over' => $this->worker(self::SUPERVISOR, $available, minHours: 80, assignedHours: 120),
        ];

        $engine = new RosteringEngine();
        $shortfalls = $engine->reportHoursShortfalls($workers);

        // Only the under-minimum worker is flagged.
        $this->assertCount(1, $shortfalls);
        $this->assertSame('under', $shortfalls[0]['worker_id']);
        $this->assertSame(160, $shortfalls[0]['min_hours']);
        $this->assertSame(120, $shortfalls[0]['scheduled_hours']);
    }
}
