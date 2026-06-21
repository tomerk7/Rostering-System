<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\Data\OptimizerConfig;
use App\Support\DB;
use PDO;

/**
 * Derive optimizer penalty baselines from the workforce and demand currently
 * stored in the database. Recommendations are advisory and never mutate config.
 *
 * only the three data reads (Eloquent query builder → raw PDO) and the Collection
 * helpers (→ plain arrays) differ. All the math methods are unchanged.
 */
class OptimizerPenaltyAdvisor
{
    /** Seeded shifts are 8h; the SA balance penalty counts excess *shifts*. */
    private const int SHIFT_HOURS = 8;

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
     * The optimizer penalties for a distribution preference.
     *
     * @param DistributionPreference $preference
     * @return array{shortfallPenaltyPerHour: float, balanceWeight: float}
     */
    public function penaltiesFor(DistributionPreference $preference): array
    {
        $maxRate = 0.0;
        $maxRoleWageSpread = 0.0;

        foreach ($this->activeHourlyRatesByRole() as $rates) {
            $maxRate = max($maxRate, max($rates));
            $maxRoleWageSpread = max($maxRoleWageSpread, max($rates) - min($rates));
        }

        return [
            'shortfallPenaltyPerHour' => max(OptimizerConfig::DEFAULT_SHORTFALL_PENALTY_PER_HOUR, $maxRate),
            'balanceWeight' => $this->balanceWeight($maxRoleWageSpread, $preference->balanceToleranceShifts()),
        ];
    }

    /**
     * Balance weight for a tolerance of `toleranceShifts` extra shifts above a
     * worker's minimum (null = never balance → 0).
     *
     * @param float $maxRoleWageSpread
     * @param int|null $toleranceShifts
     * @return float
     */
    private function balanceWeight(float $maxRoleWageSpread, ?int $toleranceShifts): float
    {
        if ($toleranceShifts === null) {
            return 0.0;
        }

        $wageGapPerShift = max($maxRoleWageSpread, 1.0) * self::SHIFT_HOURS;

        return round($wageGapPerShift / (2 * $toleranceShifts - 1), 2);
    }

    /**
     * Active, non-deleted workers' hourly costs grouped by role.
     *
     * @return array<int, list<float>>
     */
    private function activeHourlyRatesByRole(): array
    {
        $byRole = [];

        $rows = $this->pdo->query(
            'SELECT workers.role_id, contracts.hourly_cost
             FROM contracts
             JOIN workers ON workers.israeli_id = contracts.worker_id
             WHERE workers.is_active = true AND workers.deleted_at IS NULL',
        )->fetchAll();

        foreach ($rows as $row) {
            $byRole[(int) $row['role_id']][] = (float) $row['hourly_cost'];
        }

        return $byRole;
    }
}
