<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\ContractAvailability;
use App\Models\ShiftRoleRequirement;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates roster generation and returns assignments, coverage shortages,
 * and hours shortfalls for review before saving.
 */
final readonly class RosterGenerator
{
    public function __construct(
        private RosteringEngine $engine,
    ) {}

    /**
     * Generate the roster preview for a target month.
     *
     *
     * @throws Exception
     */
    public function generate(int $year, int $month): GenerationResult
    {
        $feasibilityIssues = $this->checkFeasibility($year, $month);
        $slots = $this->buildSlots($year, $month);
        $workers = $this->resolveWorkers();

        // The engine mutates the worker counters in place as it places
        // assignments, so $workers afterwards holds the final scheduled hours the
        // coverage and shortfall reports read from.
        $assignments = $this->engine->generate($slots, $workers);

        return new GenerationResult(
            year: $year,
            month: $month,
            assignments: $assignments,
            coverageShortages: $this->engine->validateCoverage($slots, $assignments, $workers),
            hoursShortfalls: $this->engine->reportHoursShortfalls($workers),
            feasibilityIssues: $feasibilityIssues,
        );
    }

    /**
     * Return staffing shortages grouped by role, date, and shift.
     *
     * @return list<array{role_id: int, work_date: CarbonImmutable, shift_id: int, required_workers: int, available_workers: int}>
     *
     * @throws Exception
     */
    private function checkFeasibility(int $year, int $month): array
    {
        $requirements = ShiftRoleRequirement::query()
            ->where('required_count', '>', 0)
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        $roleIds = $requirements
            ->pluck('role_id')
            ->map(static fn ($roleId): int => (int) $roleId)
            ->unique()
            ->values()
            ->all();

        /** @var array<int, array<int, array<int, int>>> $availableByRoleDayAndShift */
        $availableByRoleDayAndShift = [];

        ContractAvailability::query()
            ->join('contracts', 'contracts.id', '=', 'contract_availability.contract_id')
            ->join('workers', 'workers.id', '=', 'contracts.worker_id')
            ->where('workers.is_active', true)
            ->whereIn('workers.role_id', $roleIds)
            ->select('workers.role_id', 'contract_availability.day_of_week', 'contract_availability.shift_id')
            ->selectRaw('COUNT(*) AS available_workers')
            ->groupBy('workers.role_id', 'contract_availability.day_of_week', 'contract_availability.shift_id')
            ->get()
            ->each(function (ContractAvailability $availability) use (&$availableByRoleDayAndShift): void {
                $roleId = (int) $availability->role_id;
                $dayOfWeek = (int) $availability->day_of_week;
                $shiftId = (int) $availability->shift_id;

                $availableByRoleDayAndShift[$roleId][$dayOfWeek][$shiftId] = (int) $availability->available_workers;
            });

        $firstDay = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
        $issues = [];

        for ($day = 0; $day < $firstDay->daysInMonth; $day++) {
            $date = $firstDay->addDays($day);

            foreach ($requirements as $requirement) {
                $roleId = (int) $requirement->role_id;
                $shiftId = (int) $requirement->shift_id;
                $requiredWorkers = (int) $requirement->required_count;
                $availableWorkers = $availableByRoleDayAndShift[$roleId][$date->dayOfWeek][$shiftId] ?? 0;

                if ($availableWorkers < $requiredWorkers) {
                    $issues[] = [
                        'role_id' => $roleId,
                        'work_date' => $date,
                        'shift_id' => $shiftId,
                        'required_workers' => $requiredWorkers,
                        'available_workers' => $availableWorkers,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Expand the target month into every staffing slot by crossing each calendar
     * day with the data-driven demand in shift_role_requirements.
     *
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
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
                $slots[] = [
                    'work_date' => $date,
                    'shift_id' => (int) $requirement->shift_id,
                    'role_id' => (int) $requirement->role_id,
                    'required_count' => (int) $requirement->required_count,
                    'duration_hours' => (int) $requirement->shift->duration_hours,
                ];
            }
        }

        return $slots;
    }

    /**
     * Build the worker state used by the rostering engine.
     *
     * @return array<int, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, availability: array<int, array<int, true>>, assigned_hours: int, shifts_per_date: array<string, int>}>
     *
     * @throws Exception
     */
    private function resolveWorkers(): array
    {
        $workers = [];

        foreach (DB::table('workers')
            ->join('contracts', 'contracts.worker_id', '=', 'workers.id')
            ->where('workers.is_active', true)
            ->select([
                'workers.id',
                'workers.role_id',
                'contracts.hourly_cost',
                'contracts.min_monthly_hours',
                'contracts.max_monthly_hours',
            ])
            ->orderBy('workers.id')
            ->cursor() as $worker) {
            $workers[(int) $worker->id] = [
                'role_id' => (int) $worker->role_id,
                'hourly_cost' => (float) $worker->hourly_cost,
                'min_hours' => (int) $worker->min_monthly_hours,
                'max_hours' => (int) $worker->max_monthly_hours,
                'availability' => [],
                'assigned_hours' => 0,
                'shifts_per_date' => [],
            ];
        }

        foreach (DB::table('contract_availability')
            ->join('contracts', 'contracts.id', '=', 'contract_availability.contract_id')
            ->join('workers', 'workers.id', '=', 'contracts.worker_id')
            ->where('workers.is_active', true)
            ->select(
                'workers.id AS worker_id',
                'contract_availability.day_of_week',
                'contract_availability.shift_id',
            )
            ->orderBy('workers.id')
            ->cursor() as $availability) {
            $workerId = (int) $availability->worker_id;
            $dayOfWeek = (int) $availability->day_of_week;
            $shiftId = (int) $availability->shift_id;

            $workers[$workerId]['availability'][$dayOfWeek][$shiftId] = true;
        }

        return $workers;
    }
}
