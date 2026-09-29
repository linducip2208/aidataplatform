<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class MlController extends Controller
{
    private const MODEL_TYPES = ['forecast', 'churn', 'segmentation', 'anomaly', 'recommend'];

    private const VERSION_STATUSES = ['PRODUCTION', 'STAGED', 'ARCHIVED'];

    public function index(AiEngineClient $engine): JsonResponse
    {
        return ApiResponse::data($engine->models());
    }

    public function show(Request $request, AiEngineClient $engine, string $modelId): JsonResponse
    {
        if (! $this->isModelId($modelId)) {
            return $this->invalidModelId();
        }

        try {
            $model = $engine->model((int) $modelId);
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Model not found.', 404, 'not_found');
            }

            throw $exception;
        }

        // The engine decorates every version row with the artifact path it was
        // loaded from. That is the server's own filesystem layout, and it is not
        // in the documented response ("model + `versions[]`"), so it is dropped
        // rather than proxied.
        $model['versions'] = array_map(
            static fn (array $version): array => Arr::except($version, ['artifact_path']),
            (array) ($model['versions'] ?? []),
        );

        return ApiResponse::data($model);
    }

    public function train(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['required', 'string', 'max:100'],
            // The documented contract is an object, and that is what the engine's
            // `TrainRequest.params: Dict[str, Any]` expects. The web form posts a
            // JSON string because a textarea can only produce that, so both are
            // accepted here; a string is decoded below.
            'params' => ['nullable', 'array'],
            'params_json' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'model_type' => 'model type',
            'name' => 'model name',
            'params' => 'params',
            'params_json' => 'params',
        ]);

        $params = $validated['params'] ?? $this->params($validated['params_json'] ?? null);

        $result = $engine->train($validated['model_type'], $validated['name'], $params);

        AuditLog::record('model.trained', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'name' => $validated['name'],
            'version' => (string) ($result['version'] ?? ''),
        ]);

        return ApiResponse::data($result, 202);
    }

    public function promote(Request $request, AiEngineClient $engine, string $modelId): JsonResponse
    {
        if (! $this->isModelId($modelId)) {
            return $this->invalidModelId();
        }

        $modelId = (int) $modelId;

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

    /**
     * The `{modelId}` segment arrives as a string; a non-numeric one used to be
     * coerced into the `int` parameter and raised a `TypeError` — an unhandled
     * 500 for what is plainly bad input. It is answered as the 422 the
     * documented error contract calls for instead.
     */
    private function isModelId(string $modelId): bool
    {
        return ctype_digit($modelId) && (int) $modelId > 0;
    }

    private function invalidModelId(): JsonResponse
    {
        return ApiResponse::error('Model id must be a positive integer.', 422, 'validation_failed', [
            'modelId' => ['Model id must be a positive integer.'],
        ]);
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
