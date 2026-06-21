<?php

declare(strict_types=1);

namespace Tests\Api;

/**
 * End-to-end coverage for the manual assignment endpoints, exercising the
 * incremental report refresh: adding/removing a single assignment must update
 * only the affected coverage cell and the affected worker's hours-shortfall
 * alert, leaving the rest of the roster's reports untouched.
 */
final class RosterAssignmentTest extends ApiTestCase
{
    public function testManualAssignmentIncrementallyUpdatesReports(): void
    {
        // A supervisor (role 2) available Sunday on shift 1, whose minimum is a
        // single shift's worth of hours so one assignment clears their shortfall.
        $worker = $this->createWorker([
            'contract' => [
                'hourly_cost' => 50,
                'min_monthly_hours' => 8,
                'max_monthly_hours' => 160,
            ],
        ]);
        $this->assertTrue($worker['json']['success'], 'worker create failed');
        $workerId = '123456782';

        // Seed a roster for a fixed past month (kept out of "upcoming" so worker
        // changes never touch it) and pick its first Sunday for the assignment.
        $rosterId = $this->seedRoster('2025-03-01');
        $sunday = $this->firstSunday('2025-03-01');

        // The supervisor slot (shift 1, role 2 → required 1) starts unfilled.
        $this->seedCoverageShortage($rosterId, $sunday, shiftId: 1, roleId: 2, required: 1, assigned: 0);

        // --- Add the assignment: fills the cell and clears the shortfall. ---
        $add = $this->call('POST', "/api/rosters/{$rosterId}/assignments", [
            'worker_id' => $workerId,
            'shift_id' => 1,
            'work_date' => $sunday,
        ], $this->authHeader());

        $this->assertSame(201, $add['status'], $add['raw']);
        $this->assertTrue($add['json']['success']);

        // Cell now has assigned (1) >= required (1): its shortage row is cleared.
        $this->assertSame(0, $this->coverageCount($rosterId, $sunday, 1, 2));
        // Scheduled hours (8) >= min (8): no hours-shortfall alert for the worker.
        $this->assertNull($this->shortfallHours($rosterId, $workerId));

        // --- Remove the assignment: cell goes short again, shortfall returns. ---
        $assignmentId = $this->assignmentId($rosterId, $workerId, $sunday);
        $this->assertGreaterThan(0, $assignmentId);

        $delete = $this->call('DELETE', "/api/rosters/{$rosterId}/assignments/{$assignmentId}", [], $this->authHeader());

        $this->assertSame(200, $delete['status'], $delete['raw']);
        $this->assertTrue($delete['json']['success']);

        // Cell is short again (assigned 0 < required 1).
        $this->assertSame(1, $this->coverageCount($rosterId, $sunday, 1, 2));
        // Worker is back below minimum (scheduled 0 < min 8).
        $this->assertSame(0, $this->shortfallHours($rosterId, $workerId));
    }

    /**
     * Insert a roster for the given period_start and return its id.
     */
    private function seedRoster(string $periodStart): int
    {
        $userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

        $stmt = $this->db->prepare(
            "INSERT INTO rosters (period_start, status, created_by, created_at, updated_at)
             VALUES (?, 'ready', ?, now(), now()) RETURNING id",
        );
        $stmt->execute([$periodStart, $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Seed a coverage-shortage row for one cell.
     */
    private function seedCoverageShortage(int $rosterId, string $workDate, int $shiftId, int $roleId, int $required, int $assigned): void
    {
        $this->db->prepare(
            'INSERT INTO coverage_shortages
                (roster_id, work_date, shift_id, role_id, required_count, assigned_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, now(), now())',
        )->execute([$rosterId, $workDate, $shiftId, $roleId, $required, $assigned]);
    }

    /**
     * The first Sunday on or after the given date, as Y-m-d.
     */
    private function firstSunday(string $from): string
    {
        $date = new \DateTimeImmutable($from);

        while ((int) $date->format('w') !== 0) {
            $date = $date->modify('+1 day');
        }

        return $date->format('Y-m-d');
    }

    /**
     * Number of coverage-shortage rows for a single cell.
     */
    private function coverageCount(int $rosterId, string $workDate, int $shiftId, int $roleId): int
    {
        $stmt = $this->db->prepare(
            'SELECT count(*) FROM coverage_shortages
             WHERE roster_id = ? AND work_date = ? AND shift_id = ? AND role_id = ?',
        );
        $stmt->execute([$rosterId, $workDate, $shiftId, $roleId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The scheduled_hours on a worker's hours-shortfall alert, or null when none.
     */
    private function shortfallHours(int $rosterId, string $workerId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT scheduled_hours FROM roster_alerts
             WHERE roster_id = ? AND type = 'hours_shortfall' AND worker_id = ?",
        );
        $stmt->execute([$rosterId, $workerId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * The id of a worker's assignment on a date in a roster.
     */
    private function assignmentId(int $rosterId, string $workerId, string $workDate): int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM roster_assignments
             WHERE roster_id = ? AND worker_id = ? AND work_date = ?',
        );
        $stmt->execute([$rosterId, $workerId, $workDate]);

        return (int) $stmt->fetchColumn();
    }
}
