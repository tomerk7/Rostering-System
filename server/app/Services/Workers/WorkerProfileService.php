<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Models\Contract;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

final readonly class WorkerProfileService
{
    /**
     * Create a worker with contract and availability.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Worker
    {
        return DB::transaction(function () use ($data): Worker {
            $worker = Worker::query()->create($this->workerAttributes($data));

            $contract = $worker->contract()->create($this->contractAttributes($data));
            $this->replaceAvailability($contract, $data);

            return $worker->load(['role', 'contract.availableDays', 'contract.availableShifts']);
        });
    }

    /**
     * Update a worker with contract and availability.
     *
     * @param array<string, mixed> $data
     */
    public function update(Worker $worker, array $data): Worker
    {
        return DB::transaction(function () use ($worker, $data): Worker {
            $worker->update($this->workerAttributes($data));

            $contract = $worker->contract()->updateOrCreate([], $this->contractAttributes($data));
            $this->replaceAvailability($contract, $data);

            return $worker->refresh()->load(['role', 'contract.availableDays', 'contract.availableShifts']);
        });
    }

    /**
     * Build worker attributes from validated request data.
     *
     * @param array<string, mixed> $data
     *
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
     * @param array<string, mixed> $data
     *
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
     * @param array<string, mixed> $data
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
     * @param array<int, int|string> $values
     *
     * @return array<int, int>
     */
    private function normalizedIntegers(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values);

        return $values;
    }
}
