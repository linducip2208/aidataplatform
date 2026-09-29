<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\QualityRule;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Enterprise quality governance: local rule store + engine rule evaluation.
 *
 * AiEngineClient is master-owned, so this service talks to the engine through
 * its own tiny HTTP helper built from the read-only `config/ai_engine`
 * values (base URL, service key header). The envelope unwrapping and the
 * error mapping mirror AiEngineClient exactly so callers see one failure
 * shape no matter which service made the call.
 *
 * Read/write split: listRules/history/run are GET-only and never write;
 * saveRule and evaluateViaEngine are the only writers. Evaluation is never
 * triggered from a GET path.
 */
class QualityService
{
    public const RULE_TYPES = [
        'required',
        'nullable',
        'unique',
        'duplicate',
        'regex',
        'range',
        'enum',
        'datatype',
        'referential',
        'freshness',
        'completeness',
        'consistency',
        'validity',
        'schema_drift',
        'anomaly_ref',
    ];

    public const SEVERITIES = ['error', 'warn'];

    /** @return Collection<int, QualityRule> */
    public function listRules(array $filters = []): Collection
    {
        return QualityRule::query()
            ->ofType($filters['dataset_type'] ?? null)
            ->when(
                array_key_exists('active', $filters) && $filters['active'] !== null,
                fn ($query) => $query->where('active', (bool) $filters['active']),
            )
            ->when(
                isset($filters['rule_type']),
                fn ($query) => $query->where('rule_type', $filters['rule_type']),
            )
            ->orderBy('id')
            ->get();
    }

    public function saveRule(array $data): QualityRule
    {
        if (! in_array($data['rule_type'] ?? null, self::RULE_TYPES, true)) {
            throw ValidationException::withMessages([
                'rule_type' => ['Rule type harus salah satu dari: '.implode(', ', self::RULE_TYPES).'.'],
            ]);
        }

        if (! in_array($data['severity'] ?? 'error', self::SEVERITIES, true)) {
            throw ValidationException::withMessages([
                'severity' => ['Severity harus error atau warn.'],
            ]);
        }

        return QualityRule::updateOrCreate(
            ['name' => $data['name']],
            [
                'dataset_type' => $data['dataset_type'] ?? 'sales',
                'column' => $data['column'] ?? null,
                'rule_type' => $data['rule_type'],
                'params' => $data['params'] ?? [],
                'severity' => $data['severity'] ?? 'error',
                'active' => $data['active'] ?? true,
            ],
        );
    }

    /**
     * POST-only evaluation: runs the engine rule set and mirrors the score
     * onto the dataset row (terminal statuses preserved, as in runQuality).
     *
     * @param  array<string, mixed>  $payload  dataset_id?, job_id?, profile?, rules?, rows?
     * @return array<string, mixed>
     */
    public function evaluateViaEngine(array $payload): array
    {
        $dataset = isset($payload['dataset_id'])
            ? Dataset::where('uuid', $payload['dataset_id'])->first()
            : null;

        $body = array_filter([
            'dataset_ref' => $dataset?->uuid ?? $payload['dataset_ref'] ?? null,
            'job_id' => $payload['job_id'] ?? $dataset?->import_job_id,
            'profile' => $payload['profile'] ?? null,
            'rules' => $payload['rules'] ?? null,
            'rows' => $payload['rows'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $response = $this->send(
            fn (PendingRequest $http) => $http->post($this->url('/quality/evaluate'), $body),
            'quality.evaluate',
        );

        $result = $this->unwrap($response, 'quality.evaluate');

        if ($dataset) {
            $this->mirrorScore($dataset, $result);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function history(?string $datasetRef = null, int $limit = 50): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url('/quality/history'), array_filter([
                'dataset_ref' => $datasetRef,
                'limit' => $limit,
            ])),
            'quality.history',
        );

        return $this->unwrap($response, 'quality.history');
    }

    /** @return array<string, mixed> */
    public function run(int $runId): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url("/quality/runs/{$runId}")),
            'quality.runs.show',
        );

        return $this->unwrap($response, 'quality.runs.show');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function mirrorScore(Dataset $dataset, array $result): void
    {
        $score = (float) ($result['score'] ?? 0);
        $verdict = ($result['verdict'] ?? 'fail') === 'fail'
            ? QualityVerdict::Quarantine
            : QualityVerdict::Pass;

        $dataset->forceFill([
            'quality_score' => $score,
            'quality_verdict' => $verdict->value,
            'quality_checked_at' => now(),
            'metadata' => array_merge((array) $dataset->metadata, ['quality_enterprise' => $result]),
            // Same guard as runQuality(): a re-check never walks a committed
            // dataset anywhere; the verdict is recorded either way.
            'status' => match (true) {
                $dataset->status()->isTerminal() => $dataset->status(),
                $verdict === QualityVerdict::Quarantine => DatasetStatus::Quarantined,
                default => $dataset->status(),
            },
        ])->save();

        AuditLog::record('dataset.quality_checked', 'dataset', $dataset->getKey(), [
            'score' => $score,
            'verdict' => $verdict->value,
            'run_id' => $result['run_id'] ?? null,
        ]);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $operation): Response
    {
        $baseUrl = rtrim((string) config('ai_engine.base_url'), '/');
        $serviceKey = (string) config('ai_engine.service_key');

        if ($baseUrl === '' || $serviceKey === '') {
            throw new AiEngineException(
                'AI engine is not configured: set AI_ENGINE_URL and SERVICE_API_KEY.',
                503,
                $operation,
            );
        }

        try {
            return $call($this->http());
        } catch (AiEngineException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('quality_engine.failed', ['operation' => $operation, 'error' => $exception->getMessage()]);

            throw new AiEngineException($exception->getMessage(), 502, $operation);
        }
    }

    private function http(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withHeaders([
                (string) config('ai_engine.service_key_header') => (string) config('ai_engine.service_key'),
                'X-Client' => 'laravel-orchestrator',
            ])
            ->connectTimeout((int) config('ai_engine.connect_timeout'))
            ->timeout((int) config('ai_engine.timeout'));
    }

    /** @return array<string, mixed> */
    private function unwrap(Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new AiEngineException($this->errorMessage($response), $response->status(), $operation, $response->json());
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException($this->errorMessage($response), 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $message = data_get($body, 'error.message')
                ?? data_get($body, 'detail')
                ?? data_get($body, 'message');

            if (is_string($message) && $message !== '') {
                return $message;
            }

            $detail = data_get($body, 'detail');

            if (is_array($detail) && $detail !== []) {
                $parts = [];

                foreach ($detail as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $field = (string) (data_get($item, 'loc.1') ?? data_get($item, 'loc') ?? '');
                    $reason = (string) (data_get($item, 'msg') ?? '');
                    $parts[] = trim($field === '' ? $reason : "{$field}: {$reason}");
                }

                if ($parts !== []) {
                    return 'AI engine rejected the request payload — '.implode('; ', $parts);
                }
            }
        }

        $status = $response->status();

        return match (true) {
            in_array($status, [401, 403], true) => 'AI engine rejected the service key. Check SERVICE_API_KEY matches on both services.',
            $status === 404 => 'AI engine endpoint not found. Check AI_ENGINE_URL and the engine version.',
            $status === 422 => 'AI engine rejected the request payload.',
            $status === 429 => 'AI engine rate limit reached. Retry shortly.',
            $status >= 500 => 'AI engine returned a server error ('.$status.').',
            default => 'AI engine request failed with status '.$status.'.',
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim($path, '/');
    }
}
