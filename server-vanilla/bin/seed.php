<?php

declare(strict_types=1);

/**
 * Vanilla seeder — the same idea as `artisan db:seed`, idempotent. Inserts the
 * reference data the rostering engine relies on (roles, shifts, per-shift
 * staffing requirements) plus a default login user, upserting on natural keys
 * so re-running is safe.
 *
 * Usage: php bin/seed.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;

// Mirrors App\Enums\RoleCode / ShiftCode and ReferenceDataSeeder constants.
$roles = [
    ['general_guard', 'General Guard'],
    ['supervisor', 'Supervisor'],
    ['screener', 'Screener'],
];

$shifts = [
    ['A', '00:00:00', '08:00:00', 8],
    ['B', '08:00:00', '16:00:00', 8],
    ['C', '16:00:00', '00:00:00', 8],
];

// Per-shift staffing demand, applied to every shift.
$requiredCountByRole = [
    'general_guard' => 6,
    'supervisor' => 1,
    'screener' => 2,
];

$pdo = DB::connect();
$pdo->beginTransaction();

try {
    $upsertRole = $pdo->prepare(
        'INSERT INTO roles (code, name) VALUES (:code, :name)
         ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name'
    );
    foreach ($roles as [$code, $name]) {
        $upsertRole->execute([':code' => $code, ':name' => $name]);
    }

    $upsertShift = $pdo->prepare(
        'INSERT INTO shifts (code, start_time, end_time, duration_hours)
         VALUES (:code, :start, :end, :duration)
         ON CONFLICT (code) DO UPDATE
           SET start_time = EXCLUDED.start_time,
               end_time = EXCLUDED.end_time,
               duration_hours = EXCLUDED.duration_hours'
    );
    foreach ($shifts as [$code, $start, $end, $duration]) {
        $upsertShift->execute([':code' => $code, ':start' => $start, ':end' => $end, ':duration' => $duration]);
    }

    // Resolve ids by code so we never depend on hardcoded sequence values.
    $upsertRequirement = $pdo->prepare(
        'INSERT INTO shift_role_requirements (shift_id, role_id, required_count)
         VALUES (
            (SELECT id FROM shifts WHERE code = :shift),
            (SELECT id FROM roles WHERE code = :role),
            :count
         )
         ON CONFLICT (shift_id, role_id) DO UPDATE SET required_count = EXCLUDED.required_count'
    );
    foreach ($shifts as [$shiftCode]) {
        foreach ($requiredCountByRole as $roleCode => $count) {
            $upsertRequirement->execute([':shift' => $shiftCode, ':role' => $roleCode, ':count' => $count]);
        }
    }

    // Default login user. bcrypt ($2y$) is what Laravel's guard verifies against,
    // so login keeps working while Sanctum is still in place.
    $upsertUser = $pdo->prepare(
        "INSERT INTO users (name, email, email_verified_at, password, created_at, updated_at)
         VALUES ('Test User', 'test@example.com', now(), :password, now(), now())
         ON CONFLICT (email) DO UPDATE
           SET name = EXCLUDED.name,
               password = EXCLUDED.password,
               email_verified_at = EXCLUDED.email_verified_at,
               updated_at = now()"
    );
    $upsertUser->execute([':password' => password_hash('password', PASSWORD_BCRYPT)]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seeding failed: {$e->getMessage()}\n");
    exit(1);
}

echo 'Seeded: ' . count($roles) . ' roles, ' . count($shifts) . ' shifts, '
    . (count($shifts) * count($requiredCountByRole)) . " requirements, 1 user.\n";
