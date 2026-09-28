<?php

namespace App\Jobs;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs the engine's quality check for a single dataset on a worker instead
 * of inside the scheduler process, so a slow engine cannot stall the rest of
 * the nightly reconciliation. `sync:quality --queue` dispatches one of these
 * per dataset.
 */
class RefreshQualityScoreJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(public readonly string $datasetUuid)
    {
        $this->onQueue('datasets');
    }

    public function handle(DatasetIngestionService $ingestion): void
    {
        $dataset = Dataset::query()->where('uuid', $this->datasetUuid)->first();

        if ($dataset === null) {
            Log::warning('quality.dataset_missing', ['dataset_uuid' => $this->datasetUuid]);

            return;
        }

        if ($dataset->status() !== DatasetStatus::Committed) {
            Log::info('quality.skipped', [
                'dataset_uuid' => $dataset->uuid,
                'status' => $dataset->status()->value,
            ]);

            return;
        }

        $this->refresh($ingestion, $dataset);
    }

    /**
     * `runQuality()` moves a passing dataset back to `uploaded` because it is
     * meant for the pre-commit step. Re-checking an already committed dataset
     * must not silently un-commit it, so the terminal status is restored here.
     *
     * @return array{score: float, verdict: string, status: string}
     */
    public function refresh(DatasetIngestionService $ingestion, Dataset $dataset): array
    {
        $result = $ingestion->runQuality($dataset);

        if ($dataset->status() === DatasetStatus::Uploaded) {
            $dataset->update(['status' => DatasetStatus::Committed]);
        }

        $outcome = [
            'score' => (float) $result['score'],
            'verdict' => (string) $result['verdict']->value,
            'status' => $dataset->status()->value,
        ];

        Log::info('quality.refreshed', ['dataset_uuid' => $dataset->uuid] + $outcome);

        return $outcome;
    }
}
