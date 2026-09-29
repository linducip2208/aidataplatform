<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MlController extends Controller
{
    private const MODEL_TYPES = ['forecast', 'churn', 'segmentation', 'anomaly', 'recommend'];

    private const VERSION_STATUSES = ['PRODUCTION', 'STAGED', 'ARCHIVED'];

    public function index(Request $request, AiEngineClient $engine): View
    {
        $models = [];
        $selected = null;
        $error = null;
        $experiments = [];
        $experimentsError = null;
        $events = [];
        $eventsError = null;

        try {
            $models = $engine->models();

            if ($request->filled('model')) {
                $selected = $engine->model((int) $request->query('model'));
            }
        } catch (AiEngineException $exception) {
            $error = $exception->getMessage();
        }

        try {
            $experiments = $this->engineCall('GET', '/training/experiments');
        } catch (AiEngineException $exception) {
            $experimentsError = $exception->getMessage();
        }

        if (is_array($selected) && isset($selected['id'])) {
            try {
                $trail = $this->engineCall('GET', '/models/'.((int) $selected['id']).'/events');
                $events = (array) ($trail['events'] ?? []);
            } catch (AiEngineException $exception) {
                $eventsError = $exception->getMessage();
            }
        }

        return view('ml.index', [
            'models' => $models,
            'selected' => $selected,
            'modelTypes' => self::MODEL_TYPES,
            'canApprove' => $request->user()->canApproveModels(),
            'error' => $error,
            'experiments' => $experiments,
            'experimentsError' => $experimentsError,
            'events' => $events,
            'eventsError' => $eventsError,
        ]);
    }

    public function train(Request $request, AiEngineClient $engine): RedirectResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'params' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'model_type' => 'tipe model',
            'name' => 'nama model',
            'params' => 'parameter',
        ]);

        $result = $engine->train($validated['model_type'], $validated['name'], $this->params($validated['params'] ?? null));

        AuditLog::record('model.trained', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'name' => $validated['name'],
            'version' => (string) ($result['version'] ?? ''),
        ]);

        return redirect()
            ->route('ml.index')
            ->with('status', "Model {$result['model_id']} versi {$result['version']} selesai dilatih.");
    }

    public function promote(Request $request, AiEngineClient $engine, int $modelId): RedirectResponse
    {
        $validated = $request->validate([
            'version_id' => ['required', 'integer', 'min:1'],
            'to_status' => ['nullable', 'string', 'in:'.implode(',', self::VERSION_STATUSES)],
        ], [], [
            'version_id' => 'versi',
            'to_status' => 'status tujuan',
        ]);

        $versionId = (int) $validated['version_id'];
        $toStatus = $validated['to_status'] ?? 'PRODUCTION';

        $engine->promoteModel($modelId, $versionId, $toStatus);

        AuditLog::record('model.promoted', 'model', $modelId, [
            'version_id' => $versionId,
            'to_status' => $toStatus,
        ]);

        return back()->with('status', "Model {$modelId} versi {$versionId} dipindahkan ke {$toStatus}.");
    }

    public function rollback(Request $request, int $modelId): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1024'],
        ], [], [
            'note' => 'catatan',
        ]);

        $result = $this->engineCall('POST', "/models/{$modelId}/rollback", [
            'note' => $validated['note'] ?? '',
        ]);

        AuditLog::record('model.rolled_back', 'model', $modelId, [
            'rolled_back_from' => $result['rolled_back_from'] ?? null,
            'rolled_back_to' => $result['rolled_back_to'] ?? null,
        ]);

        return back()->with(
            'status',
            "Model {$modelId} dikembalikan ke versi {$result['rolled_back_to']}."
        );
    }

    public function createExperiment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'name' => ['nullable', 'string', 'max:128'],
            'dataset_ref' => ['nullable', 'string', 'max:512'],
            'dataset_version' => ['nullable', 'string', 'max:64'],
            'params' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'model_type' => 'tipe model',
            'name' => 'nama eksperimen',
            'dataset_ref' => 'referensi dataset',
            'dataset_version' => 'versi dataset',
            'params' => 'parameter',
        ]);

        $result = $this->engineCall('POST', '/training/experiments', array_filter([
            'model_type' => $validated['model_type'],
            'name' => $validated['name'] ?? 'experiment',
            'dataset_ref' => $validated['dataset_ref'] ?? '',
            'dataset_version' => $validated['dataset_version'] ?? '',
            'params' => $this->params($validated['params'] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== ''));

        AuditLog::record('model.experiment_created', 'experiment', (int) ($result['id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'status' => (string) ($result['status'] ?? ''),
        ]);

        return redirect()
            ->route('ml.index')
            ->with('status', "Eksperimen {$result['id']} selesai dengan status {$result['status']}.");
    }

    public function promoteExperiment(Request $request, int $experimentId): RedirectResponse
    {
        $validated = $request->validate([
            'version_id' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'version_id' => 'versi',
        ]);

        $result = $this->engineCall(
            'POST',
            "/training/experiments/{$experimentId}/promote",
            array_filter([
                'version_id' => $validated['version_id'] ?? null,
            ], static fn ($value): bool => $value !== null),
        );

        AuditLog::record('model.experiment_promoted', 'experiment', $experimentId, [
            'version_id' => $result['version_id'] ?? null,
            'status' => (string) ($result['status'] ?? ''),
        ]);

        return back()->with('status', "Eksperimen {$experimentId} dipromosikan ke produksi.");
    }

    public function batchPredict(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'model_type' => ['required', 'string', 'in:'.implode(',', self::MODEL_TYPES)],
            'model_name' => ['nullable', 'string', 'max:128'],
            'dataset' => ['nullable', 'string', 'max:2000000'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], [], [
            'model_type' => 'tipe model',
            'model_name' => 'nama model',
            'dataset' => 'dataset',
            'chunk_size' => 'ukuran chunk',
        ]);

        $rows = $this->params($validated['dataset'] ?? null);
        $dataset = array_is_list($rows) ? $rows : null;

        if ($dataset === null) {
            throw ValidationException::withMessages([
                'dataset' => 'Dataset harus berupa array JSON dari baris data.',
            ]);
        }

        $result = $this->engineCall('POST', '/training/batch-predict', array_filter([
            'model_type' => $validated['model_type'],
            'model_name' => $validated['model_name'] ?? null,
            'dataset' => $dataset,
            'chunk_size' => $validated['chunk_size'] ?? 500,
        ], static fn ($value): bool => $value !== null));

        AuditLog::record('model.batch_predicted', 'model', (int) ($result['model_id'] ?? 0), [
            'model_type' => $validated['model_type'],
            'run_id' => $result['run_id'] ?? null,
            'n_rows' => $result['n_rows'] ?? null,
        ]);

        return back()->with(
            'status',
            "Batch prediksi selesai: {$result['n_rows']} baris dalam {$result['n_chunks']} chunk (run {$result['run_id']})."
        );
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
                'params' => 'Parameter harus berupa JSON yang valid.',
            ]);
        }

        return $decoded;
    }

    /**
     * Minimal server-to-server call for the enterprise endpoints that have no
     * `AiEngineClient` method yet (experiments, rollback, events, batch).
     * Mirrors the client's envelope contract; master moves it into
     * `AiEngineClient` at integration.
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
            $body = $response->json();

            throw new AiEngineException(
                is_array($body) ? (string) (data_get($body, 'error.message') ?? data_get($body, 'detail') ?? 'AI engine request failed.') : 'AI engine request failed.',
                $response->status(),
                'ml.enterprise',
                is_array($body) ? $body : null,
            );
        }

        $body = $response->json();

        if (is_array($body) && array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException(
                    (string) (data_get($body, 'error.message') ?? 'AI engine request failed.'),
                    422,
                    'ml.enterprise',
                    $body,
                );
            }

            return (array) ($body['data'] ?? []);
        }

        return is_array($body) ? $body : [];
    }
}
