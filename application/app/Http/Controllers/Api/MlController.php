<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MlController extends Controller
{
    private const MODEL_TYPES = ['forecast', 'churn', 'segmentation', 'anomaly', 'recommend'];

    private const VERSION_STATUSES = ['PRODUCTION', 'STAGED', 'ARCHIVED'];

    public function index(AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->models());
    }

    public function show(Request $request, AiEngineClient $engine, int $modelId): JsonResponse
    {
        try {
            return ApiResponse::data($engine->model($modelId));
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Model not found.', 404, 'not_found');
            }

            throw $exception;
        }
    }

    public function train(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'params' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'model_type' => 'model type',
            'name' => 'model name',
            'params' => 'params',
        ]);

        $result = $engine->train($validated['model_type'], $validated['name'], $this->params($validated['params'] ?? null));

        AuditLog::record('model.trained', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'name' => $validated['name'],
            'version' => (string) ($result['version'] ?? ''),
        ]);

        return ApiResponse::data($result, 202);
    }

    public function promote(Request $request, AiEngineClient $engine, int $modelId): JsonResponse
    {
        $validated = $request->validate([
            'version_id' => ['required', 'integer', 'min:1'],
            'to_status' => ['nullable', 'string', 'in:'.implode(',', self::VERSION_STATUSES)],
        ], [], [
            'version_id' => 'version id',
            'to_status' => 'to status',
        ]);

        $versionId = (int) $validated['version_id'];
        $toStatus = $validated['to_status'] ?? 'PRODUCTION';

        $result = $engine->promoteModel($modelId, $versionId, $toStatus);

        AuditLog::record('model.promoted', 'model', $modelId, [
            'version_id' => $versionId,
            'to_status' => $toStatus,
        ]);

        return ApiResponse::data($result);
    }

    /** @return array<string, mixed> */
    private function params(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'params' => 'Params must be a valid JSON object.',
            ]);
        }

        return $decoded;
    }
}
