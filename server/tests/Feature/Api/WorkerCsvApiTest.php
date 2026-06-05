<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class WorkerCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_export_import_round_trip_is_idempotent(): void
    {
        $sampleRows = $this->csvRowCount(database_path('data/workers-sample.csv'));

        $first = $this->importFile(database_path('data/workers-sample.csv'));
        $first
            ->assertOk()
            ->assertJsonPath('data.total', $sampleRows)
            ->assertJsonPath('data.created', $sampleRows)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseCount('workers', $sampleRows);
        $this->assertDatabaseCount('contracts', $sampleRows);

        $firstExport = $this->get('/api/workers/export');
        $firstExport->assertOk();
        $exportedCsv = $firstExport->streamedContent();

        $reimportPath = $this->writeTempCsv($exportedCsv);
        $second = $this->importFile($reimportPath);
        $second
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', $sampleRows)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseCount('workers', $sampleRows);
        $this->assertDatabaseCount('contracts', $sampleRows);

        $secondExport = $this->get('/api/workers/export');
        $secondExport->assertOk();

        self::assertSame($exportedCsv, $secondExport->streamedContent());
    }

    public function test_import_reports_per_row_errors_and_skips_invalid_rows(): void
    {
        $csv = implode("\n", [
            'full_name,israeli_id,role,status,hourly_cost,min_monthly_hours,max_monthly_hours,available_days,available_shifts',
            'Valid Worker,234567816,General Guard,Active,50.00,80,160,Mon|Tue,A|B',
            'Bad Checksum,123456789,General Guard,Active,50.00,80,160,Mon,A',
            'Bad Range,314159260,Supervisor,Active,50.00,160,80,Mon,A',
        ]) . "\n";

        $response = $this->importFile($this->writeTempCsv($csv));

        $response
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertDatabaseCount('workers', 1);
        self::assertNotEmpty($response->json('errors'));
    }

    private function importFile(string $path): \Illuminate\Testing\TestResponse
    {
        $upload = new UploadedFile($path, 'workers.csv', 'text/csv', null, true);

        return $this->post('/api/workers/import', ['file' => $upload], ['Accept' => 'application/json']);
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'workers_csv_') . '.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function csvRowCount(string $path): int
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return count($lines) - 1;
    }
}
