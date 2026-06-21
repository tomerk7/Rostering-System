<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering\Data;

use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class GenerationResultTest extends TestCase
{
    public function testHasCoverageShortages(): void
    {
        $date = CarbonImmutable::parse('2026-01-01');

        $without = new GenerationResult(2026, 1, [], [], []);
        $this->assertFalse($without->hasCoverageShortages());

        $with = new GenerationResult(2026, 1, [], [
            ['work_date' => $date, 'shift_id' => 1, 'role_id' => 1, 'required' => 2, 'assigned' => 1],
        ], []);
        $this->assertTrue($with->hasCoverageShortages());
    }

    public function testHasHoursShortfalls(): void
    {
        $without = new GenerationResult(2026, 1, [], [], []);
        $this->assertFalse($without->hasHoursShortfalls());

        $with = new GenerationResult(2026, 1, [], [], [
            ['worker_id' => 'w1', 'min_hours' => 160, 'scheduled_hours' => 120],
        ]);
        $this->assertTrue($with->hasHoursShortfalls());
    }
}
