<?php

namespace App\Services;

use App\Exceptions\AiEngineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-to-server client for the decision/scenario engine endpoints.
 *
 * `AiEngineClient` is shared and read-only for this domain, so this service
 * carries its own private HTTP helper built from the same `config/ai_engine`
 * values (`base_url`, `service_key`, `service_key_header`, `timeout`) and the
 * same `X-Service-Key` header. Envelope handling mirrors
 * `AiEngineClient::unwrap()`: `{success, data}` unwrapped, `{success: false}`
 * mapped to 422, HTTP failures mapped to their status — every failure mode
 * surfaces as a single `AiEngineException` rendered by `bootstrap/app.php`.
 */
class DecisionService
{
    /**
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function recommend(array $subject = []): array
    {
        return $this->enginePost('/decision/recommend', [
            'subject' => $subject,
        ], 'decision.recommend');
    }

    /**
     * @return array<string, mixed>
     */
    public function cases(int $limit = 50): array
    {
        return $this->engineGet('/decision/cases', [
            'limit' => max(1, min(200, $limit)),
        ], 'decision.cases');
    }

    /**
     * @return array<string, mixed>
     */
    public function case(int $caseId): array
    {
        return $this->engineGet("/decision/cases/{$caseId}", [], 'decision.case');
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function runScenario(string $type, array $params = [], array $subject = []): array
    {
        return $this->enginePost('/decision/scenarios/run', [
            'type' => $type,
            'params' => $params,
            'subject' => $subject,
        ], 'decision.scenarios.run');
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $caseId, string $actor, string $decision, string $rationale = ''): array
    {
        return $this->enginePost("/decision/cases/{$caseId}/audit", [
            'actor' => $actor,
            'decision' => $decision,
            'rationale' => $rationale,
        ], 'decision.audit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->engineGet('/decision/rules', [], 'decision.rules');
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
     * @return array<string, mixed>
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
            $response->status() === 404 => 'Decision case not found.',
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
