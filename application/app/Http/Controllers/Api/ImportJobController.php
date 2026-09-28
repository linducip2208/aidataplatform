<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportJobController extends Controller
{
    public function show(Request $request, AiEngineClient $engine, int $importJobId): JsonResponse
    {
        if ($importJobId < 1) {
            return ApiResponse::error('Invalid import job id.', 422, 'validation_failed');
        }

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
            'report' => $job['report'] ?? null,
            'error' => null,
        ]);
    }
}
