<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Shift;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
final class ContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minMonthlyHours = fake()->randomElement([80, 96, 120, 144, 160]);

        return [
            'worker_id' => Worker::factory(),
            'hourly_cost' => fake()->randomFloat(2, 35, 120),
            'min_monthly_hours' => $minMonthlyHours,
            'max_monthly_hours' => fake()->numberBetween($minMonthlyHours, 220),
        ];
    }

    /**
     * Add weekday/shift availability rows after the contract is created.
     *
     * When both arrays are provided, writes the cross-product of days x shifts.
     *
     * @param array<int, int>|null $daysOfWeek
     * @param array<int, int>|null $shiftIds
     */
    public function withAvailability(?array $daysOfWeek = null, ?array $shiftIds = null): static
    {
        return $this->afterCreating(function (Contract $contract) use ($daysOfWeek, $shiftIds): void {
            $days = $this->normalizeDaysOfWeek($daysOfWeek);
            $availableShiftIds = $this->normalizeShiftIds($shiftIds);

            foreach ($days as $dayOfWeek) {
                foreach ($availableShiftIds as $shiftId) {
                    ContractAvailability::query()->firstOrCreate([
                        'contract_id' => $contract->id,
                        'day_of_week' => $dayOfWeek,
                        'shift_id' => $shiftId,
                    ]);
                }
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function normalizeDaysOfWeek(?array $daysOfWeek): array
    {
        if ($daysOfWeek === null) {
            $daysOfWeek = fake()->randomElements(range(0, 6), fake()->numberBetween(1, 7));
        }

        $daysOfWeek = array_values(array_unique(array_map('intval', $daysOfWeek)));
        sort($daysOfWeek);

        return $daysOfWeek;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeShiftIds(?array $shiftIds): array
    {
        if ($shiftIds !== null) {
            $shiftIds = array_values(array_unique(array_map('intval', $shiftIds)));
            sort($shiftIds);

            return $shiftIds;
        }

        $availableShiftIds = $this->availableShiftIds();

        return array_values(fake()->randomElements(
            $availableShiftIds,
            fake()->numberBetween(1, count($availableShiftIds)),
        ));
    }

    /**
     * @return array<int, int>
     */
    private function availableShiftIds(): array
    {
        $shiftIds = Shift::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($shiftIds !== []) {
            return $shiftIds;
        }

        return array_map(static function (array $shift): int {
            return (int) Shift::query()
                ->firstOrCreate(
                    ['code' => $shift['code']],
                    [
                        'label' => $shift['label'],
                        'start_time' => $shift['start_time'],
                        'end_time' => $shift['end_time'],
                        'duration_hours' => $shift['duration_hours'],
                    ],
                )
                ->getKey();
        }, ReferenceDataSeeder::SHIFTS);
    }
}
