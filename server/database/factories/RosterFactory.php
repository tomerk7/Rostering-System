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
        return [
            'year' => fake()->numberBetween(2024, 2027),
            'month' => fake()->numberBetween(1, 12),
            'status' => RosterStatus::Published,
            'generated_at' => Carbon::now(),
            'published_at' => Carbon::now(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Pin the roster to a specific target period.
     *
     * @param int $year
     * @param int $month
     * @return static
     */
    public function forPeriod(int $year, int $month): static
    {
        return $this->state(fn (array $attributes): array => [
            'year' => $year,
            'month' => $month,
        ]);
    }
}
