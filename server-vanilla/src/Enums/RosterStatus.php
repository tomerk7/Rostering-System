<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a roster generation job, persisted on `rosters.status`.
 */
enum RosterStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
