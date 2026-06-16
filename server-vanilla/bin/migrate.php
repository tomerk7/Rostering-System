<?php

declare(strict_types=1);

/**
 * Vanilla migration runner — the same idea as `artisan migrate`, dependency-free:
 * a folder of ordered .sql files, a `schema_migrations` tracking table, and each
 * pending file applied once in its own transaction.
 *
 * Adoption: when the tracking table does not yet exist but the schema is already
 * present (the DB Laravel migrated), the current files are recorded as applied
 * without executing them — so an existing database is adopted without data loss.
 *
 * Usage:
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --fresh    DROP every table, then apply all migrations
 *                                  (destructive — used by `make db-rebuild`)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;

$migrationsDir = __DIR__ . '/../database/migrations';

$pdo = DB::connect();

// --fresh: wipe the schema, then fall through to a full create. Dropping the
// public schema clears every table (including the tracking table), so the
// normal logic below detects an empty database and runs all migrations.
if (in_array('--fresh', $argv, true)) {
    echo "Dropping schema (--fresh)...\n";
    $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
}

/** Does a public table exist? */
$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT to_regclass(:name) AS oid');
    $stmt->execute([':name' => 'public.' . $table]);

    return $stmt->fetch()['oid'] !== null;
};

$trackingExisted = $tableExists($pdo, 'schema_migrations');

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name TEXT PRIMARY KEY,
        ran_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )'
);

/** @var list<string> $files */
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "No migration files found in {$migrationsDir}\n");
    exit(1);
}

$record = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');

// Adopt an already-migrated database (e.g. the one Laravel created): stamp the
// current files as applied without running their DDL.
if (! $trackingExisted && $tableExists($pdo, 'rosters')) {
    foreach ($files as $file) {
        $record->execute([':name' => basename($file)]);
    }
    echo 'Baselined existing schema: ' . count($files) . " migrations marked applied (none executed).\n";
    exit(0);
}

$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        continue;
    }

    $sql = file_get_contents($file);

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $record->execute([':name' => $name]);
        $pdo->commit();
        echo "Migrated: {$name}\n";
        $ran++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "FAILED on {$name}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Done: {$ran} migration(s) applied.\n";
