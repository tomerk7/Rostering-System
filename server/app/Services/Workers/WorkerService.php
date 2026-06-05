<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Models\Contract;
use App\Models\Worker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class WorkerService
{
    private const RELATIONS = [
        'role',
        'contract.availableDays',
        'contract.availableShifts',
    ];

    public function list(Request $request): LengthAwarePaginator
    {
        $perPage = min((int) $request->integer('per_page', 15), 100);

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
            $worker = Worker::query()->create($this->workerAttributes($data));

            $contract = $worker->contract()->create($this->contractAttributes($data));
            $this->replaceAvailability($contract, $data);

            return $this->loadDetails($worker);
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
            $worker->update($this->workerAttributes($data));

            $contract = $worker->contract()->updateOrCreate([], $this->contractAttributes($data));
            $this->replaceAvailability($contract, $data);

            return $this->loadDetails($worker->refresh());
        });
    }

    public function delete(Worker $worker): void
    {
        $worker->delete();
    }

    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('israeli_id', 'like', "%{$search}%");
        });
    }

    /**
     * Build worker attributes from validated request data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function workerAttributes(array $data): array
    {
        return [
            'full_name' => $data['full_name'],
            'israeli_id' => $data['israeli_id'],
            'role_id' => $data['role_id'],
            'is_active' => $data['is_active'],
        ];
    }

    /**
     * Build contract attributes from validated request data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contractAttributes(array $data): array
    {
        /** @var array<string, mixed> $contract */
        $contract = $data['contract'];

        return [
            'hourly_cost' => $contract['hourly_cost'],
            'min_monthly_hours' => $contract['min_monthly_hours'],
            'max_monthly_hours' => $contract['max_monthly_hours'],
        ];
    }

    /**
     * Replace all normalized availability rows for the contract.
     *
     * @param  array<string, mixed>  $data
     */
    private function replaceAvailability(Contract $contract, array $data): void
    {
        /** @var array{days: array<int, int|string>, shifts: array<int, int|string>} $availability */
        $availability = $data['availability'];

        $contract->availableDays()->delete();
        $contract->availableShiftRows()->delete();

        $contract->availableDays()->createMany(
            array_map(
                static fn (int $dayOfWeek): array => ['day_of_week' => $dayOfWeek],
                $this->normalizedIntegers($availability['days']),
            ),
        );

        $contract->availableShiftRows()->createMany(
            array_map(
                static fn (int $shiftId): array => ['shift_id' => $shiftId],
                $this->normalizedIntegers($availability['shifts']),
            ),
        );
    }

    /**
     * Normalize submitted integer lists before replacing child rows.
     *
     * @param  array<int, int|string>  $values
     * @return array<int, int>
     */
    private function normalizedIntegers(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values);

        return $values;
    }
}
