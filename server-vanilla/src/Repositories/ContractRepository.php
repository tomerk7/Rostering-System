<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Contract;
use App\Data\ContractAvailability;
use App\Data\Shift;
use App\Support\DB;
use PDO;

/**
 * Reads the `contracts` table (1:1 with workers), optionally with availability.
 */
final class ContractRepository
{
    private PDO $pdo;

    /**
     * Class constructor.
     *
     * @param PDO|null $pdo
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? DB::connect();
    }

    /**
     * Every contract's hourly cost keyed by worker id.
     * `DB::table('contracts')->pluck('hourly_cost', 'worker_id')` used to snapshot
     * the cost onto each generated assignment. Cost stays a raw string (the DB's
     * numeric representation) so it persists byte-identically.
     *
     * @return array<string, string>
     */
    public function hourlyCostByWorker(): array
    {
        $rows = $this->pdo->query('SELECT worker_id, hourly_cost FROM contracts')->fetchAll();

        $costs = [];
        foreach ($rows as $row) {
            $costs[(string) $row['worker_id']] = (string) $row['hourly_cost'];
        }

        return $costs;
    }

    /**
     * Every contract's cost and hour bounds, as three maps keyed by worker id
     *
     * @return array{costs: array<string, string>, minHours: array<string, int>, maxHours: array<string, int>}
     */
    public function inputsByWorker(): array
    {
        $rows = $this->pdo->query(
            'SELECT worker_id, hourly_cost, min_monthly_hours, max_monthly_hours FROM contracts',
        )->fetchAll();

        $costs = [];
        $minHours = [];
        $maxHours = [];

        foreach ($rows as $row) {
            $workerId = (string) $row['worker_id'];
            $costs[$workerId] = (string) $row['hourly_cost'];
            $minHours[$workerId] = (int) $row['min_monthly_hours'];
            $maxHours[$workerId] = (int) $row['max_monthly_hours'];
        }

        return ['costs' => $costs, 'minHours' => $minHours, 'maxHours' => $maxHours];
    }

    /**
     * Contracts for the given worker ids, keyed by worker id, each with its
     * availability (joined to shifts) loaded. Batched to avoid N+1 across a page
     * of workers.
     *
     * @param  list<string>  $workerIds
     * @return array<string, Contract>
     */
    public function forWorkerIds(array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT id, worker_id, hourly_cost, min_monthly_hours, max_monthly_hours
             FROM contracts
             WHERE worker_id IN ($placeholders)",
        );
        $stmt->execute($workerIds);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        // contract id -> worker id, so we can attach availability back to the
        // right worker after the second batched query.
        $contractToWorker = [];
        foreach ($rows as $row) {
            $contractToWorker[(int) $row['id']] = (string) $row['worker_id'];
        }

        $availabilityByContract = $this->availabilityFor(array_keys($contractToWorker));

        $contracts = [];
        foreach ($rows as $row) {
            $contractId = (int) $row['id'];
            $contracts[(string) $row['worker_id']] = new Contract(
                $contractId,
                (string) $row['hourly_cost'],
                (int) $row['min_monthly_hours'],
                (int) $row['max_monthly_hours'],
                $availabilityByContract[$contractId] ?? [],
            );
        }

