<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Seeds workers, contracts, and availability for a chosen staffing profile,
 * mirroring the profiles in scripts/generate_workers_csv.py but writing straight
 * to the database through the model factories instead of producing a CSV.
 */
final class SeedWorkers extends Command
{
    protected $signature = 'workers:seed {profile=realistic : realistic|optimization|shortage} {--coverage-factor=5.0 : headcount multiplier per round-the-clock position} {--seed=2026 : RNG seed for reproducible data} {--fresh : delete existing workers (and their contracts) first}';

    protected $description = 'Seed workers for a staffing profile using the model factories.';

    /** Per-shift demand from ReferenceDataSeeder, scaled by the coverage factor. */
    private const DEMAND = ['general_guard' => 6, 'screener' => 2, 'supervisor' => 1];

    /** Wide per-role cost ranges so cheaper same-role workers exist to swap in. */
    private const COST_RANGES = [
        'general_guard' => [40.0, 70.0],
        'screener' => [52.0, 88.0],
        'supervisor' => [70.0, 115.0],
    ];

    /** Bimodal cheap/expensive ranges for the optimization profile. */
    private const OPTIMIZATION_COST_RANGES = [
        'general_guard' => [[35.0, 45.0], [65.0, 85.0]],
        'screener' => [[45.0, 55.0], [75.0, 95.0]],
        'supervisor' => [[60.0, 75.0], [100.0, 130.0]],
    ];

    private const FIRST_NAMES = [
        'Dana', 'Yossi', 'Maya', 'Avi', 'Noa', 'Eitan', 'Tamar', 'Omer', 'Shira', 'Itai',
        'Lior', 'Gal', 'Roni', 'Nadav', 'Hila', 'Yuval', 'Adi', 'Bar', 'Chen', 'Ido',
        'Keren', 'Omri', 'Tal', 'Yael', 'Ziv', 'Amit', 'Boaz', 'Dor', 'Elad', 'Gili',
    ];

    private const LAST_NAMES = [
        'Cohen', 'Levi', 'Mizrahi', 'Friedman', 'Shapiro', 'Azoulay', 'Katz', 'Golan',
        'Avraham', 'Peretz', 'Goldberg', 'Rosen', 'Klein', 'Weiss', 'Sharon', 'Mor',
        'Dahan', 'Biton', 'Elkayam', 'Haddad', 'Saban', 'Ohana', 'Vaknin', 'Asulin',
    ];

    public function handle(): int
    {
        $profile = strtolower((string) $this->argument('profile'));

        if (! array_key_exists($profile, $this->roleCountsByProfile())) {
            $this->error("Unknown profile '{$profile}'. Use: realistic, optimization, or shortage.");

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));

        if (Role::query()->doesntExist() || Shift::query()->doesntExist()) {
            $this->call(ReferenceDataSeeder::class);
        }

        $roleIdByCode = Role::query()->pluck('id', 'code');
        $shiftIdByCode = Shift::query()->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id);

        if ($this->option('fresh') && ! $this->wipeWorkers()) {
            return self::FAILURE;
        }

        $counts = $this->roleCountsByProfile()[$profile]((float) $this->option('coverage-factor'));
        $idBase = 100_000_000 + (int) Worker::withTrashed()->count();
        $index = 0;

        foreach ($counts as $roleCode => $count) {
            for ($position = 0; $position < $count; $position++) {
                [$min, $max] = $this->contractHours($profile);
                $shiftIds = array_map(fn (string $code): int => $shiftIdByCode[$code], $this->shiftCodes($profile, $position));

                $worker = Worker::factory()->create([
                    'full_name' => $this->name(),
                    'israeli_id' => str_pad((string) ($idBase + $index), 9, '0', STR_PAD_LEFT),
                    'role_id' => (int) $roleIdByCode[$roleCode],
                    'is_active' => true,
                ]);

                Contract::factory()
                    ->withAvailability($this->days($profile), $shiftIds)
                    ->create([
                        'worker_id' => $worker->israeli_id,
                        'hourly_cost' => $this->hourlyCost($profile, $roleCode, $position),
                        'min_monthly_hours' => $min,
                        'max_monthly_hours' => $max,
                    ]);

                $index++;
            }
        }

        $this->components->info("Seeded {$index} workers for the '{$profile}' profile.");
        $this->table(
            ['Role', 'Count'],
            array_map(static fn (string $code, int $n): array => [$code, $n], array_keys($counts), array_values($counts)),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, callable(float): array<string, int>>
     */
    private function roleCountsByProfile(): array
    {
        return [
            'realistic' => fn (float $factor): array => $this->scaled($factor),
            'optimization' => fn (float $factor): array => $this->scaled($factor + 1.0),
            'shortage' => fn (float $factor): array => ['general_guard' => 6, 'screener' => 2, 'supervisor' => 1],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function scaled(float $factor): array
    {
        return array_map(static fn (int $demand): int => max(1, (int) ceil($demand * $factor)), self::DEMAND);
    }

    private function hourlyCost(string $profile, string $roleCode, int $position): float
    {
        if ($profile === 'optimization') {
            [$cheap, $expensive] = self::OPTIMIZATION_COST_RANGES[$roleCode];
            [$low, $high] = $position % 2 === 0 ? $cheap : $expensive;
        } else {
            [$low, $high] = self::COST_RANGES[$roleCode];
        }

        return round($low + ($high - $low) * (mt_rand() / mt_getrandmax()), 2);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function contractHours(string $profile): array
    {
        if ($profile === 'optimization') {
            $min = $this->pick([40, 60, 80]);

            return [$min, min(744, $min + $this->pick([120, 140, 160]))];
        }

        $min = match ($profile) {
            'shortage' => $this->pick([120, 140, 160, 180]),
            default => $this->pick([120, 128, 136, 144]),
        };

        $extra = $profile === 'realistic' ? $this->pick([24, 32, 40, 48]) : $this->pick([20, 40, 60, 80, 100]);

        return [$min, min(744, $min + $extra)];
    }

    /**
     * @return list<int>
     */
    private function days(string $profile): array
    {
        if ($profile === 'shortage') {
            return range(0, 6);
        }

        return $this->sample(range(0, 6), $profile === 'optimization' ? mt_rand(6, 7) : mt_rand(5, 6));
    }

    /**
     * @return list<string>
     */
    private function shiftCodes(string $profile, int $position): array
    {
        $codes = ['A', 'B', 'C'];

        // Optimization keeps a narrower (but multi-shift) spread to exercise the
        // swap neighbourhood; every other profile is available for any shift on
        // its working days, so small role pools can still cover all three shifts.
        if ($profile !== 'optimization') {
            return $codes;
        }

        $set = [$codes[$position % 3], $codes[($position + 1) % 3]];

        if (mt_rand() / mt_getrandmax() < 0.3) {
            $set[] = $codes[($position + 2) % 3];
        }

        return array_values(array_unique($set));
    }

    private function wipeWorkers(): bool
    {
        try {
            Worker::withTrashed()->forceDelete();
        } catch (QueryException) {
            $this->error('Cannot wipe workers: existing rosters reference them. Delete those rosters first, then retry.');

            return false;
        }

        return true;
    }

    private function name(): string
    {
        return $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items)
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * @param  list<int>  $items
     * @return list<int>
     */
    private function sample(array $items, int $count): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        $picked = array_slice($items, 0, $count);
        sort($picked);

        return $picked;
    }
}
