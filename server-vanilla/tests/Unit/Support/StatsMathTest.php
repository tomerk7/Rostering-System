<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StatsMath;
use App\Support\WorkerStatsRow;
use PHPUnit\Framework\TestCase;

final class StatsMathTest extends TestCase
{
    /**
     * @return list<WorkerStatsRow>
     */
    private function rows(): array
    {
        $rows = [];
        // Six workers with strictly increasing cost and hours so every ordering
        // is unambiguous and the size-5 cap drops exactly one entry per board.
        foreach (range(1, 6) as $i) {
            $rows[] = new WorkerStatsRow(
                workerId: "w{$i}",
                name: "Worker {$i}",
                role: 'supervisor',
                minHours: 160,
                maxHours: 200,
                actualHours: $i * 10,
                totalCost: $i * 100.0,
                shortfallHours: 0,
            );
        }

        return $rows;
    }

    public function testLeaderboards(): void
    {
        $boards = $this->rows();
        $result = StatsMath::leaderboards($boards);

        $this->assertSame(['highest_paid', 'lowest_paid', 'most_hours', 'fewest_hours'], array_keys($result));

        // Every board is capped at five entries.
        foreach ($result as $board) {
            $this->assertCount(5, $board);
        }

        // Highest paid: descending cost, dropping the cheapest (w1).
        $this->assertSame(['w6', 'w5', 'w4', 'w3', 'w2'], array_column($result['highest_paid'], 'worker_id'));
        // Lowest paid: ascending cost, dropping the priciest (w6).
        $this->assertSame(['w1', 'w2', 'w3', 'w4', 'w5'], array_column($result['lowest_paid'], 'worker_id'));
        // Most hours: descending hours.
        $this->assertSame(['w6', 'w5', 'w4', 'w3', 'w2'], array_column($result['most_hours'], 'worker_id'));
        // Fewest hours: ascending hours.
        $this->assertSame(['w1', 'w2', 'w3', 'w4', 'w5'], array_column($result['fewest_hours'], 'worker_id'));

        // Each entry is the slim shape with the board's own value key.
        $this->assertSame(
            ['worker_id' => 'w6', 'name' => 'Worker 6', 'total_cost' => 600.0],
            $result['highest_paid'][0],
        );
        $this->assertSame(
            ['worker_id' => 'w6', 'name' => 'Worker 6', 'actual_hours' => 60],
            $result['most_hours'][0],
        );
    }
}
