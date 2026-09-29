<?php

namespace App\Services;

use App\Exceptions\AiEngineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-to-server client for the engine alert center
 * (`GET/POST /api/v1/alerts/*`, `GET/POST/PATCH /api/v1/alerts/rules*`).
 *
 * Same private-HTTP-helper shape as `DecisionService` (the shared
 * `AiEngineClient` surface is pinned by contract tests): `{success, data}`
 * unwrapped, `{success: false}` mapped to 422, HTTP failures mapped to
 * their status — every failure surfaces as `AiEngineException`.
 */
class AlertService
{
    public const STATUSES = ['open', 'acknowledged', 'resolved'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function alerts(?string $status = null, ?int $ruleId = null, int $limit = 50): array
    {
        $rows = $this->engineGet('/alerts/alerts', array_filter([
            'status' => $status,
            'rule_id' => $ruleId,
            'limit' => max(1, min(200, $limit)),
        ], static fn (mixed $value): bool => $value !== null), 'alerts.list');

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rules(bool $activeOnly = false): array
    {
        $rows = $this->engineGet('/alerts/rules', [
            'active_only' => $activeOnly ? 'true' : 'false',
        ], 'alerts.rules');

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $data = $this->engineGet('/alerts/metrics', [], 'alerts.catalog');

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(int $alertId): array
    {
        $rows = $this->engineGet("/alerts/alerts/{$alertId}/events", [], 'alerts.events');

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(int $alertId, string $note = ''): array
    {
        return $this->enginePost("/alerts/alerts/{$alertId}/ack", [
            'note' => $note,
        ], 'alerts.ack');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createRule(array $attributes): array
    {
        return $this->enginePost('/alerts/rules', $attributes, 'alerts.rules.store');
    }

    /**
     * @return array<string, mixed>
     */
    public function setRuleActive(int $ruleId, bool $active): array
    {
        try {
            $response = Http::asJson()->acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->patch($this->url("/alerts/rules/{$ruleId}"), ['is_active' => $active]);
        } catch (ConnectionException $exception) {
            throw new AiEngineException(
                'AI engine unreachable at '.rtrim((string) config('ai_engine.base_url'), '/').'. Is the fastapi service running?',
                503,
                'alerts.rules.toggle',
            );
        }

        return $this->unwrap($response, 'alerts.rules.toggle');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enginePost(string $path, array $payload, string $operation): array
    {
        $this->ensureConfigured($operation);

        try {
            $response = Http::asJson()->acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->post($this->url($path), $payload);
        } catch (ConnectionException $exception) {
            throw new AiEngineException(
                'AI engine unreachable at '.rtrim((string) config('ai_engine.base_url'), '/').'. Is the fastapi service running?',
                503,
                $operation,
            );
        }

        return $this->unwrap($response, $operation);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|array<int, mixed>
     */
    private function engineGet(string $path, array $query, string $operation): array
    {
        $this->ensureConfigured($operation);

        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get($this->url($path), $query);
        } catch (ConnectionException $exception) {
            throw new AiEngineException(
                'AI engine unreachable at '.rtrim((string) config('ai_engine.base_url'), '/').'. Is the fastapi service running?',
                503,
                $operation,
            );
        }

        return $this->unwrap($response, $operation);
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new AiEngineException(
                $this->errorMessage($response),
                $response->status(),
                $operation,
                $response->json()
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        if (array_key_exists('success', $body)) {
            if ($body['success'] === false) {
                throw new AiEngineException($this->errorMessage($response), 422, $operation, $body);
            }

            $data = $body['data'] ?? [];

            return is_array($data) ? $data : ['value' => $data];
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
        }

        return match (true) {
            $response->status() === 404 => 'Alert or rule not found.',
            $response->status() === 409 => 'Alert is already resolved and cannot be acknowledged.',
            $response->status() === 422 => 'AI engine rejected the request payload.',
            $response->status() >= 500 => 'AI engine returned a server error ('.$response->status().').',
            default => 'AI engine request failed with status '.$response->status().'.',
        };
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
            'X-Client' => 'laravel-orchestrator',
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim($path, '/');
    }

    private function ensureConfigured(string $operation): void
    {
        if (rtrim((string) config('ai_engine.base_url'), '/') === '' || (string) config('ai_engine.service_key', '') === '') {
            throw new AiEngineException(
                'AI engine is not configured: set AI_ENGINE_URL and SERVICE_API_KEY.',
                503,
                $operation,
            );
        }
    }
}
