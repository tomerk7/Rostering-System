<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;

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
     * @param  int  $year
     * @param  int  $month
     * @return GenerationResult
     *
     * @throws Exception
     */
    public function generate(int $year, int $month): GenerationResult
    {
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
        );
    }

    /**
     * Expand the target month into every staffing slot by crossing each calendar
     * day with the data-driven demand in shift_role_requirements.
     *
     * @param  int  $year
     * @param  int  $month
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
     * @return array<int, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>}>
     * @throws Exception
     */
    private function resolveWorkers(): array
    {
        $workers = [];

        Worker::query()
            ->active()
            ->whereHas('contract')
            ->with(['contract.availableDays', 'contract.availableShiftRows'])
            ->orderBy('id')
            ->get()
            ->each(function (Worker $worker) use (&$workers): void {
                $contract = $worker->contract;

                $days = [];

                foreach ($contract->availableDays as $availableDay) {
                    $days[(int) $availableDay->day_of_week] = true;
                }

                $shifts = [];

                foreach ($contract->availableShiftRows as $availableShift) {
                    $shifts[(int) $availableShift->shift_id] = true;
                }

                $workers[(int) $worker->id] = [
                    'role_id' => (int) $worker->role_id,
                    'hourly_cost' => (float) $contract->hourly_cost,
                    'min_hours' => (int) $contract->min_monthly_hours,
                    'max_hours' => (int) $contract->max_monthly_hours,
                    'days' => $days,
                    'shifts' => $shifts,
                    'assigned_hours' => 0,
                    'shifts_per_date' => [],
                ];
            });

        return $workers;
    }
}
