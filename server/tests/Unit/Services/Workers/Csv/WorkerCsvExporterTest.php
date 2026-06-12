<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workers\Csv;

use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class WorkerCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    private WorkerCsvService $csvService;

    private Role $generalGuardRole;

    private Role $supervisorRole;

    private Shift $morningShift;

    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->csvService = $this->app->make(WorkerCsvService::class);
        $this->generalGuardRole = Role::query()->where('code', 'general_guard')->firstOrFail();
        $this->supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'B')->firstOrFail();
    }

    public function test_write_to_outputs_header_only_when_no_workers_exist(): void
    {
        $csv = $this->captureWriteTo();

        self::assertSame($this->csv([$this->csvService->headers()]), $csv);
    }

    public function test_write_to_exports_workers_ordered_by_israeli_id_with_canonical_columns(): void
    {
        $secondWorker = $this->createWorker(
            fullName: 'Second Worker',
            israeliId: $this->validIsraeliId(21111111),
            role: $this->generalGuardRole,
            isActive: true,
            hourlyCost: 50.25,
            minMonthlyHours: 80,
            maxMonthlyHours: 160,
            days: [0, 2, 4],
            shiftIds: [$this->morningShift->id, $this->dayShift->id],
        );
        $firstWorker = $this->createWorker(
            fullName: 'First Worker',
            israeliId: $this->validIsraeliId(11111111),
            role: $this->supervisorRole,
            isActive: false,
            hourlyCost: 72.00,
            minMonthlyHours: 100,
            maxMonthlyHours: 180,
            days: [5, 6],
            shiftIds: [$this->dayShift->id],
        );

        $csv = $this->captureWriteTo();
        $rows = $this->parseCsv($csv);

        self::assertCount(3, $rows);
        self::assertSame($this->csvService->headers(), $rows[0]);
        self::assertSame([
            'First Worker',
            $firstWorker->israeli_id,
            'Supervisor',
            'Inactive',
            '72.00',
            '100',
            '180',
            '',
            '5-6',
            '',
        ], $rows[1]);
        self::assertSame([
            'Second Worker',
            $secondWorker->israeli_id,
            'General Guard',
            'Active',
            '50.25',
            '80',
            '160',
            '0|2|4',
            '0|2|4',
            '',
        ], $rows[2]);
    }

    public function test_stream_download_returns_csv_response_with_dated_filename(): void
    {
        CarbonImmutable::setTestNow('2026-06-07 12:00:00');

        $exportId = (string) Str::uuid();
        $storedPath = "worker-exports/{$exportId}.csv";
        $this->csvService->processExport($exportId, $storedPath);

        $response = $this->csvService->streamQueuedExport($exportId);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertStringContainsString(
            'workers-2026-06-07.csv',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_stream_download_streams_the_same_csv_as_write_to(): void
    {
        $this->createWorker(
            fullName: 'Streamed Worker',
            israeliId: $this->validIsraeliId(31111111),
            role: $this->generalGuardRole,
            isActive: true,
            hourlyCost: 55.00,
            minMonthlyHours: 90,
            maxMonthlyHours: 170,
            days: [1, 3],
            shiftIds: [$this->morningShift->id],
        );

        self::assertSame($this->captureWriteTo(), $this->captureStreamDownload());
    }

    /**
     * @param list<list<string>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    private function captureWriteTo(): string
    {
        $exportId = (string) Str::uuid();
        $storedPath = "worker-exports/{$exportId}.csv";
        $this->csvService->processExport($exportId, $storedPath);

        return Storage::disk('local')->get($storedPath);
    }

    private function captureStreamDownload(): string
    {
        $exportId = (string) Str::uuid();
        $storedPath = "worker-exports/{$exportId}.csv";
        $this->csvService->processExport($exportId, $storedPath);

        $response = $this->csvService->streamQueuedExport($exportId);

        ob_start();
        $response->sendContent();
        $contents = ob_get_clean();

        return $contents;
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param list<int> $days
     * @param list<int> $shiftIds
     */
    private function createWorker(
        string $fullName,
        string $israeliId,
        Role $role,
        bool $isActive,
        float $hourlyCost,
        int $minMonthlyHours,
        int $maxMonthlyHours,
        array $days,
        array $shiftIds,
    ): Worker {
        $worker = Worker::factory()->create([
            'full_name' => $fullName,
            'israeli_id' => $israeliId,
            'role_id' => $role->id,
            'is_active' => $isActive,
        ]);

        Contract::factory()
            ->for($worker)
            ->withAvailability($days, $shiftIds)
            ->create([
                'hourly_cost' => $hourlyCost,
                'min_monthly_hours' => $minMonthlyHours,
                'max_monthly_hours' => $maxMonthlyHours,
            ]);

        return $worker;
    }

    private function validIsraeliId(int $base): string
    {
        return str_pad((string) ($base % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }
}
