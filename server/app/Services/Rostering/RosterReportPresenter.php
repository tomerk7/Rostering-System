<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the API-facing reports and summary shared by roster previews and saved
 * rosters, so both surfaces present identical coverage and hours payloads.
 */
final readonly class RosterReportPresenter
{
    /**
     * Build the enriched reports payload.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @param  list<array{worker_id: int, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     * @return array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}
     */
    public function reports(array $coverageShortages, array $hoursShortfalls): array
    {
        $lookups = $this->warmLookups($coverageShortages, $hoursShortfalls);

        return [
            'coverage_shortages' => array_map(
                fn (array $shortage): array => $this->enrichCoverageShortage($shortage, $lookups),
                $coverageShortages,
            ),
            'hours_shortfalls' => array_map(
                fn (array $shortfall): array => $this->enrichHoursShortfall($shortfall, $lookups),
                $hoursShortfalls,
            ),
        ];
    }

    /**
     * Build the roster summary counters.
     *
     * @param  list<array<string, mixed>>  $coverageShortages
     * @param  list<array<string, mixed>>  $hoursShortfalls
     * @return array<string, mixed>
     */
    public function summary(int $assignmentCount, array $coverageShortages, array $hoursShortfalls): array
    {
        return [
            'assignment_count' => $assignmentCount,
            'coverage_shortage_count' => count($coverageShortages),
            'hours_shortfall_count' => count($hoursShortfalls),
            'has_coverage_shortages' => $coverageShortages !== [],
            'has_hours_shortfalls' => $hoursShortfalls !== [],
        ];
    }

    /**
     * Enrich a coverage shortage with the shift and role names.
     *
     * @param  array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}  $shortage
     * @param  array{workers: Collection<int, Worker>, shifts: Collection<int, Shift>, roles: Collection<int, Role>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichCoverageShortage(array $shortage, array $lookups): array
    {
        $shift = $lookups['shifts']->get($shortage['shift_id']);
        $role = $lookups['roles']->get($shortage['role_id']);

        return [
            'work_date' => $shortage['work_date']->toDateString(),
            'shift_id' => $shortage['shift_id'],
            'shift_code' => $shift?->code,
            'role_id' => $shortage['role_id'],
            'role_name' => $role?->name,
            'required' => $shortage['required'],
            'assigned' => $shortage['assigned'],
            'missing' => $shortage['required'] - $shortage['assigned'],
        ];
    }

    /**
     * Enrich a hours shortfall with the worker name.
     *
     * @param  array{worker_id: int, min_hours: int, scheduled_hours: int}  $shortfall
     * @param  array{workers: Collection<int, Worker>, shifts: Collection<int, Shift>, roles: Collection<int, Role>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichHoursShortfall(array $shortfall, array $lookups): array
    {
        $worker = $lookups['workers']->get($shortfall['worker_id']);

        return [
            'worker_id' => $shortfall['worker_id'],
            'worker_name' => $worker?->full_name,
            'min_hours' => $shortfall['min_hours'],
            'scheduled_hours' => $shortfall['scheduled_hours'],
            'shortfall_hours' => $shortfall['min_hours'] - $shortfall['scheduled_hours'],
        ];
    }

    /**
     * Resolve the workers, shifts, and roles referenced by the reports.
     *
     * @param  list<array{shift_id: int, role_id: int}>  $coverageShortages
     * @param  list<array{worker_id: int}>  $hoursShortfalls
     * @return array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }
     */
    private function warmLookups(array $coverageShortages, array $hoursShortfalls): array
    {
        $workerIds = array_unique(array_column($hoursShortfalls, 'worker_id'));
        $shiftIds = array_unique(array_column($coverageShortages, 'shift_id'));
        $roleIds = array_unique(array_column($coverageShortages, 'role_id'));

        return [
            'workers' => Worker::query()->whereIn('id', $workerIds)->get()->keyBy('id'),
            'shifts' => Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id'),
            'roles' => Role::query()->whereIn('id', $roleIds)->get()->keyBy('id'),
        ];
    }
}
