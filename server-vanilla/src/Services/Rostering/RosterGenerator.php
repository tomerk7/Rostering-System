<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\Data\OptimizerConfig;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use App\Support\DB;
use Carbon\CarbonImmutable;
use Exception;
use PDO;

/**
 * Orchestrates roster generation and returns assignments, coverage shortages,
 * and hours shortfalls for review before saving. The engine/optimizer logic is
 * Roster generation logic; only the two data-loading
 * methods read via raw PDO instead of Eloquent / the query builder.
 */
class RosterGenerator
{
    private PDO $pdo;

    /**
     * Create a new roster generator.
     *
     * @param RosteringEngine $engine
     * @param OptimizerConfig $optimizerConfig
     * @param PDO|null $pdo
     */
    public function __construct(
        private RosteringEngine $engine = new RosteringEngine,
        private OptimizerConfig $optimizerConfig = new OptimizerConfig,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo ?? DB::connect();
    }

    /**
     * Generate the roster preview for a target month.
     *
     * @param int $year
     * @param int $month
     * @param bool $optimizeCost
     * @param float|null $balanceWeight
     * @param float|null $shortfallPenaltyPerHour
     * @return GenerationResult
     * @throws Exception
     */
    public function generate(
        int $year,
        int $month,
        bool $optimizeCost = false,
        ?float $balanceWeight = null,
        ?float $shortfallPenaltyPerHour = null,
    ): GenerationResult {
        $slots = $this->buildSlots($year, $month);
        $workers = $this->resolveWorkers();

        // The engine mutates the worker counters in place as it places
        // assignments, so $workers afterwards holds the final scheduled hours the
        // coverage and shortfall reports read from.
        $assignments = $this->engine->generate($slots, $workers);

        // Optional cost-optimization pass: swaps cheaper eligible workers into
        // already-filled positions. Coverage is invariant and the counters stay
        // consistent, so the reports below read the post-optimization state.
        if ($optimizeCost) {
            $config = $this->optimizerConfig;

            if ($balanceWeight !== null) {
                $config = $config->withBalanceWeight($balanceWeight);
            }

            if ($shortfallPenaltyPerHour !== null) {
                $config = $config->withShortfallPenaltyPerHour($shortfallPenaltyPerHour);
            }

            $assignments = (new SAOptimizer($this->engine, $config))->optimize($slots, $workers, $assignments);
        }

        return new GenerationResult(
            year: $year,
            month: $month,
            assignments: $assignments,
            coverageShortages: $this->engine->validateCoverage($slots, $assignments, $workers),
            hoursShortfalls: $this->engine->reportHoursShortfalls($workers),
        );
    }

    /**
     * Recompute coverage shortages and hours shortfalls from saved assignments.
     *
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable}>  $savedAssignments
     * @param int $year
     * @param int $month
     * @return array{
     *     coverageShortages: list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>,
     *     hoursShortfalls: list<array{worker_id: string, min_hours: int, scheduled_hours: int}>
     * }
     *
     * @throws Exception
     */
    public function recomputeReports(int $year, int $month, array $savedAssignments): array
    {
        $slots = $this->buildSlots($year, $month);
        $workers = $this->resolveWorkers();

        /** @var array<int, int> $durationHoursByShiftId */
        $durationHoursByShiftId = [];

        foreach ($slots as $slot) {
            $durationHoursByShiftId[$slot->shiftId] = $slot->durationHours;
        }

        $assignments = [];

        foreach ($savedAssignments as $savedAssignment) {
            $workerId = $savedAssignment['worker_id'];

            if (! isset($workers[$workerId])) {
                continue;
            }

            $shiftId = $savedAssignment['shift_id'];
            $durationHours = $durationHoursByShiftId[$shiftId] ?? 0;

            $workers[$workerId]->assignedHours += $durationHours;

            $assignments[] = [
                'worker_id' => $workerId,
                'shift_id' => $shiftId,
                'work_date' => $savedAssignment['work_date'],
            ];
        }

        return [
            'coverageShortages' => $this->engine->validateCoverage($slots, $assignments, $workers),
            'hoursShortfalls' => $this->engine->reportHoursShortfalls($workers),
        ];
    }

