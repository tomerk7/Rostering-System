<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\DB;
use App\Support\Uuid;
use PDO;
use Random\RandomException;

/**
 * The vanilla roster CSV export job queue + content store (`roster_export_jobs`).
 * FPM enqueues an export for a roster; the worker daemon claims and renders it;
 * the status/download endpoints read it back. Scoped to a roster, similar to the
 * worker CSV export job repository.
 */
final class RosterExportJobRepository
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
     * Enqueue an export for a roster; returns the export id.
     *
     * @param int $rosterId
     * @return string
     * @throws RandomException
     */
    public function enqueue(int $rosterId): string
    {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            "INSERT INTO roster_export_jobs (id, roster_id, state) VALUES (?, ?, 'queued')",
        );
        $stmt->execute([$id, $rosterId]);

        return $id;
    }

    /**
     * Atomically claim the oldest queued job, marking it processing. Returns the
     * row (id, roster_id) or null when the queue is empty.
     *
     * @return array{id: string, roster_id: int}|null
     */
    public function claimNext(): ?array
    {
        $stmt = $this->pdo->query(
            "UPDATE roster_export_jobs
             SET state = 'processing', reserved_at = now(), updated_at = now()
             WHERE id = (
                 SELECT id FROM roster_export_jobs
                 WHERE state = 'queued'
                 ORDER BY created_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING id, roster_id",
        );
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return ['id' => (string) $row['id'], 'roster_id' => (int) $row['roster_id']];
    }

    /**
     * Find a job by id.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roster_export_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Mark an export completed with its filename and generated CSV content.
     *
     * @param string $id
     * @param string $filename
     * @param string $content
     * @return void
     */
    public function markCompleted(string $id, string $filename, string $content): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE roster_export_jobs
             SET state = 'completed', filename = ?, content = ?, updated_at = now()
             WHERE id = ?",
        );
        $stmt->execute([$filename, $content, $id]);
    }

    /**
     * Mark a job failed with a message.
     *
     * @param string $id
     * @param string $message
     * @return void
     */
    public function markFailed(string $id, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE roster_export_jobs SET state = 'failed', message = ?, updated_at = now() WHERE id = ?",
        )->execute([$message, $id]);
    }

    /**
     * Read a completed export's [filename, content] scoped to its roster and
     * delete the row (stream-then-forget). Returns null if absent/not ready/not
     * this roster's.
     *
     * @param string $id
     * @param int $rosterId
     * @return array{filename: string, content: string}|null
     */
    public function takeExport(string $id, int $rosterId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT content, filename FROM roster_export_jobs
             WHERE id = ? AND roster_id = ? AND state = 'completed' LIMIT 1",
        );
        $stmt->execute([$id, $rosterId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $this->pdo->prepare('DELETE FROM roster_export_jobs WHERE id = ?')->execute([$id]);

        return [
            'filename' => $row['filename'] ?? 'roster.csv',
            'content' => (string) $row['content'],
        ];
    }
}
