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
        private RosterReportPresenter $presenter,
    ) {}

    /**
     * Generate a roster preview payload for the requested period.
     *
     * @param int $year
     * @param int $month
     * @return array{
     *     year: int,
     *     month: int,
     *     assignments: AnonymousResourceCollection,
     *     reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>},
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
            'reports' => $this->presenter->reports($result->coverageShortages, $result->hoursShortfalls),
            'summary' => $this->presenter->summary(
                count($result->assignments),
                $result->coverageShortages,
                $result->hoursShortfalls,
            ),
        ];
    }

    /**
     * Enrich an assignment with the worker, shift, and role data.
     * 
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
            'role_id' => $role?->id,
            'role_name' => $role?->name,
            'work_date' => $assignment['work_date']->toDateString(),
            'source' => $assignment['source'],
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
