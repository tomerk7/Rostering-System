<?php

declare(strict_types=1);

namespace Tests;

use App\Support\DB;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for DB-backed tests (integration + API). Each test runs inside a
 * single transaction on the shared connection and is rolled back in tearDown, so
 * writes never persist and tests stay isolated. The app's DB::transaction() is
 * reentrant — it joins this outer transaction instead of committing — so code
 * under test composes cleanly with the rollback. The connection targets
 * rostering_test (configured in tests/bootstrap.php).
 */
abstract class TestCase extends BaseTestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = DB::connect();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        parent::tearDown();
    }
}
