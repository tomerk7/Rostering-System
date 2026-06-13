<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\ShiftRoleRequirement;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\Data\OptimizerConfig;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates roster generation and returns assignments, coverage shortages,
 * and hours shortfalls for review before saving.
 */
final readonly class RosterGenerator
{
    /**
     * Create a new roster generator.
     *
     * @param RosteringEngine $engine
     * @param OptimizerConfig $optimizerConfig
     */
    public function __construct(
        private RosteringEngine $engine,
        private OptimizerConfig $optimizerConfig = new OptimizerConfig,
    ) {}

    /**
     * Generate the roster preview for a target month.
     *
     * When $optimizeCost is set, $balanceWeight selects the distribution
     * preference for the cost pass (null uses the optimizer's default weight).
     *
     * @param int $year
     * @param int $month
     * @param bool $optimizeCost
     * @param float|null $balanceWeight
     * @return GenerationResult
     * @throws Exception
     */
    public function generate(int $year, int $month, bool $optimizeCost = false, ?float $balanceWeight = null): GenerationResult
    {
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
            $config = $balanceWeight === null
                ? $this->optimizerConfig
                : $this->optimizerConfig->withBalanceWeight($balanceWeight);

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
        $requirements = ShiftRoleRequirement::query()
            ->where('required_count', '>', 0)
            ->with('shift')
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get();

        if ($requirements->isEmpty()) {
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
                    shiftId: (int) $requirement->shift_id,
                    roleId: (int) $requirement->role_id,
                    requiredCount: (int) $requirement->required_count,
                    durationHours: (int) $requirement->shift->duration_hours,
                );
            }
        }

        return $slots;
    }

    /**
     * Build the worker state used by the rostering engine.
     *
     * @return array<string, RosterWorker>
     *
     * @throws Exception
     */
    private function resolveWorkers(): array
    {
        /** @var array<string, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int}> $workerRows */
        $workerRows = [];

        foreach (DB::table('workers')
            ->join('contracts', 'contracts.worker_id', '=', 'workers.israeli_id')
            ->where('workers.is_active', true)
            ->whereNull('workers.deleted_at')
            ->select([
                'workers.israeli_id',
                'workers.role_id',
                'contracts.hourly_cost',
                'contracts.min_monthly_hours',
                'contracts.max_monthly_hours',
            ])
            ->orderBy('workers.israeli_id')
            ->cursor() as $worker) {
            $workerRows[(string) $worker->israeli_id] = [
                'role_id' => (int) $worker->role_id,
                'hourly_cost' => (float) $worker->hourly_cost,
                'min_hours' => (int) $worker->min_monthly_hours,
                'max_hours' => (int) $worker->max_monthly_hours,
            ];
        }

        /** @var array<string, array<int, array<int, true>>> $availabilityByWorker */
        $availabilityByWorker = [];

        foreach (DB::table('contract_availability')
            ->join('contracts', 'contracts.id', '=', 'contract_availability.contract_id')
            ->join('workers', 'workers.israeli_id', '=', 'contracts.worker_id')
            ->where('workers.is_active', true)
            ->whereNull('workers.deleted_at')
            ->select(
                'workers.israeli_id AS worker_id',
                'contract_availability.day_of_week',
                'contract_availability.shift_id',
            )
            ->orderBy('workers.israeli_id')
            ->cursor() as $availability) {
            $workerId = (string) $availability->worker_id;
            $dayOfWeek = (int) $availability->day_of_week;
            $shiftId = (int) $availability->shift_id;

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
