<?php

namespace App\Jobs;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Services\AiEngineClient;
use App\Services\DatasetIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one dataset import on the `imports` queue.
 *
 * The worker process drives the engine synchronously (commit with
 * `$runAsync = false`, then mirror the terminal state) so the Celery
 * `imports` queue is not involved: Laravel owns the retry/backoff policy
 * here via `$tries`/`$backoff`. Only the public surface of
 * {@see DatasetIngestionService} (`commit()` + `syncStatus()`) and
 * {@see AiEngineClient} (`commitImport()` + `importJob()`) is used — no
 * existing service was modified for this job.
 */
class RunImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 1800;

    public function __construct(public readonly string $datasetUuid)
    {
        $this->onQueue('imports');
    }

    public function handle(DatasetIngestionService $ingestion, AiEngineClient $engine): void
    {
        $dataset = Dataset::query()->where('uuid', $this->datasetUuid)->first();

        if ($dataset === null) {
            Log::warning('import.dataset_missing', ['dataset_uuid' => $this->datasetUuid]);

            return;
        }

        if ($dataset->status()->isTerminal()) {
            Log::info('import.skipped_terminal', [
                'dataset_uuid' => $dataset->uuid,
                'status' => $dataset->status()->value,
            ]);

            return;
        }

        if (! $dataset->import_job_id) {
            Log::warning('import.no_job', ['dataset_uuid' => $dataset->uuid]);

            $dataset->forceFill(['status' => DatasetStatus::Failed])->save();

            return;
        }

        Log::info('import.started', [
            'dataset_uuid' => $dataset->uuid,
            'import_job_id' => $dataset->import_job_id,
        ]);

        // Synchronous engine commit: the HTTP call returns the ETL report
        // (rows, quality, metrics) rather than a queued token.
        $result = $ingestion->commit($dataset, false);

        // Mirror the terminal engine state locally (done -> committed,
        // failed/error/cancelled -> failed); anything else stays importing.
        $job = $ingestion->syncStatus($dataset);

        Log::info('import.finished', [
            'dataset_uuid' => $dataset->uuid,
            'import_job_id' => $dataset->import_job_id,
            'status' => $dataset->status()->value,
            'engine_status' => strtolower((string) ($job['status'] ?? '')),
            'processed_rows' => (int) ($result['processed_rows'] ?? 0),
        ]);
    }

    /**
     * Last resort: the exception escaped all retries. The dataset must not
     * stay `importing` forever — mirror it as failed so the UI and the
     * nightly sweep treat it as terminal and an operator can re-queue it.
     */
    public function failed(Throwable $exception): void
    {
        $dataset = Dataset::query()->where('uuid', $this->datasetUuid)->first();

        if ($dataset === null) {
            return;
        }

        if (! $dataset->status()->isTerminal()) {
            $dataset->forceFill(['status' => DatasetStatus::Failed])->save();
        }

        Log::error('import.failed', [
            'dataset_uuid' => $this->datasetUuid,
            'error' => $exception->getMessage(),
        ]);
    }
}
