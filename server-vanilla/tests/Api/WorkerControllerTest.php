<?php

declare(strict_types=1);

namespace Tests\Api;

final class WorkerControllerTest extends ApiTestCase
{
    public function testReferenceData(): void
    {
        $response = $this->call('GET', '/api/workers/reference-data', [], $this->authHeader());

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);
        $data = $response['json']['data'];
        // The seeded reference data: 3 roles, 3 shifts, and the staffing demand.
        $this->assertCount(3, $data['roles']);
        $this->assertCount(3, $data['shifts']);
        $this->assertArrayHasKey('shift_role_requirements', $data);

        // Protected route → 401 without a token.
        $this->assertSame(401, $this->call('GET', '/api/workers/reference-data')['status']);
    }

    public function testIndex(): void
    {
        // The test DB starts with no workers, so the page is empty but the
        // pagination meta is always present and well-formed.
        $response = $this->call('GET', '/api/workers', [], $this->authHeader());

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);
        $this->assertSame([], $response['json']['data']);
        $this->assertSame(0, $response['json']['meta']['total']);
        $this->assertSame(1, $response['json']['meta']['current_page']);

        // After creating one worker it shows up and the total reflects it.
        $this->createWorker();
        $after = $this->call('GET', '/api/workers', [], $this->authHeader());
        $this->assertCount(1, $after['json']['data']);
        $this->assertSame(1, $after['json']['meta']['total']);
    }

    public function testStore(): void
    {
        // Valid payload → 201 with the created worker.
        $created = $this->createWorker(['israeli_id' => '123456782']);
        $this->assertSame(201, $created['status']);
        $this->assertTrue($created['json']['success']);
        $this->assertSame('123456782', $created['json']['data']['israeli_id']);
        $this->assertSame('Dana Cohen', $created['json']['data']['full_name']);

        // Invalid payload (bad israeli id + missing role) → 422 with field errors.
        $invalid = $this->call('POST', '/api/workers', [
            'full_name' => 'Bad Worker',
            'israeli_id' => '123', // not 9 digits
            'is_active' => true,
            'contract' => ['hourly_cost' => 50, 'min_monthly_hours' => 80, 'max_monthly_hours' => 160],
            'availability' => [['day_of_week' => 0, 'shift_id' => 1]],
        ], $this->authHeader());

        $this->assertSame(422, $invalid['status']);
        $this->assertArrayHasKey('israeli_id', $invalid['json']['errors']);
        $this->assertArrayHasKey('role_id', $invalid['json']['errors']);
    }

    public function testShow(): void
    {
        $this->createWorker(['israeli_id' => '123456782']);

        // Existing worker → 200 with its detail.
        $found = $this->call('GET', '/api/workers/123456782', [], $this->authHeader());
        $this->assertSame(200, $found['status']);
        $this->assertSame('123456782', $found['json']['data']['israeli_id']);

        // Unknown israeli id → 404.
        $missing = $this->call('GET', '/api/workers/999999999', [], $this->authHeader());
        $this->assertSame(404, $missing['status']);
        $this->assertFalse($missing['json']['success']);
    }

    public function testDestroy(): void
    {
        $this->createWorker(['israeli_id' => '123456782']);

        // Soft-delete removes it from the default (non-trashed) lookups.
        $deleted = $this->call('DELETE', '/api/workers/123456782', [], $this->authHeader());
        $this->assertSame(200, $deleted['status']);
        $this->assertTrue($deleted['json']['success']);
        $this->assertSame(404, $this->call('GET', '/api/workers/123456782', [], $this->authHeader())['status']);

        // Deleting an unknown worker → 404.
        $this->assertSame(404, $this->call('DELETE', '/api/workers/999999999', [], $this->authHeader())['status']);
    }

    public function testDeactivate(): void
    {
        $this->createWorker(['israeli_id' => '123456782', 'is_active' => true]);

        $response = $this->call('POST', '/api/workers/123456782/deactivate', [], $this->authHeader());
        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['json']['success']);

        // Still present (deactivate is distinct from soft-delete) but inactive.
        $worker = $this->call('GET', '/api/workers/123456782', [], $this->authHeader());
        $this->assertSame(200, $worker['status']);
        $this->assertFalse($worker['json']['data']['is_active']);
    }

    public function testRestore(): void
    {
        $this->createWorker(['israeli_id' => '123456782']);
        $this->call('DELETE', '/api/workers/123456782', [], $this->authHeader());

        // Restore brings a soft-deleted worker back.
        $restored = $this->call('POST', '/api/workers/123456782/restore', [], $this->authHeader());
        $this->assertSame(200, $restored['status']);
        $this->assertTrue($restored['json']['success']);
        $this->assertSame(200, $this->call('GET', '/api/workers/123456782', [], $this->authHeader())['status']);
    }

    public function testDeleteAll(): void
    {
        $this->createWorker(['israeli_id' => '123456782']);
        $this->createWorker(['israeli_id' => '123456790']);

        // Bulk soft-delete then restore round-trips the counts.
        $deleted = $this->call('POST', '/api/workers/delete-all', [], $this->authHeader());
        $this->assertSame(200, $deleted['status']);
        $this->assertSame(2, $deleted['json']['data']['deleted']);
        $this->assertSame(0, $this->call('GET', '/api/workers', [], $this->authHeader())['json']['meta']['total']);

        $restored = $this->call('POST', '/api/workers/restore-all', [], $this->authHeader());
        $this->assertSame(2, $restored['json']['data']['restored']);
        $this->assertSame(2, $this->call('GET', '/api/workers', [], $this->authHeader())['json']['meta']['total']);
    }
}