    /**
     * Expand the target month into every staffing slot by crossing each calendar
     * day with the data-driven demand in shift_role_requirements.
     *
     * @param int $year
     * @param int $month
     * @return list<RosterSlot>
     *
     * @throws Exception
     */
    private function buildSlots(int $year, int $month): array
    {
        $requirements = $this->pdo->query(
            'SELECT srr.shift_id, srr.role_id, srr.required_count, s.duration_hours
             FROM shift_role_requirements srr
             JOIN shifts s ON s.id = srr.shift_id
             WHERE srr.required_count > 0
             ORDER BY srr.shift_id, srr.role_id',
        )->fetchAll();

        if ($requirements === []) {
            return [];
        }

        $firstDay = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
        $daysInMonth = $firstDay->daysInMonth;

        $slots = [];

        for ($day = 0; $day < $daysInMonth; $day++) {
            $date = $firstDay->addDays($day);

            foreach ($requirements as $requirement) {
                $slots[] = new RosterSlot(
                    workDate: $date,
                    shiftId: (int) $requirement['shift_id'],
                    roleId: (int) $requirement['role_id'],
                    requiredCount: (int) $requirement['required_count'],
                    durationHours: (int) $requirement['duration_hours'],
                );
            }
        }

        return $slots;
    }

    /**
     * Build the worker state used by the rostering engine. Public (the only
     * deviation from the verbatim port) so the report write-back can reuse the
     * same active-worker + availability map for its validity checks.
     *
     * @return array<string, RosterWorker>
     *
     * @throws Exception
     */
    public function resolveWorkers(): array
    {
        /** @var array<string, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int}> $workerRows */
        $workerRows = [];

        $rows = $this->pdo->query(
            'SELECT workers.israeli_id, workers.role_id, contracts.hourly_cost,
                    contracts.min_monthly_hours, contracts.max_monthly_hours
             FROM workers
             JOIN contracts ON contracts.worker_id = workers.israeli_id
             WHERE workers.is_active = true AND workers.deleted_at IS NULL
             ORDER BY workers.israeli_id',
        )->fetchAll();

        foreach ($rows as $worker) {
            $workerRows[(string) $worker['israeli_id']] = [
                'role_id' => (int) $worker['role_id'],
                'hourly_cost' => (float) $worker['hourly_cost'],
                'min_hours' => (int) $worker['min_monthly_hours'],
                'max_hours' => (int) $worker['max_monthly_hours'],
            ];
        }

        /** @var array<string, array<int, array<int, true>>> $availabilityByWorker */
        $availabilityByWorker = [];

        $availabilityRows = $this->pdo->query(
            'SELECT workers.israeli_id AS worker_id, contract_availability.day_of_week,
                    contract_availability.shift_id
             FROM contract_availability
             JOIN contracts ON contracts.id = contract_availability.contract_id
             JOIN workers ON workers.israeli_id = contracts.worker_id
             WHERE workers.is_active = true AND workers.deleted_at IS NULL
             ORDER BY workers.israeli_id',
        )->fetchAll();

        foreach ($availabilityRows as $availability) {
            $workerId = (string) $availability['worker_id'];
            $dayOfWeek = (int) $availability['day_of_week'];
            $shiftId = (int) $availability['shift_id'];

            $availabilityByWorker[$workerId][$dayOfWeek][$shiftId] = true;
        }

        $workers = [];

        foreach ($workerRows as $workerId => $row) {
            $workers[$workerId] = new RosterWorker(
                roleId: $row['role_id'],
                hourlyCost: $row['hourly_cost'],
                minHours: $row['min_hours'],
                maxHours: $row['max_hours'],
                availability: $availabilityByWorker[$workerId] ?? [],
            );
        }

        return $workers;
    }
}
