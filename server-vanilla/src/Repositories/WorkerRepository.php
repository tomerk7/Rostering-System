<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Role;
use App\Data\Worker;
use App\Support\DB;
use PDO;

/**
 * Reads the `workers` table, with role and contract relations loaded.
 */
final class WorkerRepository
{
    private const string SELECT = 'w.israeli_id, w.full_name, w.is_active::int AS is_active,
                                   w.deleted_at, w.created_at, w.updated_at,
                                   r.id AS role__id, r.code AS role__code, r.name AS role__name';

    private PDO $pdo;
    private ContractRepository $contracts;

    /**
     * Class constructor.
     *
     * @param PDO|null $pdo
     * @param ContractRepository|null $contracts
     */
    public function __construct(?PDO $pdo = null, ?ContractRepository $contracts = null)
    {
        $this->pdo = $pdo ?? DB::connect();
        $this->contracts = $contracts ?? new ContractRepository($this->pdo);
    }

    /**
     * Count workers matching the given filters.
     *
     * @param  array<string, mixed>  $filters
     * @return int
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM workers w LEFT JOIN roles r ON r.id = w.role_id $where",
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A page of workers (ordered by full_name) matching the filters, each with
     * role and contract+availability loaded.
     *
     * @param  array<string, mixed>  $filters
     * @param int $limit
     * @param int $offset
     * @return list<Worker>
     */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $limit = max(0, $limit);
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . " FROM workers w
             LEFT JOIN roles r ON r.id = w.role_id
             $where
             ORDER BY w.full_name
             LIMIT $limit OFFSET $offset",
        );
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    /**
     * Find a single non-trashed worker by israeli id, with relations loaded.
     *
     * @param string $israeliId
     * @return Worker|null
     */
    public function find(string $israeliId): ?Worker
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM workers w
             LEFT JOIN roles r ON r.id = w.role_id
             WHERE w.israeli_id = ? AND w.deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute([$israeliId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate([$row])[0];
    }

    /**
     * Count workers (including soft-deleted) matching any of the given ids.
     *
     * @param list<string> $ids
     * @return int
     */
    public function countAnyWithTrashed(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT count(*) FROM workers WHERE israeli_id IN ($in)");
        $stmt->execute($ids);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Un-trash any soft-deleted workers among the given ids (CSV import restores
     *
     * @param  list<string>  $ids
     * @return void
     */
    public function restoreTrashed(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE workers SET deleted_at = NULL WHERE israeli_id IN ($in) AND deleted_at IS NOT NULL",
        );
        $stmt->execute($ids);
    }

    /**
     * Bulk insert/update workers keyed on israeli_id (CSV import).
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
            $params[] = $r['israeli_id'];
            $params[] = $r['full_name'];
            $params[] = $r['role_id'];
            $params[] = $r['is_active'] ? 'true' : 'false';
            $params[] = $now;
            $params[] = $now;
        }
        $sql = 'INSERT INTO workers (israeli_id, full_name, role_id, is_active, created_at, updated_at) VALUES '
            . implode(', ', $values)
            . ' ON CONFLICT (israeli_id) DO UPDATE SET '
            . 'full_name = EXCLUDED.full_name, role_id = EXCLUDED.role_id, '
            . 'is_active = EXCLUDED.is_active, updated_at = EXCLUDED.updated_at';
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * All non-trashed workers ordered by israeli_id, with role + contract +
     * availability loaded (for CSV export).
     *
     * @return list<Worker>
     */
    public function allForExport(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::SELECT . ' FROM workers w
             LEFT JOIN roles r ON r.id = w.role_id
             WHERE w.deleted_at IS NULL
             ORDER BY w.israeli_id',
        );

        return $this->hydrate($stmt->fetchAll());
    }

    /**
     * Full names of all non-trashed workers, keyed by israeli_id. Mirrors the
     * benchmark's `DB::table('workers')->whereNull('deleted_at')->pluck('full_name','israeli_id')`.
     *
     * @return array<string, string>
     */
    public function namesByIdExcludingTrashed(): array
    {
        $rows = $this->pdo->query(
            'SELECT israeli_id, full_name FROM workers WHERE deleted_at IS NULL',
        )->fetchAll();

        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['israeli_id']] = $row['full_name'];
        }

        return $names;
    }

    /**
     * Whether a worker exists, including soft-deleted ones (for restore).
     *
     * @param string $israeliId
     * @return bool
     */
    public function existsWithTrashed(string $israeliId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM workers WHERE israeli_id = ? LIMIT 1');
        $stmt->execute([$israeliId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Insert a new worker (timestamps set, not trashed).
     *
     * @param string $israeliId
     * @param string $fullName
     * @param int $roleId
     * @param bool $isActive
     * @return void
     */
    public function insert(string $israeliId, string $fullName, int $roleId, bool $isActive): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO workers (israeli_id, full_name, role_id, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$israeliId, $fullName, $roleId, $isActive ? 'true' : 'false', $now, $now]);
    }

    /**
     * Update a worker's editable fields.
     *
     * @param string $israeliId
     * @param string $fullName
     * @param int $roleId
     * @param bool $isActive
     * @return void
     */
    public function updateFields(string $israeliId, string $fullName, int $roleId, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET full_name = ?, role_id = ?, is_active = ?, updated_at = ? WHERE israeli_id = ?',
        );
        $stmt->execute([$fullName, $roleId, $isActive ? 'true' : 'false', gmdate('Y-m-d H:i:s'), $israeliId]);
    }

    /**
     * Mark a worker inactive (without soft-deleting).
     *
     * @param string $israeliId
     * @return void
     */
    public function deactivate(string $israeliId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET is_active = false, updated_at = ? WHERE israeli_id = ?',
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $israeliId]);
    }

    /**
     * Soft-delete a worker (archived + inactive).
     *
     * @param string $israeliId
     * @return void
     */
    public function softDelete(string $israeliId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET is_active = false, deleted_at = ?, updated_at = ? WHERE israeli_id = ?',
        );
        $stmt->execute([$now, $now, $israeliId]);
    }

    /**
     * Restore a soft-deleted worker as active.
     *
     * @param string $israeliId
     * @return void
     */
    public function restore(string $israeliId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET deleted_at = NULL, is_active = true, updated_at = ? WHERE israeli_id = ?',
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $israeliId]);
    }

    /**
     * Soft-delete (and deactivate) every non-trashed worker, in one statement.
     *
     * @return void
     */
    public function softDeleteAll(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET is_active = false, deleted_at = ?, updated_at = ? WHERE deleted_at IS NULL',
        );
        $stmt->execute([$now, $now]);
    }

    /**
     * The ids of every soft-deleted worker (for restore-all).
     *
     * @return list<string>
     */
    public function trashedIds(): array
    {
        $rows = $this->pdo
            ->query('SELECT israeli_id FROM workers WHERE deleted_at IS NOT NULL ORDER BY israeli_id')
            ->fetchAll();

        return array_map(static fn (array $row): string => (string) $row['israeli_id'], $rows);
    }

    /**
     * Restore (and reactivate) every soft-deleted worker, in one statement.
     *
     * @return void
     */
    public function restoreAllTrashed(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE workers SET deleted_at = NULL, is_active = true, updated_at = ? WHERE deleted_at IS NOT NULL',
        );
        $stmt->execute([gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Build Worker DTOs from rows, batch-loading their contracts.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Worker>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => (string) $row['israeli_id'], $rows);
        $contracts = $this->contracts->forWorkerIds($ids);

        return array_map(static fn (array $row): Worker => new Worker(
            (string) $row['israeli_id'],
            $row['full_name'],
            (bool) (int) $row['is_active'],
            $row['deleted_at'],
            $row['created_at'],
            $row['updated_at'],
            $row['role__id'] === null ? null : new Role(
                (int) $row['role__id'],
                $row['role__code'],
                $row['role__name'],
            ),
            $contracts[(string) $row['israeli_id']] ?? null,
        ), $rows);
    }

    /**
     * Translate request filters into a WHERE clause and bound params.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // Soft-delete scope: default hides trashed, matching Eloquent.
        $trashed = $filters['trashed'] ?? '';
        if ($trashed === 'only') {
            $conditions[] = 'w.deleted_at IS NOT NULL';
        } elseif ($trashed !== 'with') {
            $conditions[] = 'w.deleted_at IS NULL';
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = (string) $filters['search'];
            $conditions[] = '(LOWER(w.full_name) LIKE ? OR w.israeli_id LIKE ?)';
            $params[] = '%' . mb_strtolower($search) . '%';
            $params[] = '%' . $search . '%';
        }

        if (isset($filters['role_id'])) {
            $conditions[] = 'w.role_id = ?';
            $params[] = (int) $filters['role_id'];
        }

        if (isset($filters['role_code']) && $filters['role_code'] !== '') {
            $conditions[] = 'r.code = ?';
            $params[] = (string) $filters['role_code'];
        }

        if (array_key_exists('is_active', $filters)) {
            // Derived server-side, so a literal is safe (avoids bool param typing).
            $conditions[] = $filters['is_active'] ? 'w.is_active = true' : 'w.is_active = false';
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }
}
