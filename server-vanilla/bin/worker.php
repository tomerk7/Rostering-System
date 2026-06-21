<?php

declare(strict_types=1);

/**
 * Vanilla worker daemon — queue worker for the
 * vanilla pool. Polls `worker_csv_jobs`, `roster_generation_jobs`, and
 * `roster_export_jobs`, claims the oldest queued job, and runs the CSV
 * import/export, roster generation, or roster export. Runs as its own container
 * (server-vanilla-worker).
 *
 * Self-exits after WORKER_MAX_TIME so the container restarts it periodically
 * (fresh connection/memory), mirroring `queue:work --max-time`.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\RosterExportJobRepository;
use App\Repositories\RosterGenerationJobRepository;
use App\Repositories\WorkerCsvJobRepository;
use App\Services\Rostering\Csv\RosterCsvService;
use App\Services\RosterService;
use App\Services\Workers\Csv\WorkerCsvService;

$jobs = new WorkerCsvJobRepository;
$service = new WorkerCsvService;

$rosterJobs = new RosterGenerationJobRepository;
$rosterService = new RosterService;

$exportJobs = new RosterExportJobRepository;
$rosterCsv = new RosterCsvService;

$maxTime = (int) (getenv('WORKER_MAX_TIME') ?: 3600);
$start = time();
$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

fwrite(STDOUT, "[worker] started\n");

while ($running && (time() - $start) < $maxTime) {
    $job = $jobs->claimNext();

    if ($job !== null) {
        $id = (string) $job['id'];
        fwrite(STDOUT, "[worker] processing {$job['type']} {$id}\n");

        try {
            if ($job['type'] === 'import') {
                $service->processImport($id, (string) $job['payload']);
            } else {
                $service->processExport($id);
            }
            fwrite(STDOUT, "[worker] completed {$id}\n");
        } catch (Throwable $e) {
            $jobs->markFailed($id, $e->getMessage());
            fwrite(STDERR, "[worker] failed {$id}: {$e->getMessage()}\n");
        }

        continue;
    }

    $rosterJob = $rosterJobs->claimNext();

    if ($rosterJob !== null) {
        $id = $rosterJob['id'];
        $rosterId = $rosterJob['roster_id'];
        fwrite(STDOUT, "[worker] processing roster-generation {$id} (roster {$rosterId})\n");

        try {
            $rosterService->processGeneration($rosterId, $rosterJob['optimize_cost'], $rosterJob['distribution_preference']);
            $rosterJobs->markCompleted($id);
            fwrite(STDOUT, "[worker] completed {$id}\n");
        } catch (Throwable $e) {
            $rosterService->markGenerationFailed($rosterId);
            $rosterJobs->markFailed($id, $e->getMessage());
            fwrite(STDERR, "[worker] failed {$id}: {$e->getMessage()}\n");
        }

        continue;
    }

    $exportJob = $exportJobs->claimNext();

    if ($exportJob !== null) {
        $id = $exportJob['id'];
        $rosterId = $exportJob['roster_id'];
        fwrite(STDOUT, "[worker] processing roster-export {$id} (roster {$rosterId})\n");

        try {
            $rosterCsv->processExport($id, $rosterId);
            fwrite(STDOUT, "[worker] completed {$id}\n");
        } catch (Throwable $e) {
            $rosterCsv->markExportFailed($id, $e->getMessage());
            fwrite(STDERR, "[worker] failed {$id}: {$e->getMessage()}\n");
        }

        continue;
    }

    sleep(1);
}

fwrite(STDOUT, "[worker] exiting\n");
