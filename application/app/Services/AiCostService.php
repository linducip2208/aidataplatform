<?php

namespace App\Services;

use App\Exceptions\AiEngineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-to-server client for the AI cost dashboard aggregation
 * (`GET /api/v1/ai/usage/summary`).
 *
 * Same private-HTTP-helper shape as `DecisionService`: `{success, data}`
 * unwrapped, failures as `AiEngineException`. All numbers come from the
 * engine `ai_usage` ledger; estimates stay labelled there.
 */
class AiCostService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        $data = $this->engineGet('/ai/usage/summary', [
            'days' => max(1, min(365, $days)),
        ], 'ai.usage.summary');

        return is_array($data) ? $data : [];
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

            return is_array($data) ? $data : [];
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
