<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Roster;
use App\Services\Rostering\RosterStatsService;
use Illuminate\Http\JsonResponse;

final class RosterStatsController extends Controller
{
    /**
     * Constructor.
     * 
     * @param RosterStatsService $statsService
     * @return void
     */
    public function __construct(
        private readonly RosterStatsService $statsService,
    ) {}

    /**
     * Per-worker statistics and summary for a saved roster.
     * 
     * @param Roster $roster
     * @return JsonResponse
     * @throws Exception
     */
    public function __invoke(Roster $roster): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Roster stats retrieved successfully.',
            status: 200,
            data: $this->statsService->forRoster($roster)->toArray(),
        );
    }
}
