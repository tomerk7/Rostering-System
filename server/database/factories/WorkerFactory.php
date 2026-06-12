<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worker>
 */
final class WorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'israeli_id' => $this->israeliId(),
            'role_id' => $this->roleId(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'deleted_at' => now(),
        ]);
    }

    private function roleId(): int
    {
        $roleId = Role::query()->inRandomOrder()->value('id');

        if ($roleId !== null) {
            return (int) $roleId;
        }

        return (int) Role::query()
            ->firstOrCreate(
                ['code' => ReferenceDataSeeder::ROLES[0]['code']],
                ['name' => ReferenceDataSeeder::ROLES[0]['name']],
            )
            ->getKey();
    }

    private function israeliId(): string
    {
        return str_pad(
            (string) fake()->unique()->numberBetween(1, 999_999_999),
            9,
            '0',
            STR_PAD_LEFT,
        );
    }
}
