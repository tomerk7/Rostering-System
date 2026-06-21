<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RoleCode;
use PHPUnit\Framework\TestCase;

final class RoleCodeTest extends TestCase
{
    public function testLabel(): void
    {
        $this->assertSame('General Guard', RoleCode::GeneralGuard->label());
        $this->assertSame('Supervisor', RoleCode::Supervisor->label());
        $this->assertSame('Screener', RoleCode::Screener->label());
    }

    public function testCsvLabel(): void
    {
        // CSV labels are the lowercased display names.
        $this->assertSame('general guard', RoleCode::GeneralGuard->csvLabel());
        $this->assertSame('supervisor', RoleCode::Supervisor->csvLabel());
        $this->assertSame('screener', RoleCode::Screener->csvLabel());
    }

    public function testCodeByCsvLabel(): void
    {
        $this->assertSame([
            'general guard' => 'general_guard',
            'supervisor' => 'supervisor',
            'screener' => 'screener',
        ], RoleCode::codeByCsvLabel());
    }

    public function testLabelForCode(): void
    {
        $this->assertSame('General Guard', RoleCode::labelForCode('general_guard'));
        // Unknown codes fall through to the code itself.
        $this->assertSame('mystery_role', RoleCode::labelForCode('mystery_role'));
    }
}
