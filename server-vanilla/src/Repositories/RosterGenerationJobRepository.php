<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Rostering\Data\DistributionPreference;
use App\Support\DB;
use App\Support\Uuid;
use PDO;
use Random\RandomException;

/**
 * The vanilla roster-generation job queue (`roster_generation_jobs`). FPM
 * enqueues a job carrying the target roster and optimizer settings; the worker
 * daemon claims and runs it. The visible status the client polls lives on
 * `rosters.status`, not here.
 */
final class RosterGenerationJobRepository
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
     * Enqueue a generation job for a roster; returns the job id. The chosen
     * distribution preference rides on the row as a categorical value; the worker
     * resolves it to data-driven optimizer penalties when it runs the job.
     *
     * @param int $rosterId
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return string
     * @throws RandomException
     */
    public function enqueue(int $rosterId, bool $optimizeCost, ?DistributionPreference $preference): string
    {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            "INSERT INTO roster_generation_jobs (id, roster_id, optimize_cost, distribution_preference, state)
             VALUES (?, ?, ?, ?, 'queued')",
        );
        $stmt->execute([$id, $rosterId, $optimizeCost ? 1 : 0, $preference?->value]);

        return $id;
    }

    /**
     * Atomically claim the oldest queued job, marking it processing. Returns the
     * row (id, roster_id, optimize_cost, distribution_preference) or null when the
     * queue is empty.
     *
     * @return array{id: string, roster_id: int, optimize_cost: bool, distribution_preference: ?DistributionPreference}|null
     */
    public function claimNext(): ?array
    {
        $stmt = $this->pdo->query(
            "UPDATE roster_generation_jobs
             SET state = 'processing', reserved_at = now(), updated_at = now()
             WHERE id = (
                 SELECT id FROM roster_generation_jobs
                 WHERE state = 'queued'
                 ORDER BY created_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING id, roster_id, optimize_cost, distribution_preference",
        );
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'roster_id' => (int) $row['roster_id'],
            'optimize_cost' => (bool) $row['optimize_cost'],
            'distribution_preference' => $row['distribution_preference'] === null
                ? null
                : DistributionPreference::from((string) $row['distribution_preference']),
        ];
    }

    /**
     * Mark a job completed.
     *
     * @param string $id
     * @return void
     */
    public function markCompleted(string $id): void
    {
        $this->pdo->prepare(
            "UPDATE roster_generation_jobs SET state = 'completed', updated_at = now() WHERE id = ?",
        )->execute([$id]);
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
            "UPDATE roster_generation_jobs SET state = 'failed', message = ?, updated_at = now() WHERE id = ?",
        )->execute([$message, $id]);
    }
}
