<?php

namespace App\Services;

use App\Exceptions\AiEngineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-to-server client for the FastAPI AI engine.
 *
 * The engine answers with an envelope: `{"success": bool, "data": ...}` on
 * success and `{"success": false, "error": {"message": "..."}}` on failure.
 * This class unwraps that envelope so callers work with plain arrays and get a
 * single exception type for every failure mode (connection, HTTP status,
 * envelope failure). The browser never talks to the engine directly.
 */
class AiEngineClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serviceKey,
        private readonly string $serviceKeyHeader,
        private readonly int $timeout,
        private readonly int $llmTimeout,
        private readonly int $uploadTimeout,
        private readonly int $connectTimeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('ai_engine.base_url'), '/'),
            serviceKey: (string) config('ai_engine.service_key'),
            serviceKeyHeader: (string) config('ai_engine.service_key_header'),
            timeout: (int) config('ai_engine.timeout'),
            llmTimeout: (int) config('ai_engine.llm_timeout'),
            uploadTimeout: (int) config('ai_engine.upload_timeout'),
            connectTimeout: (int) config('ai_engine.connect_timeout'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->serviceKey !== '';
    }

    // ------------------------------------------------------------------
    // health / meta
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function health(): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->url('/health')), 'health', $this->timeout);

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    public function readiness(): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->url('/readiness')), 'readiness', $this->timeout);

        return $this->decode($response);
    }

    // ------------------------------------------------------------------
    // ingestion
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     *
     * @throws AiEngineException
     */
    public function uploadFile(UploadedFile $file, string $datasetType = 'sales'): array
    {
        $response = $this->send(function (PendingRequest $http) use ($file, $datasetType) {
            return $http->post($this->url('/imports/upload'), [
                'file' => $file,
                'dataset_type' => $datasetType,
            ], ['multipart' => true]);
        }, 'imports.upload', $this->uploadTimeout);

        return $this->unwrap($response, 'imports.upload');
    }

    /** @return array<string, mixed> */
    public function preview(int $importJobId): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url("/imports/preview/{$importJobId}")),
            'imports.preview',
        );

        return $this->unwrap($response, 'imports.preview');
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, array<string, mixed>>
     */
    public function suggestMapping(array $columns, string $datasetType = 'sales'): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('/imports/mapping/suggest'), [
            'columns' => array_values($columns),
            'dataset_type' => $datasetType,
        ]), 'imports.mapping.suggest');

        return (array) $this->unwrap($response, 'imports.mapping.suggest');
    }

    /**
     * @param  array<string, string>  $mappings
     * @return array<string, mixed>
     */
    public function applyMapping(int $importJobId, array $mappings, string $datasetType = 'sales', ?string $saveAsTemplate = null): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('/imports/mapping'), [
            'import_job_id' => $importJobId,
            'dataset_type' => $datasetType,
            'mappings' => $mappings,
            'save_as_template' => $saveAsTemplate,
        ]), 'imports.mapping');

        return $this->unwrap($response, 'imports.mapping');
    }

    /**
     * @return array<string, mixed>
     */
    public function runQuality(int $importJobId): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url("/imports/quality/{$importJobId}")),
            'imports.quality',
        );

        return $this->unwrap($response, 'imports.quality');
    }

    /**
     * @param  array<string, string>  $mappings
     * @return array<string, mixed>
     */
    public function commitImport(int $importJobId, array $mappings = [], string $datasetType = 'sales', bool $runAsync = true): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('/imports/commit'), [
            'import_job_id' => $importJobId,
            'dataset_type' => $datasetType,
            'mappings' => $mappings,
            'run_async' => $runAsync,
        ]), 'imports.commit');

        return $this->unwrap($response, 'imports.commit');
    }

    /** @return array<string, mixed> */
    public function importJob(int $importJobId): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url("/imports/jobs/{$importJobId}")),
            'imports.jobs',
        );

        return $this->unwrap($response, 'imports.jobs');
    }

    // ------------------------------------------------------------------
    // analytics
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function kpi(array $filter = []): array
    {
        return $this->post('/analytics/kpi', $filter, 'analytics.kpi');
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function trend(array $filter = []): array
    {
        return $this->post('/analytics/trend', $filter, 'analytics.trend');
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function rfm(array $filter = []): array
    {
        return $this->post('/analytics/rfm', $filter, 'analytics.rfm');
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function abc(array $filter = []): array
    {
        return $this->post('/analytics/abc', $filter, 'analytics.abc');
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function cohort(array $filter = []): array
    {
        return $this->post('/analytics/cohort', $filter, 'analytics.cohort');
    }

    /** @return array<string, mixed> */
    public function branches(): array
    {
        return $this->get('/analytics/branches', [], 'analytics.branches');
    }

    /** @return array<string, mixed> */
    public function finance(): array
    {
        return $this->get('/analytics/finance', [], 'analytics.finance');
    }

    // ------------------------------------------------------------------
    // ml
    // ------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    public function forecast(array $history, int $horizon = 30, string $granularity = 'daily'): array
    {
        return $this->post('/forecast', [
            'history' => array_values($history),
            'horizon' => $horizon,
            'granularity' => $granularity,
        ], 'forecast');
    }

    /**
     * @param  array<int, array<string, mixed>>  $customers
     * @return array<string, mixed>
     */
    public function churn(array $customers): array
    {
        return $this->post('/customers/churn', ['customers' => array_values($customers)], 'customers.churn');
    }

    /**
     * @param  array<int, array<string, mixed>>  $customers
     * @return array<string, mixed>
     */
    public function segment(array $customers, int $nClusters = 4): array
    {
        return $this->post('/customers/segment', [
            'customers' => array_values($customers),
            'n_clusters' => $nClusters,
        ], 'customers.segment');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function inventoryHealth(array $payload = []): array
    {
        return $this->post('/inventory/health', $payload, 'inventory.health');
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    public function detectAnomalies(array $series, float $sensitivity = 2.5): array
    {
        return $this->post('/anomaly/detect', [
            'series' => array_values($series),
            'sensitivity' => $sensitivity,
        ], 'anomaly.detect');
    }

    /** @return array<string, mixed> */
    public function recommend(?string $customerId = null, ?string $productId = null, int $topK = 5): array
    {
        return $this->post('/recommend', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'top_k' => $topK,
        ], 'recommend');
    }

    /** @return array<int, array<string, mixed>> */
    public function models(): array
    {
        return (array) $this->get('/models', [], 'models.list');
    }

    /** @return array<string, mixed> */
    public function model(int $modelId): array
    {
        return $this->get("/models/{$modelId}", [], 'models.show');
    }

    /** @return array<string, mixed> */
    public function promoteModel(int $modelId, int $versionId, string $toStatus = 'PRODUCTION'): array
    {
        return $this->post("/models/{$modelId}/promote", [
            'version_id' => $versionId,
            'to_status' => $toStatus,
        ], 'models.promote');
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, array<string, mixed>>|null  $dataset
     * @return array<string, mixed>
     */
    public function train(string $modelType, string $name, array $params = [], ?array $dataset = null): array
    {
        return $this->post('/training/train', [
            'model_type' => $modelType,
            'name' => $name,
            'params' => $params,
            'dataset' => $dataset,
        ], 'training.train', $this->llmTimeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function predict(string $modelType, ?string $modelName = null, array $payload = []): array
    {
        return $this->post('/training/predict', [
            'model_type' => $modelType,
            'model_name' => $modelName,
            'payload' => $payload,
        ], 'training.predict');
    }

    // ------------------------------------------------------------------
    // ai / rag
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function chat(string $message, ?int $conversationId = null, array $context = []): array
    {
        return $this->post('/ai/chat', [
            'message' => $message,
            'conversation_id' => $conversationId,
            'context' => $context,
        ], 'ai.chat', $this->llmTimeout);
    }

    /** @return array<string, mixed> */
    public function report(string $period = 'weekly', ?string $branch = null, string $format = 'json'): array
    {
        return $this->post('/ai/report', [
            'period' => $period,
            'branch' => $branch,
            'format' => $format,
        ], 'ai.report', $this->llmTimeout);
    }

    /** @return array<string, mixed> */
    public function ragIngest(string $title, string $content, string $source = 'api', string $docType = 'txt'): array
    {
        return $this->post('/rag/ingest', [
            'title' => $title,
            'content' => $content,
            'source' => $source,
            'doc_type' => $docType,
        ], 'rag.ingest', $this->llmTimeout);
    }

    /** @return array<string, mixed> */
    public function ragQuery(string $query, int $topK = 5): array
    {
        return $this->post('/rag/query', [
            'query' => $query,
            'top_k' => $topK,
        ], 'rag.query', $this->llmTimeout);
    }

    // ------------------------------------------------------------------
    // plumbing
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $operation, ?int $timeout = null): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->post($this->url($path), $payload),
            $operation,
            $timeout,
        );

        return $this->unwrap($response, $operation);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $operation, ?int $timeout = null): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url($path), $query),
            $operation,
            $timeout,
        );

        return $this->unwrap($response, $operation);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $operation, ?int $timeout = null): Response
    {
        if (! $this->isConfigured()) {
            throw new AiEngineException(
                'AI engine is not configured: set AI_ENGINE_URL and SERVICE_API_KEY.',
                503,
                $operation,
            );
        }

        try {
            return $call($this->http($timeout));
        } catch (ConnectionException $exception) {
            Log::error('ai_engine.unreachable', [
                'operation' => $operation,
                'base_url' => $this->baseUrl,
                'error' => $exception->getMessage(),
            ]);

            throw new AiEngineException(
                'AI engine unreachable at '.$this->baseUrl.'. Is the fastapi service running?',
                503,
                $operation,
            );
        } catch (AiEngineException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('ai_engine.failed', ['operation' => $operation, 'error' => $exception->getMessage()]);

            throw new AiEngineException($exception->getMessage(), 502, $operation);
        }
    }

    private function http(?int $timeout = null): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withHeaders([
                $this->serviceKeyHeader => $this->serviceKey,
                'X-Client' => 'laravel-orchestrator',
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout($timeout ?? $this->timeout)
            ->retry(2, 250, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response, string $operation): array
    {
        if ($response->failed()) {
            $message = $this->errorMessage($response);
            Log::warning('ai_engine.http_error', [
                'operation' => $operation,
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new AiEngineException($message, $response->status(), $operation, $response->json());
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                $message = $this->errorMessage($response);

                throw new AiEngineException($message, 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
    }

    /**
     * `/health` and `/readiness` answer with a bare pydantic model rather than
     * the `{success, data}` envelope, so they must not go through `unwrap()`.
     * They still have to respect the HTTP status: a 500 from the engine has to
     * surface as a failure, not as an empty health array.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new AiEngineException($this->errorMessage($response), $response->status(), 'health');
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        $message = is_array($body)
            ? (data_get($body, 'error.message')
                ?? data_get($body, 'detail')
                ?? data_get($body, 'message'))
            : null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $status = $response->status();

        return match ($status) {
            401, 403 => 'AI engine rejected the service key. Check SERVICE_API_KEY matches on both services.',
            404 => 'AI engine endpoint not found. Check AI_ENGINE_URL and the engine version.',
            422 => 'AI engine rejected the request payload.',
            429 => 'AI engine rate limit reached. Retry shortly.',
            $status >= 500 => 'AI engine returned a server error ('.$status.').',
            default => 'AI engine request failed with status '.$status.'.',
        };
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/api/v1'.'/'.ltrim($path, '/');
    }
}
