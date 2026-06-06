<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssignmentSource;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RosterAssignment>
 */
final class RosterAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roster_id' => Roster::factory(),
            'worker_id' => Worker::factory(),
            'shift_id' => $this->shiftId(),
            'work_date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'source' => AssignmentSource::Auto,
        ];
    }

    /**
     * Mark the assignment as manual.
     * 
     * @return static
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => AssignmentSource::Manual,
        ]);
    }

    /**
     * Get the shift ID.
     * 
     * @return int
     */
    private function shiftId(): int
    {
        $shiftId = Shift::query()->inRandomOrder()->value('id');

        if ($shiftId !== null) {
            return (int) $shiftId;
        }

        $shift = ReferenceDataSeeder::SHIFTS[0];

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
    }
}
