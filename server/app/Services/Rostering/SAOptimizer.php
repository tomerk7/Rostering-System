<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Services\Rostering\Data\OptimizerConfig;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Post-construction simulated annealing pass that lowers the roster's total
 * cost while protecting hour distribution.
 *
 * The greedy engine decides WHICH positions get filled; this optimizer only
 * changes WHO fills them, so coverage is invariant. Every proposed move is
 * validated through the same hard-constraint check the greedy engine uses
 * (role match, availability, daily shift ceiling, max hours, unique slot), so
 * an optimized roster can never become invalid. All assignments are movable.
 *
 * The objective is multi-criteria: total cost, plus a weighted penalty for
 * min-hours shortfalls, plus a weighted penalty on the squared number of shifts
 * each worker is scheduled above their minimum. 
 */
final readonly class SAOptimizer
{
    /**
     * Guard for float comparisons when tracking the best-seen objective.
     */
    private const float EPSILON = 1e-9;

    /**
     * Hours per shift, used to express the balance penalty in whole shifts above
     * minimum so balanceWeight tunes on a human scale. Matches the seeded 8h
     * shift grid and RosterBenchmark::SHIFT_HOURS.
     */
    private const int SHIFT_HOURS = 8;

    public function __construct(
        private RosteringEngine $engine,
        private OptimizerConfig $config = new OptimizerConfig,
    ) {}

    /**
     * Optimize the assignments produced by the greedy engine and return them
     * with the same shape and row order, possibly with cheaper workers in some
     * positions. Worker counters are mutated so they stay consistent with the
     * returned rows for downstream shortfall reporting.
     *
     * When coverage is below the configured threshold the input is returned
     * unchanged: optimizing cost on a badly understaffed roster polishes a
     * result that is fundamentally incomplete.
     *
     * @param  list<RosterSlot>  $slots
     * @param  array<string, RosterWorker>  $workers
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @return list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>
     */
    public function optimize(array $slots, array $workers, array $assignments): array
    {
        $totalPositions = 0;

        foreach ($slots as $slot) {
            $totalPositions += $slot->requiredCount;
        }

        if ($totalPositions === 0 || count($assignments) / $totalPositions < $this->config->coverageThreshold) {
            return $assignments;
        }

        /** @var array<string, RosterSlot> $slotsByKey */
        $slotsByKey = [];

        // Build an O(1) lookup map so assignments can resolve their slot without scanning the full array.
        foreach ($slots as $slot) {
            $slotsByKey[$this->slotKey($slot->workDate, $slot->shiftId, $slot->roleId)] = $slot;
        }

        // Build occupancy sets and the movable entries list from all assignments.
        /** @var array<string, array<string, true>> $slotWorkers */
        $slotWorkers = [];

        /** @var list<array{slot: RosterSlot, workerId: string, index: int}> $entries */
        $entries = [];

        foreach ($assignments as $index => $assignment) {
            $workerId = (string) $assignment['worker_id'];
            $key = $this->slotKey($assignment['work_date'], $assignment['shift_id'], $workers[$workerId]->roleId);

            $slotWorkers[$key][$workerId] = true;

            if (isset($slotsByKey[$key])) {
                // Slot give us duration/role/date for delta calculations.
                // WorkerId who currently assigned to this slot.(Will be muated during SA moves).
                // Index of the assignment in the original assignments array.
                $entries[] = ['slot' => $slotsByKey[$key], 'workerId' => $workerId, 'index' => $index];
            }
        }

        if (empty($entries)) {
            return $assignments;
        }

        $currentObjective = $this->objective($workers);
        $bestObjective = $currentObjective;
        $bestWorkerIds = array_column($entries, 'workerId');

        // Seeded Mersenne Twister gives a deterministic yet high-quality random sequence across runs.
        $randomizer = new Randomizer(new Mt19937($this->config->seed));
        $temperature = $this->config->initialTemperature;
        $entryCount = count($entries);

        for ($iteration = 0; $iteration < $this->config->maxIterations; $iteration++) {
            if ($temperature < $this->config->minTemperature) {
                break;
            }

            // Determine the type of move to make.
            // Replace: swap the worker in one random filled position for another eligible worker.
            // Swap: exchange the workers of two same-role filled positions.
            $moveIsReplace = $entryCount < 2 || $randomizer->getInt(0, 1) === 0;

            $delta = $moveIsReplace
                ? $this->tryReplace($entries, $workers, $slotWorkers, $randomizer, $temperature)
                : $this->trySwap($entries, $workers, $slotWorkers, $randomizer, $temperature);

            if ($delta !== null) {
                $currentObjective += $delta;

                if ($currentObjective < $bestObjective - self::EPSILON) {
                    $bestObjective = $currentObjective;
                    $bestWorkerIds = array_column($entries, 'workerId');
                }
            }

            $temperature *= $this->config->coolingRate;
        }

        $this->restoreBest($entries, $workers, $slotWorkers, $bestWorkerIds, $bestObjective, $currentObjective);

        foreach ($entries as $entry) {
            $assignments[$entry['index']]['worker_id'] = $entry['workerId'];
        }

        return $assignments;
    }

    /**
     * Propose swapping the worker in one random filled position for another
     * eligible worker. Returns the accepted objective delta, or null when the
     * move found no candidate or was rejected.
     *
     * @param  list<array{slot: RosterSlot, workerId: string, index: int}>  $entries
     * @param  array<string, RosterWorker>  $workers
     * @param  array<string, array<string, true>>  $slotWorkers
     * @param  Randomizer $randomizer
     * @param  float $temperature
     * @return ?float
     */
    private function tryReplace(array &$entries, array $workers, array &$slotWorkers, Randomizer $randomizer, float $temperature): ?float
    {
        $entryIndex = $randomizer->getInt(0, count($entries) - 1);
        $slot = $entries[$entryIndex]['slot'];
        $key = $this->slotKey($slot->workDate, $slot->shiftId, $slot->roleId);

        // Direct reuse of the engine's hard-constraint check; the occupancy set
        // already excludes the current holder and everyone else in the slot.
        $candidateIds = $this->engine->availableWorkerIds($slot, $workers, $slotWorkers[$key]);

        if (empty($candidateIds)) {
            return null;
        }

        $oldId = $entries[$entryIndex]['workerId'];
        $newId = $candidateIds[$randomizer->getInt(0, count($candidateIds) - 1)];
        $durationHours = $slot->durationHours;

        // Net objective change: wage difference of swapping workers, the weighted shortfall impact on both workers' min-hours floors, and the weighted balance impact (old worker sheds hours, new worker gains them).
        $deltaCost = ($workers[$newId]->hourlyCost - $workers[$oldId]->hourlyCost) * $durationHours;
        $deltaShortfall = $this->shortfallDelta($workers[$oldId], -$durationHours) + $this->shortfallDelta($workers[$newId], $durationHours);
        $deltaBalance = $this->balanceDelta($workers[$oldId], -$durationHours)+ $this->balanceDelta($workers[$newId], $durationHours);
        $delta = $deltaCost + $this->config->shortfallPenaltyPerHour * $deltaShortfall + $this->config->balanceWeight * $deltaBalance;

        if (! $this->accepts($delta, $temperature, $randomizer)) {
            return null;
        }

        $this->vacate($workers[$oldId], $oldId, $slot, $slotWorkers, $key);
        $this->occupy($workers[$newId], $newId, $slot, $slotWorkers, $key);
        $entries[$entryIndex]['workerId'] = $newId;

        return $delta;
    }

    /**
     * Propose exchanging the workers of two same-role filled positions.
     * Returns the accepted objective delta, or null when the pair was
     * incompatible or the move was rejected.
     *
     * Both workers are tentatively vacated before eligibility is checked so a
     * worker at the daily ceiling or at max hours can still legally trade
     * places; the removal is rolled back when the move does not go through.
     *
     * @param  list<array{slot: RosterSlot, workerId: string, index: int}>  $entries
     * @param  array<string, RosterWorker>  $workers
     * @param  array<string, array<string, true>>  $slotWorkers
     * @param  Randomizer $randomizer
     * @param  float $temperature
     * 
     * @return ?float
     */
    private function trySwap(array &$entries, array $workers, array &$slotWorkers, Randomizer $randomizer, float $temperature): ?float
    {
        $lastIndex = count($entries) - 1;
        $first = $randomizer->getInt(0, $lastIndex);
        $second = $randomizer->getInt(0, $lastIndex);

        if ($first === $second) {
            return null;
        }

        $slotA = $entries[$first]['slot'];
        $slotB = $entries[$second]['slot'];
        $workerIdA = $entries[$first]['workerId'];
        $workerIdB = $entries[$second]['workerId'];

        if ($slotA->roleId !== $slotB->roleId || $workerIdA === $workerIdB) {
            return null;
        }

        $keyA = $this->slotKey($slotA->workDate, $slotA->shiftId, $slotA->roleId);
        $keyB = $this->slotKey($slotB->workDate, $slotB->shiftId, $slotB->roleId);

        // do not allow swapping workers if they are already in the same slot.(Meaning they're working in the same shift on the same shift and same day)
        if (isset($slotWorkers[$keyB][$workerIdA]) || isset($slotWorkers[$keyA][$workerIdB])) {
            return null;
        }

        $workerA = $workers[$workerIdA];
        $workerB = $workers[$workerIdB];

        // Deltas read the pre-move counters, so compute them before vacating.
        $hoursDeltaA = $slotB->durationHours - $slotA->durationHours;
        $deltaCost = ($workerA->hourlyCost - $workerB->hourlyCost) * $hoursDeltaA;
        $deltaShortfall = $this->shortfallDelta($workerA, $hoursDeltaA)
            + $this->shortfallDelta($workerB, -$hoursDeltaA);
        $deltaBalance = $this->balanceDelta($workerA, $hoursDeltaA)
            + $this->balanceDelta($workerB, -$hoursDeltaA);
        $delta = $deltaCost
            + $this->config->shortfallPenaltyPerHour * $deltaShortfall
            + $this->config->balanceWeight * $deltaBalance;

        $this->vacate($workerA, $workerIdA, $slotA, $slotWorkers, $keyA);
        $this->vacate($workerB, $workerIdB, $slotB, $slotWorkers, $keyB);

        $bothFit = $this->engine->availableWorkerIds($slotB, [$workerIdA => $workerA], $slotWorkers[$keyB]) !== []
            && $this->engine->availableWorkerIds($slotA, [$workerIdB => $workerB], $slotWorkers[$keyA]) !== [];

        if (! $bothFit || ! $this->accepts($delta, $temperature, $randomizer)) {
            $this->occupy($workerA, $workerIdA, $slotA, $slotWorkers, $keyA);
            $this->occupy($workerB, $workerIdB, $slotB, $slotWorkers, $keyB);

            return null;
        }

        // Complete the swap by updating the entries and occupancy sets.
        $this->occupy($workerA, $workerIdA, $slotB, $slotWorkers, $keyB);
        $this->occupy($workerB, $workerIdB, $slotA, $slotWorkers, $keyA);
        $entries[$first]['workerId'] = $workerIdB;
        $entries[$second]['workerId'] = $workerIdA;

        return $delta;
    }

    /**
     * Standard annealing acceptance: always take improvements, take worsening
     * moves with probability exp(-delta / T) so early high temperatures can
     * escape the greedy solution's local optimum.
     * 
     * @param  float $delta
     * @param  float $temperature
     * @param  Randomizer $randomizer
     * @return bool
     */
    private function accepts(float $delta, float $temperature, Randomizer $randomizer): bool
    {
        if ($delta < 0.0) {
            return true;
        }

        return $randomizer->nextFloat() < exp(-$delta / $temperature);
    }

    /**
     * Roll the working state back to the best-seen roster when annealing ended
     * on a worse one. Removals run before insertions so a slot whose occupants
     * were permuted across entries cannot corrupt its occupancy set.
     *
     * @param  list<array{slot: RosterSlot, workerId: string, index: int}>  $entries
     * @param  array<string, RosterWorker>  $workers
     * @param  array<string, array<string, true>>  $slotWorkers
     * @param  list<string>  $bestWorkerIds
     * @param  float $bestObjective
     * @param  float $currentObjective
     */
    private function restoreBest(array &$entries, array $workers, array &$slotWorkers, array $bestWorkerIds, float $bestObjective, float $currentObjective): void
    {
        if ($bestObjective >= $currentObjective - self::EPSILON) {
            return;
        }

        foreach ($entries as $position => $entry) {
            if ($entry['workerId'] !== $bestWorkerIds[$position]) {
                $key = $this->slotKey($entry['slot']->workDate, $entry['slot']->shiftId, $entry['slot']->roleId);
                $this->vacate($workers[$entry['workerId']], $entry['workerId'], $entry['slot'], $slotWorkers, $key);
            }
        }

        foreach ($entries as $position => $entry) {
            if ($entry['workerId'] !== $bestWorkerIds[$position]) {
                $bestId = $bestWorkerIds[$position];
                $key = $this->slotKey($entry['slot']->workDate, $entry['slot']->shiftId, $entry['slot']->roleId);
                $this->occupy($workers[$bestId], $bestId, $entry['slot'], $slotWorkers, $key);
                $entries[$position]['workerId'] = $bestId;
            }
        }
    }

    /**
     * Total objective: cost, plus the weighted min-hours shortfall penalty, plus
     * the weighted squared-excess-shifts balance penalty. Cost is read from the
     * live assigned-hours counters (which the engine accumulated from these
     * assignments), so Σ rate × duration over rows equals Σ rate × assignedHours
     * over workers. Computed once at the start; the annealing loop maintains it
     * incrementally through deltas.
     *
     * @param  array<string, RosterWorker>  $workers
     * @return float
     */
    private function objective(array $workers): float
    {
        $cost = 0.0;
        $shortfallPenalty = 0;
        $balancePenalty = 0.0;

        foreach ($workers as $worker) {
            $cost += $worker->hourlyCost * $worker->assignedHours;
            // count shortfall hours
            $shortfallPenalty += max(0, $worker->minHours - $worker->assignedHours);
            $balancePenalty += $this->balancePenalty($worker);
        }

        return $cost
            + $this->config->shortfallPenaltyPerHour * $shortfallPenalty
            + $this->config->balanceWeight * $balancePenalty;
    }

    /**
     * Change in a worker's min-hours shortfall if their assigned hours moved
     * by the given amount. Positive means the shortfall got worse.
     * 
     * @param  RosterWorker $worker
     * @param  int $hoursDelta
     * @return int
     */
    private function shortfallDelta(RosterWorker $worker, int $hoursDelta): int
    {
        return max(0, $worker->minHours - ($worker->assignedHours + $hoursDelta))
            - max(0, $worker->minHours - $worker->assignedHours);
    }

    /**
     * A worker's balance penalty: the square of how many whole shifts they are
     * scheduled above their contracted minimum. Squaring makes each extra shift
     * on an already-loaded worker hurt more than the last, so the optimizer is
     * rewarded for spreading surplus shifts across the pool. Workers at or below
     * their minimum contribute nothing.
     *
     * @param  RosterWorker $worker
     * @param  int $hoursDelta
     * @return float
     */
    private function balancePenalty(RosterWorker $worker, int $hoursDelta = 0): float
    {
        $excessShifts = max(0, ($worker->assignedHours + $hoursDelta) - $worker->minHours) / self::SHIFT_HOURS;

        return $excessShifts ** 2;
    }

    /**
     * Change in a worker's balance penalty if their assigned hours moved by the
     * given amount. Reads the pre-move counters, mirroring shortfallDelta.
     * 
     * @param  RosterWorker $worker
     * @param  int $hoursDelta
     * @return float
     */
    private function balanceDelta(RosterWorker $worker, int $hoursDelta): float
    {
        return $this->balancePenalty($worker, $hoursDelta) - $this->balancePenalty($worker);
    }

    /**
     * Remkove a worer from a slot: release counters and the occupancy set,
     * mirroring how the greedy engine claimed them.
     *
     * @param  RosterWorker $worker
     * @param  string $workerId
     * @param  RosterSlot $slot
     * @param  array<string, array<string, true>>  $slotWorkers
     * @param  string $key
     * 
     * @return void
     * @param  array<string, array<string, true>>  $slotWorkers
     */
    private function vacate(RosterWorker $worker, string $workerId, RosterSlot $slot, array &$slotWorkers, string $key): void
    {
        $dateKey = $slot->workDate->toDateString();

        $worker->assignedHours -= $slot->durationHours;
        $worker->shiftsPerDate[$dateKey]--;

        if ($worker->shiftsPerDate[$dateKey] === 0) {
            unset($worker->shiftsPerDate[$dateKey]);
        }

        unset($slotWorkers[$key][$workerId]);
    }

    /**
     * Place a worker into a slot: claim counters and the occupancy set,
     * mirroring how the greedy engine claims them.
     *
     * @param  RosterWorker $worker
     * @param  string $workerId
     * @param  RosterSlot $slot
     * @param  array<string, array<string, true>>  $slotWorkers
     * @param  string $key
     * 
     * @return void
     */
    private function occupy(RosterWorker $worker, string $workerId, RosterSlot $slot, array &$slotWorkers, string $key): void
    {
        $dateKey = $slot->workDate->toDateString();

        $worker->assignedHours += $slot->durationHours;
        $worker->shiftsPerDate[$dateKey] = ($worker->shiftsPerDate[$dateKey] ?? 0) + 1;
        $slotWorkers[$key][$workerId] = true;
    }

    /**
     * Build the stable key for one (date, shift, role) slot, matching the
     * engine's coverage aggregation key.
     * 
     * @param  CarbonImmutable $date
     * @param  int $shiftId
     * @param  int $roleId
     * @return string
     */
    private function slotKey(CarbonImmutable $date, int $shiftId, int $roleId): string
    {
        return $date->toDateString().'|'.$shiftId.'|'.$roleId;
    }
}
