<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
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

    // ------------------------------------------------------------------
    // enterprise: experiments, rollback, events, batch prediction
    // ------------------------------------------------------------------

    /**
     * List tracked experiments. Read-only proxy of
     * `GET /api/v1/training/experiments`.
     */
    public function experiments(Request $request): JsonResponse
    {
        $request->validate([
            'model_type' => ['nullable', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
        ]);

        $experiments = $this->engineCall('GET', '/training/experiments', array_filter([
            'model_type' => $request->query('model_type'),
        ]));

        return ApiResponse::data($experiments);
    }

    /**
     * Create (and by default run) an experiment. The engine fits synchronously
     * and returns the split plan plus per-split metrics, so this answers 202
     * like `train`: accepted for training, result inline.
     */
    public function createExperiment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['nullable', 'string', 'max:128'],
            'dataset' => ['nullable', 'array'],
            'dataset_ref' => ['nullable', 'string', 'max:512'],
            'dataset_version' => ['nullable', 'string', 'max:64'],
            'feature_list' => ['nullable', 'array'],
            'feature_list.*' => ['string'],
            'params' => ['nullable', 'array'],
            'params_json' => ['nullable', 'string', 'max:4000'],
            'train_ratio' => ['nullable', 'numeric', 'min:0.01'],
            'val_ratio' => ['nullable', 'numeric', 'min:0.01'],
            'test_ratio' => ['nullable', 'numeric', 'min:0.01'],
            'seed' => ['nullable', 'integer'],
        ]);

        $result = $this->engineCall('POST', '/training/experiments', array_filter([
            'model_type' => $validated['model_type'],
            'name' => $validated['name'] ?? 'experiment',
            'dataset' => $validated['dataset'] ?? null,
            'dataset_ref' => $validated['dataset_ref'] ?? '',
            'dataset_version' => $validated['dataset_version'] ?? '',
            'feature_list' => $validated['feature_list'] ?? [],
            'params' => $validated['params'] ?? $this->params($validated['params_json'] ?? null),
            'train_ratio' => $validated['train_ratio'] ?? 0.7,
            'val_ratio' => $validated['val_ratio'] ?? 0.15,
            'test_ratio' => $validated['test_ratio'] ?? 0.15,
            'seed' => $validated['seed'] ?? 42,
        ], static fn ($value): bool => $value !== null));

        AuditLog::record('model.experiment_created', 'experiment', (int) ($result['id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'name' => (string) ($result['name'] ?? ''),
            'status' => (string) ($result['status'] ?? ''),
        ]);

        return ApiResponse::data($result, 202);
    }

    /**
     * Rank experiments by one per-split metric, best first.
     */
    public function compareExperiment(Request $request, string $experimentId): JsonResponse
    {
        if (! $this->isExperimentId($experimentId)) {
            return $this->invalidExperimentId();
        }

        $validated = $request->validate([
            'experiment_ids' => ['nullable', 'array'],
            'experiment_ids.*' => ['integer', 'min:1'],
            'metric' => ['nullable', 'string', 'max:64'],
            'split' => ['nullable', 'string', 'in:train,validate,test'],
            'higher_is_better' => ['nullable', 'boolean'],
        ]);

        $result = $this->engineCall(
            'POST',
            '/training/experiments/'.((int) $experimentId).'/compare',
            array_filter([
                'experiment_ids' => $validated['experiment_ids'] ?? [(int) $experimentId],
                'metric' => $validated['metric'] ?? null,
                'split' => $validated['split'] ?? null,
                'higher_is_better' => $validated['higher_is_better'] ?? null,
            ], static fn ($value): bool => $value !== null),
        );

        return ApiResponse::data($result);
    }

    /**
     * Promote an experiment's linked version to production. Admin only
     * (wired by master in `routes/api.php` alongside `promote`).
     */
    public function promoteExperiment(Request $request, string $experimentId): JsonResponse
    {
        if (! $this->isExperimentId($experimentId)) {
            return $this->invalidExperimentId();
        }

        $validated = $request->validate([
            'version_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->engineCall(
            'POST',
            '/training/experiments/'.((int) $experimentId).'/promote',
            array_filter([
                'version_id' => $validated['version_id'] ?? null,
            ], static fn ($value): bool => $value !== null),
        );

        AuditLog::record('model.experiment_promoted', 'experiment', (int) $experimentId, [
            'version_id' => $result['version_id'] ?? null,
            'status' => (string) ($result['status'] ?? ''),
        ]);

        return ApiResponse::data($result);
    }

    /**
     * Roll a model back to its most recent archived predecessor. Admin only.
     */
    public function rollback(Request $request, string $modelId): JsonResponse
    {
        if (! $this->isModelId($modelId)) {
            return $this->invalidModelId();
        }

        $validated = $request->validate([
            'actor' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $result = $this->engineCall('POST', '/models/'.((int) $modelId).'/rollback', [
                'actor' => $validated['actor'] ?? '',
                'note' => $validated['note'] ?? '',
            ]);
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Model not found.', 404, 'not_found');
            }

            throw $exception;
        }

        AuditLog::record('model.rolled_back', 'model', (int) $modelId, [
            'rolled_back_from' => $result['rolled_back_from'] ?? null,
            'rolled_back_to' => $result['rolled_back_to'] ?? null,
        ]);

        return ApiResponse::data($result);
    }

    /**
     * Audit trail for a model: lifecycle, deployment and rollback entries.
     */
    public function events(string $modelId): JsonResponse
    {
        if (! $this->isModelId($modelId)) {
            return $this->invalidModelId();
        }

        try {
            $trail = $this->engineCall('GET', '/models/'.((int) $modelId).'/events');
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Model not found.', 404, 'not_found');
            }

            throw $exception;
        }

        return ApiResponse::data($trail);
    }

    /**
     * Complete metadata for a model: training timestamp, dataset version,
     * features, metrics, params, artifact path, lifecycle and deployment
     * status per version.
     */
    public function detail(string $modelId): JsonResponse
    {
        if (! $this->isModelId($modelId)) {
            return $this->invalidModelId();
        }

        try {
            $model = $this->engineCall('GET', '/models/'.((int) $modelId).'/detail');
        } catch (AiEngineException $exception) {
            if ($exception->upstreamStatus() === 404) {
                return ApiResponse::error('Model not found.', 404, 'not_found');
            }

            throw $exception;
        }

        return ApiResponse::data($model);
    }

    /**
     * Score a dataset in chunks with the serving version. Answers 202 with
     * the persisted run id, summary and artifact path.
     */
    public function batchPredict(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'dataset' => ['nullable', 'array'],
            'csv_text' => ['nullable', 'string', 'max:2000000'],
            'model_name' => ['nullable', 'string', 'max:128'],
            'model_id' => ['nullable', 'integer', 'min:1'],
            'version_id' => ['nullable', 'integer', 'min:1'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'params' => ['nullable', 'array'],
            'params_json' => ['nullable', 'string', 'max:4000'],
        ]);

        if (($validated['dataset'] ?? null) === null && ($validated['csv_text'] ?? null) === null) {
            throw ValidationException::withMessages([
                'dataset' => 'Either dataset or csv_text is required.',
            ]);
        }

        $result = $this->engineCall('POST', '/training/batch-predict', array_filter([
            'model_type' => $validated['model_type'],
            'dataset' => $validated['dataset'] ?? null,
            'csv_text' => $validated['csv_text'] ?? null,
            'model_name' => $validated['model_name'] ?? null,
            'model_id' => $validated['model_id'] ?? null,
            'version_id' => $validated['version_id'] ?? null,
            'chunk_size' => $validated['chunk_size'] ?? 500,
            'params' => $validated['params'] ?? $this->params($validated['params_json'] ?? null),
        ], static fn ($value): bool => $value !== null));

        AuditLog::record('model.batch_predicted', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'run_id' => $result['run_id'] ?? null,
            'n_rows' => $result['n_rows'] ?? null,
        ]);

        return ApiResponse::data($result, 202);
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

    private function isExperimentId(string $experimentId): bool
    {
        return ctype_digit($experimentId) && (int) $experimentId > 0;
    }

    private function invalidExperimentId(): JsonResponse
    {
        return ApiResponse::error('Experiment id must be a positive integer.', 422, 'validation_failed', [
            'experimentId' => ['Experiment id must be a positive integer.'],
        ]);
    }

    /**
     * Minimal server-to-server call for the enterprise endpoints that have no
     * `AiEngineClient` method yet (experiments, rollback, events, batch).
     * Same envelope contract as the client: `{success, data}` unwrapped,
     * `{success: false}` mapped to 422, HTTP failures mapped to their status.
     * Master moves these into `AiEngineClient` at integration; the behaviour
     * (headers, timeouts, exception type) already matches it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function engineCall(string $method, string $path, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('ai_engine.base_url'), '/');
        $serviceKey = (string) config('ai_engine.service_key');
        $header = (string) config('ai_engine.service_key_header', 'X-Service-Key');

        if ($baseUrl === '' || $serviceKey === '') {
            throw new AiEngineException(
                'AI engine is not configured: set AI_ENGINE_URL and SERVICE_API_KEY.',
                503,
                'ml.enterprise',
            );
        }

        $url = $baseUrl.'/api/v1/'.ltrim($path, '/');

        try {
            $request = Http::acceptJson()
                ->withHeaders([$header => $serviceKey, 'X-Client' => 'laravel-orchestrator'])
                ->timeout((int) config('ai_engine.llm_timeout', 120));

            $response = strtoupper($method) === 'GET'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new AiEngineException(
                'AI engine unreachable at '.$baseUrl.'. Is the fastapi service running?',
                503,
                'ml.enterprise',
            );
        }

        if ($response->failed()) {
            throw new AiEngineException(
                $this->engineErrorMessage($response->json()),
                $response->status(),
                'ml.enterprise',
                $response->json(),
            );
        }

        $body = $response->json();

        if (is_array($body) && array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException(
                    $this->engineErrorMessage($body),
                    422,
                    'ml.enterprise',
                    $body,
                );
            }

            return (array) ($body['data'] ?? []);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function engineErrorMessage(mixed $body): string
    {
        if (is_array($body)) {
            $message = data_get($body, 'error.message')
                ?? data_get($body, 'detail')
                ?? data_get($body, 'message');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'AI engine request failed.';
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
