<?php

declare(strict_types=1);

/**
 * Seed workers, contracts, and availability for a chosen staffing profile — the
 * worker seed command. Dev/test fixture
 * data only; writes straight to the DB via PDO (no factories, no parity needed).
 *
 * Usage:
 *   php bin/seed-workers.php [profile] [--coverage-factor=5.0] [--seed=2026] [--fresh]
 *
 *   profile           realistic (default) | optimization | shortage
 *   --coverage-factor headcount multiplier per round-the-clock position
 *   --seed            RNG seed for reproducible data
 *   --fresh           delete existing workers (and their contracts) first
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;

/** Per-shift demand from the reference seed, scaled by the coverage factor. */
const DEMAND = ['general_guard' => 6, 'screener' => 2, 'supervisor' => 1];

/** Wide per-role cost ranges so cheaper same-role workers exist to swap in. */
const COST_RANGES = [
    'general_guard' => [40.0, 70.0],
    'screener' => [52.0, 88.0],
    'supervisor' => [70.0, 115.0],
];

/** Bimodal cheap/expensive ranges for the optimization profile. */
const OPTIMIZATION_COST_RANGES = [
    'general_guard' => [[35.0, 45.0], [65.0, 85.0]],
    'screener' => [[45.0, 55.0], [75.0, 95.0]],
    'supervisor' => [[60.0, 75.0], [100.0, 130.0]],
];

const FIRST_NAMES = [
    'Dana', 'Yossi', 'Maya', 'Avi', 'Noa', 'Eitan', 'Tamar', 'Omer', 'Shira', 'Itai',
    'Lior', 'Gal', 'Roni', 'Nadav', 'Hila', 'Yuval', 'Adi', 'Bar', 'Chen', 'Ido',
    'Keren', 'Omri', 'Tal', 'Yael', 'Ziv', 'Amit', 'Boaz', 'Dor', 'Elad', 'Gili',
];

const LAST_NAMES = [
    'Cohen', 'Levi', 'Mizrahi', 'Friedman', 'Shapiro', 'Azoulay', 'Katz', 'Golan',
    'Avraham', 'Peretz', 'Goldberg', 'Rosen', 'Klein', 'Weiss', 'Sharon', 'Mor',
    'Dahan', 'Biton', 'Elkayam', 'Haddad', 'Saban', 'Ohana', 'Vaknin', 'Asulin',
];

const BALANCE_SHIFT_CODES = ['A', 'B', 'C'];

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

// --- arg parsing ----------------------------------------------------------
$optind = 0;
$options = getopt('', ['coverage-factor:', 'seed:', 'fresh'], $optind);
$profile = strtolower($argv[$optind] ?? 'realistic');
$coverageFactor = isset($options['coverage-factor']) ? (float) $options['coverage-factor'] : 5.0;
$seed = isset($options['seed']) ? (int) $options['seed'] : 2026;
$fresh = array_key_exists('fresh', $options);

// --- profile role counts --------------------------------------------------
$scaled = static fn (float $factor): array => array_map(
    static fn (int $demand): int => max(1, (int) ceil($demand * $factor)),
    DEMAND,
);
$roleCountsByProfile = [
    'realistic' => static fn (float $factor): array => $scaled($factor),
    'optimization' => static fn (float $factor): array => $scaled($factor + 1.0),
    'shortage' => static fn (float $factor): array => ['general_guard' => 6, 'screener' => 2, 'supervisor' => 1],
];

if (! array_key_exists($profile, $roleCountsByProfile)) {
    $fail("Unknown profile '{$profile}'. Use: realistic, optimization, or shortage.");
}

mt_srand($seed);

// --- RNG helpers (ported verbatim from SeedWorkers) -----------------------
$pick = static fn (array $items): mixed => $items[mt_rand(0, count($items) - 1)];

$sample = static function (array $items, int $count): array {
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    $picked = array_slice($items, 0, $count);
    sort($picked);

    return $picked;
};

$name = static fn (): string => $pick(FIRST_NAMES) . ' ' . $pick(LAST_NAMES);

$hourlyCost = static function (string $profile, string $roleCode, int $position): float {
    if ($profile === 'optimization') {
        [$cheap, $expensive] = OPTIMIZATION_COST_RANGES[$roleCode];
        [$low, $high] = $position % 2 === 0 ? $cheap : $expensive;
    } else {
        [$low, $high] = COST_RANGES[$roleCode];
    }

    return round($low + ($high - $low) * (mt_rand() / mt_getrandmax()), 2);
};

