<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class WorkersImported
{
    use Dispatchable;

    /**
     * Create a new event instance.
     * 
     * @param int $created
     * @param int $updated
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
    ) {}
}
