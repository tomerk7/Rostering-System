<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\OptimizerPenaltyAdvisor;
use Tests\TestCase;

/**
 * The advisor scales the optimizer's balance weight to the live within-role wage
 * spread, so the distribution presets produce genuinely different weights. A flat
 * per-preset magnitude got swamped by the cost term and made every preset land on
 * the same roster.
 */
final class OptimizerPenaltyAdvisorTest extends TestCase
{
    public function testBalanceWeightScalesWithWageSpreadAndDiffersPerPreset(): void
    {
        // Two supervisors (role 2) paid 40 and 70 -> within-role spread 30.
        $this->seedWorker('100000001', roleId: 2, hourlyCost: 40);
        $this->seedWorker('100000002', roleId: 2, hourlyCost: 70);

        $advisor = new OptimizerPenaltyAdvisor($this->db);

        // wageGapPerShift = spread(30) * 8h = 240; weight = 240 / (2 * tolerance - 1).
        $this->assertSame(0.0, $advisor->penaltiesFor(DistributionPreference::MaximumSavings)['balanceWeight']);   // never
        $this->assertSame(12.63, $advisor->penaltiesFor(DistributionPreference::CostFocused)['balanceWeight']);    // gap 10 -> 240/19
        $this->assertSame(34.29, $advisor->penaltiesFor(DistributionPreference::Balanced)['balanceWeight']);       // gap 4  -> 240/7
        $this->assertSame(240.0, $advisor->penaltiesFor(DistributionPreference::DistributionFocused)['balanceWeight']); // gap 1  -> 240/1

        // The shortfall penalty is the priciest worker's rate (>= the 45 default).
        $this->assertSame(70.0, $advisor->penaltiesFor(DistributionPreference::Balanced)['shortfallPenaltyPerHour']);
    }

    /**
     * Insert an active worker and their contract.
     */
    private function seedWorker(string $israeliId, int $roleId, float $hourlyCost): void
    {
        $this->db->prepare(
            'INSERT INTO workers (israeli_id, full_name, role_id, is_active, created_at, updated_at)
             VALUES (?, ?, ?, true, now(), now())',
        )->execute([$israeliId, 'Test '.$israeliId, $roleId]);

        $this->db->prepare(
            'INSERT INTO contracts (worker_id, hourly_cost, min_monthly_hours, max_monthly_hours, created_at, updated_at)
             VALUES (?, ?, 80, 160, now(), now())',
        )->execute([$israeliId, $hourlyCost]);
    }
}
