<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Http\Resources\RosterAssignmentResource;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Generates the API payload for a roster preview before it is saved.
 */
final readonly class RosterPreviewService
{
    public function __construct(
        private RosterGenerator $generator,
    ) {}

    /**
     * Generate a roster preview payload for the requested period.
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     assignments: AnonymousResourceCollection,
     *     coverage_shortages: list<array<string, mixed>>,
     *     hours_shortfalls: list<array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     *
     * @throws Exception
     */
    public function generate(int $year, int $month): array
    {
        $result = $this->generator->generate($year, $month);
        $lookups = $this->warmLookups($result);

        $enrichedAssignments = array_map(
            fn (array $assignment): array => $this->enrichAssignment($assignment, $lookups),
            $result->assignments,
        );

        return [
            'year' => $result->year,
            'month' => $result->month,
            'assignments' => RosterAssignmentResource::collection($enrichedAssignments),
            'coverage_shortages' => array_map(
                fn (array $shortage): array => $this->enrichCoverageShortage($shortage, $lookups),
                $result->coverageShortages,
            ),
            'hours_shortfalls' => array_map(
                fn (array $shortfall): array => $this->enrichHoursShortfall($shortfall, $lookups),
                $result->hoursShortfalls,
            ),
            'summary' => $this->buildSummary($result),
        ];
    }

    /**
     * @param  array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}  $assignment
     * @param  array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }  $lookups
     * @return array<string, mixed>
     */
    private function enrichAssignment(array $assignment, array $lookups): array
    {
        $worker = $lookups['workers']->get($assignment['worker_id']);
        $shift = $lookups['shifts']->get($assignment['shift_id']);
        $role = $worker !== null ? $lookups['roles']->get((int) $worker->role_id) : null;

        return [
            'worker_id' => $assignment['worker_id'],
            'worker_name' => $worker?->full_name,
            'shift_id' => $assignment['shift_id'],
            'shift_code' => $shift?->code,
            'role_name' => $role?->name,
            'work_date' => $assignment['work_date']->toDateString(),
            'source' => $assignment['source'],
        ];
    }

    /**
     * Enrich a coverage shortage with the shift and role names.
     * 
     * @param array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int} $shortage
     * @param array{workers: Collection<int, Worker>, shifts: Collection<int, Shift>, roles: Collection<int, Role>} $lookups
     * @return array<string, mixed>
     */
    private function enrichCoverageShortage(array $shortage, array $lookups): array
    {
        $shift = $lookups['shifts']->get($shortage['shift_id']);
        $role = $lookups['roles']->get($shortage['role_id']);
        $missing = $shortage['required'] - $shortage['assigned'];

        return [
            'work_date' => $shortage['work_date']->toDateString(),
            'shift_id' => $shortage['shift_id'],
            'shift_code' => $shift?->code,
            'role_id' => $shortage['role_id'],
            'role_name' => $role?->name,
            'required' => $shortage['required'],
            'assigned' => $shortage['assigned'],
            'missing' => $missing,
        ];
    }

    /**
     * Enrich a hours shortfall with the worker name.
     *
     * @param array{workers: Collection<int, Worker>} $lookups
     * @return array<string, mixed>
     */
    private function enrichHoursShortfall(array $shortfall, array $lookups): array
    {
        $worker = $lookups['workers']->get($shortfall['worker_id']);
        $shortfallHours = $shortfall['min_hours'] - $shortfall['scheduled_hours'];

        return [
            'worker_id' => $shortfall['worker_id'],
            'worker_name' => $worker?->full_name,
            'min_hours' => $shortfall['min_hours'],
            'scheduled_hours' => $shortfall['scheduled_hours'],
            'shortfall_hours' => $shortfallHours,
        ];
    }

    /**
     * Build the summary of the generation result.
     *
     * @param GenerationResult $result
     * @return array<string, mixed>
     */
    private function buildSummary(GenerationResult $result): array
    {
        return [
            'assignment_count' => count($result->assignments),
            'coverage_shortage_count' => count($result->coverageShortages),
            'hours_shortfall_count' => count($result->hoursShortfalls),
            'has_coverage_shortages' => $result->hasCoverageShortages(),
            'has_hours_shortfalls' => $result->hasHoursShortfalls(),
        ];
    }

    /**
     * Warm the lookups for the generation result.
     *
     * @param GenerationResult $result
     * @return array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }
     */
    private function warmLookups(GenerationResult $result): array
    {
        $workerIds = array_unique(array_merge(
            array_column($result->assignments, 'worker_id'),
            array_column($result->hoursShortfalls, 'worker_id'),
        ));

        $shiftIds = array_unique(array_merge(
            array_column($result->assignments, 'shift_id'),
            array_column($result->coverageShortages, 'shift_id'),
        ));

        $roleIds = array_unique(array_column($result->coverageShortages, 'role_id'));

        $workers = Worker::query()
            ->whereIn('id', $workerIds)
            ->get()
            ->keyBy('id');

        foreach ($workers as $worker) {
            $roleIds[] = (int) $worker->role_id;
        }

        return [
            'workers' => $workers,
            'shifts' => Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id'),
            'roles' => Role::query()->whereIn('id', array_unique($roleIds))->get()->keyBy('id'),
        ];
    }
}
