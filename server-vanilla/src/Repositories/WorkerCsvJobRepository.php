<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\DB;
use App\Support\Uuid;
use PDO;
use Random\RandomException;

/**
 * The vanilla CSV job queue + status store (`worker_csv_jobs`). FPM enqueues
 * jobs; the worker daemon claims and completes them; status/download endpoints
 * read them back.
 */
final class WorkerCsvJobRepository
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
     * Enqueue an import job carrying the uploaded CSV; returns the job id.
     *
     * @param string $csv
     * @return string
     * @throws RandomException
     */
    public function enqueueImport(string $csv): string
    {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            "INSERT INTO worker_csv_jobs (id, type, state, payload) VALUES (?, 'import', 'queued', ?)",
        );
        $stmt->execute([$id, $csv]);

        return $id;
    }

    /**
     * Enqueue an export job; returns the job id.
     *
     * @return string
     * @throws RandomException
     */
    public function enqueueExport(): string
    {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            "INSERT INTO worker_csv_jobs (id, type, state, payload) VALUES (?, 'export', 'queued', NULL)",
        );
        $stmt->execute([$id]);

        return $id;
    }

    /**
     * Atomically claim the oldest queued job, marking it processing. Returns the
     * row (id, type, payload) or null when the queue is empty.
     *
     * @return array{id: string, type: string, payload: ?string}|null
     */
    public function claimNext(): ?array
    {
        $stmt = $this->pdo->query(
            "UPDATE worker_csv_jobs
             SET state = 'processing', reserved_at = now(), updated_at = now()
             WHERE id = (
                 SELECT id FROM worker_csv_jobs
                 WHERE state = 'queued'
                 ORDER BY created_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING id, type, payload",
        );
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Find a job by id.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker_csv_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Mark an import completed with its result summary and per-row errors.
     *
     * @param string $id
     * @param array<string, int> $result
     * @param list<array{line: int, field: string, message: string}> $errors
     */
    public function markImportCompleted(string $id, array $result, array $errors): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE worker_csv_jobs
             SET state = 'completed', result = ?, errors = ?, updated_at = now()
             WHERE id = ?",
        );
        $stmt->execute([json_encode($result), json_encode($errors), $id]);
    }

    /**
     * Mark an export completed with its filename and generated CSV content.
     *
     * @param string $id
     * @param string $filename
     * @param string $content
     */
    public function markExportCompleted(string $id, string $filename, string $content): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE worker_csv_jobs
             SET state = 'completed', result = ?, content = ?, updated_at = now()
             WHERE id = ?",
        );
        $stmt->execute([json_encode(['filename' => $filename]), $content, $id]);
    }

    /**
     * Mark a job failed with a message.
     *
     * @param string $id
     * @param string $message
     */
    public function markFailed(string $id, string $message): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE worker_csv_jobs SET state = 'failed', message = ?, updated_at = now() WHERE id = ?",
        );
        $stmt->execute([$message, $id]);
    }

    /**
     * Read a completed export's [filename, content] and delete the row (mirrors
     * stream-then-forget). Returns null if not present/ready.
     *
     * @param string $id
     * @return array{filename: string, content: string}|null
     */
    public function takeExport(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT content, result FROM worker_csv_jobs WHERE id = ? AND type = 'export' AND state = 'completed' LIMIT 1",
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $delete = $this->pdo->prepare('DELETE FROM worker_csv_jobs WHERE id = ?');
        $delete->execute([$id]);

        $result = json_decode((string) $row['result'], true);

        return [
            'filename' => $result['filename'] ?? 'workers.csv',
            'content' => (string) $row['content'],
        ];
    }
}
