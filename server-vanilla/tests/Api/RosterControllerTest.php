<?php

declare(strict_types=1);

namespace Tests\Api;

final class RosterControllerTest extends ApiTestCase
{
    public function testIndex(): void
    {
        $response = $this->call('GET', '/api/rosters', [], $this->authHeader());

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);
        $this->assertSame([], $response['json']['data']);
        // The list carries the current year in its meta.
        $this->assertSame((int) gmdate('Y'), $response['json']['meta']['current_year']);

        // Protected route → 401 without a token.
        $this->assertSame(401, $this->call('GET', '/api/rosters')['status']);
    }

    public function testStore(): void
    {
        // A valid month with a distribution preference queues generation (202) and
        // the enqueued job carries the chosen preference (migration 0023's column).
        $response = $this->call('POST', '/api/rosters', [
            'month' => 6,
            'distribution_preference' => 'balanced',
        ], $this->authHeader());

        $this->assertSame(202, $response['status']);
        $this->assertTrue($response['json']['success']);
        $this->assertSame('processing', $response['json']['data']['status']);
        $this->assertSame(6, $response['json']['data']['month']);

        $rosterId = $response['json']['data']['id'];
        $preference = $this->db
            ->query("SELECT distribution_preference FROM roster_generation_jobs WHERE roster_id = {$rosterId}")
            ->fetchColumn();
        $this->assertSame('balanced', $preference);

        // An out-of-range month fails validation → 422.
        $bad = $this->call('POST', '/api/rosters', ['month' => 13], $this->authHeader());
        $this->assertSame(422, $bad['status']);
        $this->assertArrayHasKey('month', $bad['json']['errors']);
    }

    public function testBenchmark(): void
    {
        // No contracts exist in the test DB, so a benchmark run has nothing to
        // schedule and reports a 422 (BenchmarkException) rather than throwing.
        $noData = $this->call('POST', '/api/rosters/benchmark', [
            'month' => 6,
            'distribution_preference' => 'balanced',
        ], $this->authHeader());
        $this->assertSame(422, $noData['status']);
        $this->assertFalse($noData['json']['success']);

        // The preference is required → 422 with a field error when omitted.
        $missingPreference = $this->call('POST', '/api/rosters/benchmark', ['month' => 6], $this->authHeader());
        $this->assertSame(422, $missingPreference['status']);
        $this->assertArrayHasKey('distribution_preference', $missingPreference['json']['errors']);
    }

    public function testShow(): void
    {
        // Unknown roster → 404.
        $response = $this->call('GET', '/api/rosters/999999', [], $this->authHeader());
        $this->assertSame(404, $response['status']);
        $this->assertFalse($response['json']['success']);
    }

    public function testStats(): void
    {
        // Unknown roster → 404.
        $response = $this->call('GET', '/api/rosters/999999/stats', [], $this->authHeader());
        $this->assertSame(404, $response['status']);
    }

    public function testDestroy(): void
    {
        // Unknown roster → 404.
        $response = $this->call('DELETE', '/api/rosters/999999', [], $this->authHeader());
        $this->assertSame(404, $response['status']);
    }

    public function testRegenerate(): void
    {
        // A missing roster is a 404 before validation runs.
        $response = $this->call('POST', '/api/rosters/999999/regenerate', [], $this->authHeader());
        $this->assertSame(404, $response['status']);
    }
}