$contractHours = static function (string $profile) use ($pick): array {
    if ($profile === 'optimization') {
        $min = $pick([40, 60, 80]);

        return [$min, min(744, $min + $pick([120, 140, 160]))];
    }

    $min = match ($profile) {
        'shortage' => $pick([120, 140, 160, 180]),
        default => $pick([120, 128, 136, 144]),
    };

    $extra = $profile === 'realistic' ? $pick([24, 32, 40, 48]) : $pick([20, 40, 60, 80, 100]);

    return [$min, min(744, $min + $extra)];
};

$days = static function (string $profile) use ($sample): array {
    if ($profile === 'shortage') {
        return range(0, 6);
    }

    return $sample(range(0, 6), $profile === 'optimization' ? mt_rand(6, 7) : mt_rand(5, 6));
};

$shiftCodes = static function (string $profile, int $position): array {
    $codes = BALANCE_SHIFT_CODES;

    if ($profile !== 'optimization') {
        return $codes;
    }

    $set = [$codes[$position % 3], $codes[($position + 1) % 3]];

    if (mt_rand() / mt_getrandmax() < 0.3) {
        $set[] = $codes[($position + 2) % 3];
    }

    return array_values(array_unique($set));
};

// --- DB setup -------------------------------------------------------------
$pdo = DB::connect();

$roleIdByCode = [];
foreach ($pdo->query('SELECT id, code FROM roles')->fetchAll() as $row) {
    $roleIdByCode[$row['code']] = (int) $row['id'];
}
$shiftIdByCode = [];
foreach ($pdo->query('SELECT id, code FROM shifts')->fetchAll() as $row) {
    $shiftIdByCode[$row['code']] = (int) $row['id'];
}

if ($roleIdByCode === [] || $shiftIdByCode === []) {
    $fail('Reference data (roles/shifts) is missing. Run `php bin/seed.php` first.');
}

foreach (array_keys(DEMAND) as $roleCode) {
    if (! isset($roleIdByCode[$roleCode])) {
        $fail("Reference data is missing the '{$roleCode}' role. Run `php bin/seed.php` first.");
    }
}
foreach (BALANCE_SHIFT_CODES as $code) {
    if (! isset($shiftIdByCode[$code])) {
        $fail("Reference data is missing shift '{$code}'. Run `php bin/seed.php` first.");
    }
}

// --- optional wipe --------------------------------------------------------
if ($fresh) {
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM contract_availability WHERE contract_id IN (SELECT id FROM contracts)');
        $pdo->exec('DELETE FROM contracts');
        $pdo->exec('DELETE FROM workers');
        $pdo->commit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        $fail('Cannot wipe workers: existing rosters reference them. Delete those rosters first, then retry.');
    }
}

// --- seed -----------------------------------------------------------------
$counts = $roleCountsByProfile[$profile]($coverageFactor);
$existing = (int) $pdo->query('SELECT count(*) FROM workers')->fetchColumn();
$idBase = 100_000_000 + $existing;
$index = 0;

$insertWorker = $pdo->prepare(
    'INSERT INTO workers (israeli_id, full_name, role_id, is_active, created_at, updated_at)
     VALUES (?, ?, ?, true, now(), now())',
);
$insertContract = $pdo->prepare(
    'INSERT INTO contracts (worker_id, hourly_cost, min_monthly_hours, max_monthly_hours, created_at, updated_at)
     VALUES (?, ?, ?, ?, now(), now()) RETURNING id',
);
$insertAvailability = $pdo->prepare(
    'INSERT INTO contract_availability (contract_id, day_of_week, shift_id) VALUES (?, ?, ?)',
);

$pdo->beginTransaction();
try {
    foreach ($counts as $roleCode => $count) {
        for ($position = 0; $position < $count; $position++) {
            [$min, $max] = $contractHours($profile);
            $shiftIds = array_map(static fn (string $code): int => $shiftIdByCode[$code], $shiftCodes($profile, $position));
            $israeliId = str_pad((string) ($idBase + $index), 9, '0', STR_PAD_LEFT);

            $insertWorker->execute([$israeliId, $name(), $roleIdByCode[$roleCode]]);

            $insertContract->execute([$israeliId, $hourlyCost($profile, $roleCode, $position), $min, $max]);
            $contractId = (int) $insertContract->fetchColumn();

            foreach ($days($profile) as $dayOfWeek) {
                foreach ($shiftIds as $shiftId) {
                    $insertAvailability->execute([$contractId, $dayOfWeek, $shiftId]);
                }
            }

            $index++;
        }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    $fail('Seeding failed: ' . $e->getMessage());
}

echo "Seeded {$index} workers for the '{$profile}' profile.\n";
foreach ($counts as $roleCode => $n) {
    printf("  %-16s %d\n", $roleCode, $n);
}

exit(0);