        return $contracts;
    }

    /**
     * Create or update the worker's single contract, returning the contract id.
     *
     * @param string $workerId
     * @param string $hourlyCost
     * @param int $minMonthlyHours
     * @param int $maxMonthlyHours
     * @return int
     */
    public function updateOrCreateForWorker(string $workerId, string $hourlyCost, int $minMonthlyHours, int $maxMonthlyHours): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT id FROM contracts WHERE worker_id = ? LIMIT 1');
        $stmt->execute([$workerId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE contracts
                 SET hourly_cost = ?, min_monthly_hours = ?, max_monthly_hours = ?, updated_at = ?
                 WHERE id = ?',
            );
            $update->execute([$hourlyCost, $minMonthlyHours, $maxMonthlyHours, $now, $existingId]);

            return (int) $existingId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO contracts (worker_id, hourly_cost, min_monthly_hours, max_monthly_hours, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
        );
        $insert->execute([$workerId, $hourlyCost, $minMonthlyHours, $maxMonthlyHours, $now, $now]);

        return (int) $insert->fetchColumn();
    }

    /**
     * Replace all availability rows for the contract. Deduplicates on
     * (day_of_week, shift_id), preserving first-seen order.
     *
     * @param int $contractId
     * @param  list<array{day_of_week: int, shift_id: int}>  $availability
     * @return void
     */
    public function replaceAvailability(int $contractId, array $availability): void
    {
        $delete = $this->pdo->prepare('DELETE FROM contract_availability WHERE contract_id = ?');
        $delete->execute([$contractId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO contract_availability (contract_id, day_of_week, shift_id) VALUES (?, ?, ?)',
        );

        $seen = [];
        foreach ($availability as $slot) {
            $dayOfWeek = (int) $slot['day_of_week'];
            $shiftId = (int) $slot['shift_id'];
            $key = "{$dayOfWeek}:{$shiftId}";

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $insert->execute([$contractId, $dayOfWeek, $shiftId]);
        }
    }

    /**
     * Bulk insert/update contracts keyed on worker_id (CSV import).
     *
     * @param array $rows
     * @return void
     */
    public function bulkUpsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $values = [];
        $params = [];
        foreach ($rows as $r) {
            $values[] = '(?, ?, ?, ?, ?, ?)';
            $params[] = $r['worker_id'];
            $params[] = (string) $r['hourly_cost'];
            $params[] = $r['min_monthly_hours'];
            $params[] = $r['max_monthly_hours'];
            $params[] = $now;
            $params[] = $now;
        }
        $sql = 'INSERT INTO contracts (worker_id, hourly_cost, min_monthly_hours, max_monthly_hours, created_at, updated_at) VALUES '
            . implode(', ', $values)
            . ' ON CONFLICT (worker_id) DO UPDATE SET '
            . 'hourly_cost = EXCLUDED.hourly_cost, min_monthly_hours = EXCLUDED.min_monthly_hours, '
            . 'max_monthly_hours = EXCLUDED.max_monthly_hours, updated_at = EXCLUDED.updated_at';
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Map worker ids to their contract id.
     *
     * @param  list<string>  $workerIds
     * @return array<string, int>
     */
    public function contractIdsByWorkerIds(array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($workerIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, worker_id FROM contracts WHERE worker_id IN ($in)");
        $stmt->execute($workerIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['worker_id']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * Delete all availability for the given contract ids.
     *
     * @param  list<int>  $contractIds
     * @return void
     */
    public function deleteAvailabilityFor(array $contractIds): void
    {
        if ($contractIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($contractIds), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM contract_availability WHERE contract_id IN ($in)");
        $stmt->execute($contractIds);
    }

    /**
     * Bulk insert availability rows.
     *
     * @param  list<array{contract_id: int, day_of_week: int, shift_id: int}>  $rows
     * @return void
     */
    public function insertAvailabilityRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($rows as $r) {
            $values[] = '(?, ?, ?)';
            $params[] = $r['contract_id'];
            $params[] = $r['day_of_week'];
            $params[] = $r['shift_id'];
        }
        $sql = 'INSERT INTO contract_availability (contract_id, day_of_week, shift_id) VALUES ' . implode(', ', $values);
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Availability rows (with shift) for the given contract ids, grouped by
     * contract id. Ordered by id to preserve insertion order, matching Eloquent.
     *
     * @param  list<int>  $contractIds
     * @return array<int, list<ContractAvailability>>
     */
    private function availabilityFor(array $contractIds): array
    {
        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT ca.contract_id, ca.day_of_week,
                    s.id AS shift__id, s.code AS shift__code,
                    s.start_time AS shift__start_time, s.end_time AS shift__end_time,
                    s.duration_hours AS shift__duration_hours
             FROM contract_availability ca
             JOIN shifts s ON s.id = ca.shift_id
             WHERE ca.contract_id IN ($placeholders)
             ORDER BY ca.contract_id, ca.id",
        );
        $stmt->execute($contractIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['contract_id']][] = new ContractAvailability(
                (int) $row['day_of_week'],
                new Shift(
                    (int) $row['shift__id'],
                    $row['shift__code'],
                    $row['shift__start_time'],
                    $row['shift__end_time'],
                    (int) $row['shift__duration_hours'],
                ),
            );
        }

        return $grouped;
    }
}
