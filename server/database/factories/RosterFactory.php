<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Roster>
 */
final class RosterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->numberBetween(2024, 2027);
        $month = fake()->numberBetween(1, 12);

        return [
            'period_start' => Carbon::create($year, $month, 1)->toDateString(),
            'status' => RosterStatus::Ready,
            'generated_at' => Carbon::now(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Pin the roster to a specific target period.
     */
    public function forPeriod(int $year, int $month): static
    {
        return $this->state(fn (array $attributes): array => [
            'period_start' => Carbon::create($year, $month, 1)->toDateString(),
        ]);
    }
}
