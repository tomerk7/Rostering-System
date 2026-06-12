<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\BenchmarkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BenchmarkRosterRequest;
use App\Services\Rostering\RosterBenchmark;
use Illuminate\Http\JsonResponse;

final class RosterBenchmarkController extends Controller
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct(
        private readonly RosterBenchmark $benchmark,
    ) {}

    /**
     * Run a plain vs cost-optimized generation benchmark for the given month
     * in the current year. Both runs are previews only — nothing is saved.
     */
    public function __invoke(BenchmarkRosterRequest $request): JsonResponse
    {
        try {
            $result = $this->benchmark->run(
                (int) now()->year,
                (int) $request->validated('month'),
            );
        } catch (BenchmarkException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
            );
        }

        return $this->response(
            success: true,
            message: 'Benchmark completed successfully.',
            status: 200,
            data: $result->toArray(),
        );
    }
}
