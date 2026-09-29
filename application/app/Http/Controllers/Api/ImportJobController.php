<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ImportJobController extends Controller
{
    public function show(Request $request, AiEngineClient $engine, string $importJobId): JsonResponse
    {
        // A non-numeric segment used to be coerced into the `int` parameter and
        // raised a `TypeError` — a 500 for what is bad input. The doc's error
        // contract answers that with a 422 keyed by the field.
        if (! ctype_digit($importJobId) || (int) $importJobId < 1) {
            return ApiResponse::error('Invalid import job id.', 422, 'validation_failed', [
                'importJobId' => ['Import job id must be a positive integer.'],
            ]);
        }

        $importJobId = (int) $importJobId;

        try {
            $job = $engine->importJob($importJobId);
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Import job not found.', 404, 'not_found');
            }

            throw $exception;
        }

        return ApiResponse::data([
            'job_id' => $importJobId,
            'type' => 'import',
            'status' => (string) ($job['status'] ?? 'unknown'),
            'progress' => (float) ($job['progress'] ?? 0),
            'total_rows' => (int) ($job['total_rows'] ?? 0),
            'processed_rows' => (int) ($job['processed_rows'] ?? 0),
            'error_rows' => (int) ($job['error_rows'] ?? 0),
            // The engine stores the upload validator's report on the job row
            // until an ETL run or a quality profile overwrites it, so on a
            // freshly uploaded job `report` IS that blob — sha256 of the
            // uploaded file, byte count, and the engine's own stored filename
            // included. It is passed through here filtered for the same reason
            // the dataset presenter drops `metadata.validation`.
            'report' => $this->report($job['report'] ?? null),
            'error' => null,
        ]);
    }

    /**
     * The job's progress report, minus the file-identifying `meta` block the
     * upload validator leaves behind. `score`/`breakdown`/`issues` — what a
     * client actually polls for — survive untouched.
     */
    private function report(mixed $report): mixed
    {
        return is_array($report) ? Arr::except($report, ['meta']) : $report;
    }
}
