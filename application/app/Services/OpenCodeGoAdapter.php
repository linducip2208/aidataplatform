<?php

namespace App\Services;

use App\Models\AiProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * HTTP adapter for the OpenCode Go provider (Responses API).
 *
 * The API key is supplied per call and never stored, logged, or returned:
 * every note/error passes through {@see scrub()}. Retry only covers
 * transient statuses (config `ai_providers.opencode_go.retry_statuses`);
 * authentication and validation errors are never retried.
 */
class OpenCodeGoAdapter
{
    /** @var callable|null */
    private $streamReader = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 3,
        private readonly ?\Closure $sleeper = null,
    ) {}

    public static function forProvider(AiProvider $provider, string $apiKey): self
    {
        $baseUrl = trim((string) $provider->base_url) !== ''
            ? rtrim(trim((string) $provider->base_url), '/')
            : rtrim((string) config('ai_providers.opencode_go.default_base_url'), '/');

        return new self(
            $baseUrl,
            $apiKey,
            $provider->effectiveTimeout(),
            $provider->effectiveRetries(),
        );
    }

    public function modelsUrl(): string
    {
        return $this->baseUrl.config('ai_providers.opencode_go.models_path', '/models');
    }

    public function responsesUrl(): string
    {
        return $this->baseUrl.config('ai_providers.opencode_go.responses_path', '/responses');
    }

    // ------------------------------------------------------------------
    // Model discovery
    // ------------------------------------------------------------------

    /**
     * GET /models and normalize every entry. Muse Spark (or any other id
     * the API advertises) appears automatically: nothing is allowlisted.
     *
     * @return array{models: list<array{external_id: string, name: string|null, capabilities: list<string>, metadata: array<string, mixed>}>, raw_count: int}
     */
    public function discoverModels(): array
    {
        $sent = $this->sendWithRetry('GET', $this->modelsUrl(), []);

        if ($sent['error'] !== null || $sent['response'] === null) {
            return ['models' => [], 'raw_count' => 0];
        }

        return self::normalizeModelsList($sent['response']->json());
    }

    /**
     * @return array{models: list<array{external_id: string, name: string|null, capabilities: list<string>, metadata: array<string, mixed>}>, raw_count: int}
     */
    public static function normalizeModelsList(mixed $payload): array
    {
        $items = [];

        if (is_array($payload)) {
            $items = array_is_list($payload) ? $payload : ($payload['data'] ?? $payload['models'] ?? []);
        }

        if (! is_array($items)) {
            return ['models' => [], 'raw_count' => 0];
        }

        $models = [];

        foreach ($items as $item) {
            $normalized = self::normalizeModel($item);

            if ($normalized !== null) {
                $models[] = $normalized;
            }
        }

        return ['models' => $models, 'raw_count' => count($items)];
    }

    /**
     * @return array{external_id: string, name: string|null, capabilities: list<string>, metadata: array<string, mixed>}|null
     */
    public static function normalizeModel(mixed $item): ?array
    {
        if (is_string($item)) {
            $item = ['id' => $item];
        }

        if (! is_array($item)) {
            return null;
        }

        $externalId = trim((string) ($item['id'] ?? $item['model'] ?? $item['name'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        return [
            'external_id' => $externalId,
            'name' => isset($item['name']) && trim((string) $item['name']) !== ''
                ? trim((string) $item['name'])
                : (isset($item['display_name']) && trim((string) $item['display_name']) !== ''
                    ? trim((string) $item['display_name'])
                    : null),
            'capabilities' => self::detectCapabilities($item),
            'metadata' => self::safeMetadata($item),
        ];
    }

    /** @return list<string> */
    public static function detectCapabilities(array $item): array
    {
        $found = [];

        foreach ($item['capabilities'] ?? [] as $capability) {
            if (is_string($capability) && trim($capability) !== '') {
                $found[] = Str::slug(trim($capability), '-');
            }
        }

        $flagMap = [
            'streaming' => 'streaming',
            'stream' => 'streaming',
            'tools' => 'tools',
            'tool_choice' => 'tools',
            'function_calling' => 'tools',
            'parallel_tool_calls' => 'parallel-tools',
            'vision' => 'vision',
            'images' => 'vision',
            'image_input' => 'vision',
            'reasoning' => 'reasoning',
            'embeddings' => 'embeddings',
            'structured_output' => 'structured-output',
            'json_mode' => 'structured-output',
        ];

        foreach ($flagMap as $field => $capability) {
            if (! empty($item[$field])) {
                $found[] = $capability;
            }
        }

        foreach ($item as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'supports_') && ! empty($value)) {
                $found[] = Str::slug(substr($key, strlen('supports_')), '-');
            }
        }

        return array_values(array_unique($found));
    }

    /** @return array<string, mixed> */
    public static function safeMetadata(array $item): array
    {
        $metadata = [];

        foreach ($item as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Model listings must never smuggle secrets into our database.
            if (preg_match('/key|secret|token|password/i', $key)) {
                continue;
            }

            if (is_scalar($value) || $value === null || is_array($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    // ------------------------------------------------------------------
    // Test connection (real HTTP: GET /models + latency)
    // ------------------------------------------------------------------

    /** @return array{ok: bool, note: string, status: int|null, latency_ms: int|null, models: int|null} */
    public function testConnection(): array
    {
        $started = hrtime(true);
        $sent = $this->sendWithRetry('GET', $this->modelsUrl(), []);
        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($sent['error'] !== null || $sent['response'] === null) {
            return [
                'ok' => false,
                'note' => 'Unreachable: '.$this->scrub(substr($sent['error'] ?? 'unknown error', 0, 160)),
                'status' => null,
                'latency_ms' => $latencyMs,
                'models' => null,
            ];
        }

        $response = $sent['response'];
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return [
                'ok' => false,
                'note' => "HTTP {$status}: key rejected.",
                'status' => $status,
                'latency_ms' => $latencyMs,
                'models' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'note' => 'HTTP '.$status.$this->apiErrorSuffix($response->json()),
                'status' => $status,
                'latency_ms' => $latencyMs,
                'models' => null,
            ];
        }

        $count = self::normalizeModelsList($response->json())['raw_count'];

        return [
            'ok' => true,
            'note' => "HTTP {$status} · {$count} models · {$latencyMs}ms.",
            'status' => $status,
            'latency_ms' => $latencyMs,
            'models' => $count,
        ];
    }

    // ------------------------------------------------------------------
    // Test model + inference (real HTTP: POST /responses)
    // ------------------------------------------------------------------

    /** @return array{ok: bool, note: string, text: string|null, usage: array<string, mixed>|null, latency_ms: int|null, status: int|null} */
    public function testModel(string $model, ?string $prompt = null): array
    {
        $model = trim($model);

        if ($model === '') {
            return ['ok' => false, 'note' => 'Model must not be empty.', 'text' => null, 'usage' => null, 'latency_ms' => null, 'status' => null];
        }

        $started = hrtime(true);

        try {
            $parsed = $this->complete($model, $prompt ?? 'Reply with: ok', ['max_output_tokens' => 16]);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'note' => 'Unreachable: '.$this->scrub(substr($exception->getMessage(), 0, 160)),
                'text' => null,
                'usage' => null,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'status' => null,
            ];
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $status = $parsed['status'];

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'note' => "HTTP {$status}: key rejected.", 'text' => null, 'usage' => null, 'latency_ms' => $latencyMs, 'status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            $detail = isset($parsed['error_detail']) ? $this->scrub($parsed['error_detail']) : null;

            return ['ok' => false, 'note' => "HTTP {$status}".($detail !== null ? ": {$detail}" : '.'), 'text' => null, 'usage' => null, 'latency_ms' => $latencyMs, 'status' => $status];
        }

        return [
            'ok' => true,
            'note' => "HTTP {$status} · {$latencyMs}ms.",
            'text' => $parsed['text'] !== '' ? substr($parsed['text'], 0, 500) : null,
            'usage' => $parsed['usage'],
            'latency_ms' => $latencyMs,
            'status' => $status,
        ];
    }

    /**
     * One blocking Responses call.
     *
     * @param  array<string, mixed>  $options  max_output_tokens|temperature|top_p|tools|tool_choice|metadata
     * @return array{text: string, usage: array<string, mixed>, model: string|null, request_id: string|null, status: int, tool_calls: list<array<string, mixed>>}
     */
    public function complete(string $model, string $input, array $options = []): array
    {
        $payload = ['model' => $model, 'input' => $input];

        foreach (['max_output_tokens', 'temperature', 'top_p', 'tools', 'tool_choice', 'metadata'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $payload[$key] = $options[$key];
            }
        }

        $sent = $this->sendWithRetry('POST', $this->responsesUrl(), $payload);

        if ($sent['error'] !== null || $sent['response'] === null) {
            throw new \RuntimeException($sent['error'] ?? 'No response from provider.');
        }

        $parsed = self::parseResponsesPayload($sent['response']->json() ?? [], $model);
        $parsed['status'] = $sent['response']->status();

        return $parsed;
    }

    /**
     * Defensive Responses payload parser: canonical shape first, then the
     * shapes providers actually ship, so a new field layout degrades to
     * partial data instead of an exception.
     *
     * @return array{text: string, usage: array<string, mixed>, model: string|null, request_id: string|null, tool_calls: list<array<string, mixed>>}
     */
    public static function parseResponsesPayload(mixed $payload, ?string $fallbackModel = null): array
    {
        $result = [
            'text' => '',
            'usage' => [],
            'model' => $fallbackModel,
            'request_id' => null,
            'tool_calls' => [],
        ];

        if (! is_array($payload)) {
            return $result;
        }

        $texts = [];
        $toolCalls = [];

        $output = $payload['output'] ?? null;

        if (is_array($output)) {
            foreach ($output as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $type = (string) ($item['type'] ?? '');

                if ($type === 'message') {
                    foreach ((array) ($item['content'] ?? []) as $part) {
                        if (! is_array($part)) {
                            continue;
                        }

                        $partType = (string) ($part['type'] ?? '');

                        if (in_array($partType, ['output_text', 'text', 'input_text'], true) && isset($part['text']) && is_string($part['text'])) {
                            $texts[] = $part['text'];
                        }
                    }
                }

                if ($type === 'function_call') {
                    $toolCalls[] = self::normalizeToolCall($item);
                }
            }
        }

        if ($texts === []) {
            foreach (['output_text', 'text', 'response', 'answer'] as $key) {
                if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                    $texts[] = $payload[$key];
                    break;
                }
            }
        }

        // Chat-completions-shaped cousins degrade gracefully too.
        if ($texts === [] && isset($payload['choices'][0]['message']['content']) && is_string($payload['choices'][0]['message']['content'])) {
            $texts[] = $payload['choices'][0]['message']['content'];
        }

        if (isset($payload['model']) && is_string($payload['model'])) {
            $result['model'] = $payload['model'];
        }

        foreach (['id', 'response_id', 'request_id'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $result['request_id'] = $payload[$key];
                break;
            }
        }

        $result['text'] = implode('', $texts);
        $result['usage'] = self::normalizeUsage($payload['usage'] ?? null);
        $result['tool_calls'] = $toolCalls;

        $detail = self::extractErrorDetail($payload);

        if ($detail !== null) {
            // Raw here; callers scrub with the request key before display.
            $result['error_detail'] = $detail;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function normalizeToolCall(array $item): array
    {
        $arguments = $item['arguments'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : ['raw' => $arguments];
        }

        return [
            'name' => (string) ($item['name'] ?? ''),
            'arguments' => is_array($arguments) ? $arguments : [],
            'call_id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
        ];
    }

    /**
     * Pull a human-readable error out of the shapes providers actually
     * ship: {error:{message}}, {error:"..."}, {message}, {detail}.
     */
    public static function extractErrorDetail(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['error', 'message', 'detail'] as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate)) {
                $candidate = $candidate['message'] ?? $candidate['detail'] ?? null;
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                return substr(trim($candidate), 0, 200);
            }
        }

        return null;
    }

    private function apiErrorSuffix(mixed $json): string
    {
        $detail = self::extractErrorDetail($json);

        return $detail !== null ? ': '.$this->scrub($detail) : '.';
    }

    /** @return array<string, mixed> */
    public static function normalizeUsage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [];
        }

        $normalized = [];

        $input = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;

        if (is_numeric($input)) {
            $normalized['input_tokens'] = (int) $input;
        }

        if (is_numeric($output)) {
            $normalized['output_tokens'] = (int) $output;
        }

        if (is_numeric($total)) {
            $normalized['total_tokens'] = (int) $total;
        } elseif (isset($normalized['input_tokens'], $normalized['output_tokens'])) {
            $normalized['total_tokens'] = $normalized['input_tokens'] + $normalized['output_tokens'];
        }

        foreach (['reasoning_tokens', 'reasoning'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $normalized['reasoning_tokens'] = (int) $usage[$key];
                break;
            }
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Streaming (SSE -> normalized internal events)
    // ------------------------------------------------------------------

    /**
     * Blocking call with `stream: true`, yielding normalized events:
     * AIStreamStarted, AITextDelta, AIToolCall, AIResponseCompleted,
     * AIResponseFailed. (AIToolResult is yielded by the tool executor that
     * consumes AIToolCall, never by the wire.)
     *
     * @return iterable<array<string, mixed>>
     */
    public function streamComplete(string $model, string $input, array $options = []): iterable
    {
        $payload = array_merge(['model' => $model, 'input' => $input], $options, ['stream' => true]);

        try {
            $response = Http::acceptJson()
                ->withHeaders($this->authHeaders())
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->post($this->responsesUrl(), $payload);
        } catch (Throwable $exception) {
            yield ['event' => 'AIResponseFailed', 'error' => 'Unreachable: '.$this->scrub(substr($exception->getMessage(), 0, 160)), 'status' => null];

            return;
        }

        if (! $response->successful()) {
            yield ['event' => 'AIResponseFailed', 'error' => 'HTTP '.$response->status().'.', 'status' => $response->status()];

            return;
        }

        $stream = $response->toPsrResponse()->getBody();

        yield ['event' => 'AIStreamStarted', 'response_id' => null];

        $buffer = '';
        $text = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);

            if (! is_string($chunk) || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            [$events, $buffer] = self::parseSseBuffer($buffer);

            foreach ($events as $event) {
                if ($event['event'] === 'AITextDelta') {
                    $text .= $event['delta'];
                }

                yield $event;
            }
        }

        yield ['event' => 'AIResponseCompleted', 'text' => $text, 'usage' => []];
    }

    /**
     * Pure SSE frame parser (unit-testable without a socket): splits complete
     * `data:` frames off the buffer, keeps the partial tail.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public static function parseSseBuffer(string $buffer): array
    {
        $events = [];
        $parts = preg_split("/\r?\n\r?\n/", $buffer);

        if ($parts === false) {
            return [[], $buffer];
        }

        $remainder = (string) array_pop($parts);

        foreach ($parts as $frame) {
            $eventName = '';
            $dataLines = [];

            foreach (preg_split("/\r?\n/", $frame) ?: [] as $line) {
                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }

            $data = implode("\n", $dataLines);

            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $decoded = json_decode($data, true);
            $events[] = self::sseFrameToEvent($eventName, is_array($decoded) ? $decoded : ['text' => $data]);
        }

        return [$events, $remainder];
    }

    /** @return array<string, mixed> */
    private static function sseFrameToEvent(string $eventName, array $data): array
    {
        if (in_array($eventName, ['response.output_text.delta', 'text.delta'], true)) {
            $delta = $data['delta'] ?? $data['text'] ?? '';

            return ['event' => 'AITextDelta', 'delta' => is_string($delta) ? $delta : ''];
        }

        if (in_array($eventName, ['response.function_call', 'tool_call', 'function_call'], true)) {
            $call = self::normalizeToolCall($data);

            return ['event' => 'AIToolCall', 'name' => $call['name'], 'arguments' => $call['arguments'], 'call_id' => $call['call_id']];
        }

        if (in_array($eventName, ['response.completed', 'completed', 'done'], true)) {
            return ['event' => 'AIResponseCompleted', 'text' => (string) ($data['text'] ?? ''), 'usage' => self::normalizeUsage($data['usage'] ?? null)];
        }

        if (in_array($eventName, ['error', 'response.failed', 'failed'], true)) {
            return ['event' => 'AIResponseFailed', 'error' => (string) ($data['error'] ?? $data['message'] ?? 'stream failed'), 'status' => null];
        }

        if (isset($data['text']) && is_string($data['text'])) {
            return ['event' => 'AITextDelta', 'delta' => $data['text']];
        }

        return ['event' => 'AITextDelta', 'delta' => ''];
    }

    // ------------------------------------------------------------------
    // Health check
    // ------------------------------------------------------------------

    /** @return array{status: string, latency_ms: int|null, http_status: int|null, models_available: int|null, checked_at: string, note: string} */
    public function healthCheck(): array
    {
        $started = hrtime(true);
        $sent = $this->sendWithRetry('GET', $this->modelsUrl(), [], 0);
        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $checkedAt = now()->toIso8601String();

        if ($sent['error'] !== null || $sent['response'] === null) {
            return [
                'status' => 'unhealthy',
                'latency_ms' => $latencyMs,
                'http_status' => null,
                'models_available' => null,
                'checked_at' => $checkedAt,
                'note' => 'Unreachable: '.$this->scrub(substr($sent['error'] ?? 'unknown error', 0, 160)),
            ];
        }

        $status = $sent['response']->status();

        if ($status === 401 || $status === 403) {
            return ['status' => 'unhealthy', 'latency_ms' => $latencyMs, 'http_status' => $status, 'models_available' => null, 'checked_at' => $checkedAt, 'note' => "HTTP {$status}: key rejected."];
        }

        if ($status < 200 || $status >= 300) {
            return ['status' => 'unhealthy', 'latency_ms' => $latencyMs, 'http_status' => $status, 'models_available' => null, 'checked_at' => $checkedAt, 'note' => "HTTP {$status}."];
        }

        $count = self::normalizeModelsList($sent['response']->json())['raw_count'];

        if ($count === 0) {
            return ['status' => 'degraded', 'latency_ms' => $latencyMs, 'http_status' => $status, 'models_available' => 0, 'checked_at' => $checkedAt, 'note' => 'Reachable but no models advertised.'];
        }

        return ['status' => 'healthy', 'latency_ms' => $latencyMs, 'http_status' => $status, 'models_available' => $count, 'checked_at' => $checkedAt, 'note' => "HTTP {$status} · {$count} models · {$latencyMs}ms."];
    }

    // ------------------------------------------------------------------
    // Retry core + plumbing
    // ------------------------------------------------------------------

    public static function isTransientStatus(int $status): bool
    {
        return in_array($status, config('ai_providers.opencode_go.retry_statuses', [408, 429, 500, 502, 503, 504]), true);
    }

    /**
     * @return array{response: Response|null, attempts: int, slept_ms: int, error: string|null}
     */
    public function sendWithRetry(string $method, string $url, array $payload = [], ?int $maxRetries = null): array
    {
        $maxRetries ??= $this->maxRetries;
        $attempts = 0;
        $sleptMs = 0;

        while (true) {
            $attempts++;

            try {
                $request = Http::acceptJson()
                    ->withHeaders($this->authHeaders())
                    ->timeout($this->timeout);

                $response = strtolower($method) === 'get'
                    ? $request->get($url, $payload)
                    : $request->post($url, $payload);

                if (! self::isTransientStatus($response->status()) || $attempts > $maxRetries) {
                    return ['response' => $response, 'attempts' => $attempts, 'slept_ms' => $sleptMs, 'error' => null];
                }

                $sleptMs += $this->backoffMs($attempts, $response);
            } catch (RequestException $exception) {
                $response = $exception->response;

                if ($response !== null && (! self::isTransientStatus($response->status()) || $attempts > $maxRetries)) {
                    return ['response' => $response, 'attempts' => $attempts, 'slept_ms' => $sleptMs, 'error' => null];
                }

                if ($response === null && $attempts > $maxRetries) {
                    return ['response' => null, 'attempts' => $attempts, 'slept_ms' => $sleptMs, 'error' => $exception->getMessage()];
                }

                $sleptMs += $this->backoffMs($attempts, $response);
            } catch (Throwable $exception) {
                // Connection-level failure (DNS, TLS, timeout): transient.
                if ($attempts > $maxRetries) {
                    return ['response' => null, 'attempts' => $attempts, 'slept_ms' => $sleptMs, 'error' => $exception->getMessage()];
                }

                $sleptMs += $this->backoffMs($attempts, null);
            }
        }
    }

    private function backoffMs(int $attempt, ?Response $response): int
    {
        $retryAfter = $response !== null ? $response->header('Retry-After') : null;

        if (is_string($retryAfter) && ctype_digit(trim($retryAfter))) {
            $ms = min(
                (int) trim($retryAfter) * 1000,
                (int) config('ai_providers.opencode_go.max_retry_after_seconds', 30) * 1000,
            );

            $this->sleepMs($ms);

            return $ms;
        }

        $ms = min(500 * (2 ** max($attempt - 1, 0)), 5000);

        $this->sleepMs($ms);

        return $ms;
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }

        usleep($ms * 1000);
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if (trim($this->apiKey) !== '') {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        return $headers;
    }

    public function scrub(string $text): string
    {
        $key = trim($this->apiKey);

        if ($key === '') {
            return $text;
        }

        return str_replace($key, '[redacted]', $text);
    }
}
