<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\WorkerStatsRow;
use PHPUnit\Framework\TestCase;

final class WorkerStatsRowTest extends TestCase
{
    public function testToArray(): void
    {
        $row = new WorkerStatsRow(
            workerId: 'w1',
            name: 'Dana Cohen',
            role: 'supervisor',
            minHours: 160,
            maxHours: 200,
            actualHours: 184,
            totalCost: 7360.5,
            shortfallHours: 0,
        );

        $this->assertSame([
            'worker_id' => 'w1',
            'name' => 'Dana Cohen',
            'role' => 'supervisor',
            'min_hours' => 160,
            'max_hours' => 200,
            'actual_hours' => 184,
            'total_cost' => 7360.5,
            'shortfall_hours' => 0,
        ], $row->toArray());
    }
}
