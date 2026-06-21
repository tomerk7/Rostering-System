<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * Point the database at the dedicated test database BEFORE loading .env (whose
 * DB_* target the Docker dev DB at db:5432/rostering). Set first so the .env
 * loader below — which only fills unset keys — never overrides these. The
 * defaults target the host-mapped Postgres (docker-compose maps db:5432 -> the
 * host's :5433); override any of them from the real environment to run elsewhere
 * (e.g. inside the container: DB_HOST=db DB_PORT=5432).
 */
$testDb = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '5433',
    'DB_DATABASE' => 'rostering_test',
    'DB_USERNAME' => 'rostering',
    'DB_PASSWORD' => 'rostering',
];

foreach ($testDb as $key => $value) {
    if (getenv($key) === false) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

/*
 * Load server-vanilla/.env (if present) for non-DB config such as JWT_SECRET.
 * DB_* are already set above, so the unset-only loader leaves them on the test
 * database.
 */
$envFile = __DIR__ . '/../.env';

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/*
 * Pure unit tests for AuthService::decode need a signing secret but never touch
 * the database. Provide a deterministic default when nothing configured it.
 */
if (getenv('JWT_SECRET') === false) {
    putenv('JWT_SECRET=test-secret-please-do-not-use-in-production');
    $_ENV['JWT_SECRET'] = 'test-secret-please-do-not-use-in-production';
}
