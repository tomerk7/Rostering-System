<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Models\Contract;
use App\Models\Role;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class WorkerService
{
    private const array RELATIONS = [
        'role',
        'contract.availability.shift',
    ];

    /**
     * List workers with search, role ID, role code, and active filters.
     *
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function list(Request $request): LengthAwarePaginator
    {
        $perPage = min((int) $request->integer('per_page', 15), 1000);

        return Worker::query()
            ->with(self::RELATIONS)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $this->applySearch($query, (string) $request->string('search'));
            })
            ->when($request->filled('role_id'), function (Builder $query) use ($request): void {
                $query->where('role_id', $request->integer('role_id'));
            })
            ->when($request->filled('role_code'), function (Builder $query) use ($request): void {
                $query->whereHas('role', function (Builder $query) use ($request): void {
                    $query->where('code', (string) $request->string('role_code'));
                });
            })
            ->when($request->has('is_active'), function (Builder $query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('full_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Load worker details with relations.
     *
     * @param Worker $worker
     * @return Worker
     */
    public function loadDetails(Worker $worker): Worker
    {
        return $worker->load(self::RELATIONS);
    }

    /**
     * Create a worker with contract and availability.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Worker
    {
        return DB::transaction(function () use ($data): Worker {
            $worker = Worker::query()->create([
                'full_name' => $data['full_name'],
                'israeli_id' => $data['israeli_id'],
                'role_id' => $data['role_id'],
                'is_active' => $data['is_active']
            ]);

            /** @var Contract $contract */
            $contract = $worker->contract()->create([
                'hourly_cost' => $data['contract']['hourly_cost'],
                'min_monthly_hours' => $data['contract']['min_monthly_hours'],
                'max_monthly_hours' => $data['contract']['max_monthly_hours'],
            ]);

            $this->replaceAvailability($contract, $data);

            return $worker->load(self::RELATIONS);
        });
    }

    /**
     * Update a worker with contract and availability.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Worker $worker, array $data): Worker
    {
        return DB::transaction(function () use ($worker, $data): Worker {
            $worker->update([
                'full_name' => $data['full_name'],
                'israeli_id' => $data['israeli_id'],
                'role_id' => $data['role_id'],
                'is_active' => $data['is_active']
            ]);

            /** @var Contract $contract */
            $contract = $worker->contract()->updateOrCreate([], [
                'hourly_cost' => $data['contract']['hourly_cost'],
                'min_monthly_hours' => $data['contract']['min_monthly_hours'],
                'max_monthly_hours' => $data['contract']['max_monthly_hours'],
            ]);

            $this->replaceAvailability($contract, $data);

            return $worker->load(self::RELATIONS);
        });
    }

    /**
     * Delete a worker.
     *
     * @param Worker $worker
     * @return void
     */
    public function delete(Worker $worker): void
    {
        $worker->delete();
    }

    /**
     * Delete every worker and their roster assignments.
     *
     * @return int
     */
    public function deleteAll(): int
    {
        return DB::transaction(function (): int {
            RosterAssignment::query()->delete();

            return Worker::query()->delete();
        });
    }

    /**
     * Return reference values needed by worker forms.
     *
     * @return array{
     *     roles: Collection<int, Role>,
     *     shifts: Collection<int, Shift>,
     *     shift_role_requirements: Collection<int, array{
     *         shift_id: int,
     *         role_id: int,
     *         required_count: int,
     *         role: array{id: int, code: string, name: string}|null
     *     }>
     * }
     */
    public function referenceData(): array
    {
        return [
            'roles' => Role::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'shifts' => Shift::query()
                ->orderBy('code')
                ->get(['id', 'code', 'label', 'start_time', 'end_time', 'duration_hours']),
            'shift_role_requirements' => ShiftRoleRequirement::query()
                ->with(['role:id,code,name'])
                ->orderBy('shift_id')
                ->orderBy('role_id')
                ->get(['shift_id', 'role_id', 'required_count'])
                ->map(static fn (ShiftRoleRequirement $requirement): array => [
                    'shift_id' => $requirement->shift_id,
                    'role_id' => $requirement->role_id,
                    'required_count' => $requirement->required_count,
                    'role' => $requirement->role === null ? null : [
                        'id' => $requirement->role->id,
                        'code' => $requirement->role->code,
                        'name' => $requirement->role->name,
                    ],
                ])
                ->values(),
        ];
    }

    /**
     * Apply search to the query.
     *
     * @param Builder $query
     * @param string $search
     * @return void
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%' . mb_strtolower($search) . '%';

        $query->where(function (Builder $query) use ($term, $search): void {
            $query
                ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                ->orWhere('israeli_id', 'like', "%{$search}%");
        });
    }

    /**
     * Replace all availability rows for the contract.
     *
     * @param  array<string, mixed>  $data
     */
    private function replaceAvailability(Contract $contract, array $data): void
    {
        /** @var list<array{day_of_week: int, shift_id: int}> $availability */
        $availability = $data['availability'];

        $contract->availability()->delete();

        $rows = [];
        $seen = [];

        foreach ($availability as $slot) {
            $dayOfWeek = (int) $slot['day_of_week'];
            $shiftId = (int) $slot['shift_id'];
            $key = "{$dayOfWeek}:{$shiftId}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'day_of_week' => $dayOfWeek,
                'shift_id' => $shiftId,
            ];
        }

        $contract->availability()->createMany($rows);
    }
}
