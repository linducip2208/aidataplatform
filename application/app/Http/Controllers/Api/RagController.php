<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RagController extends Controller
{
    public function query(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required_without:query', 'nullable', 'string', 'max:2000'],
            'query' => ['required_without:question', 'nullable', 'string', 'max:2000'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'hybrid' => ['nullable', 'boolean'],
            'rerank' => ['nullable', 'boolean'],
        ], [], [
            'question' => 'question',
            'query' => 'query',
            'top_k' => 'top k',
            'hybrid' => 'hybrid',
            'rerank' => 'rerank',
        ]);

        $text = trim((string) (($validated['question'] ?? null) ?: ($validated['query'] ?? '')));
        $topK = (int) ($validated['top_k'] ?? 5);

        // Document ACL, enforced in the engine before retrieval: the caller
        // never chooses their own allowlist. Viewers see public documents,
        // analysts add internal ones; admins keep the pinned `ragQuery()`
        // path (byte-identical body, legacy allow-all).
        $role = $request->user()->role()->value;
        $allow = match ($role) {
            'viewer' => 'public',
            'analyst' => 'public,internal',
            default => null,
        };

        // Default path is byte-identical: the pinned `ragQuery()` body.
        // `hybrid`/`rerank`/`allow` ride as engine query params on an
        // extended call (same body), whenever any of them applies.
        $flags = [];
        if ($allow !== null) {
            $flags['allow'] = $allow;
        }
        if (array_key_exists('hybrid', $validated)) {
            $flags['hybrid'] = $validated['hybrid'] ? 'true' : 'false';
        }
        if (array_key_exists('rerank', $validated)) {
            $flags['rerank'] = $validated['rerank'] ? 'true' : 'false';
        }

        if ($flags === []) {
            $result = $engine->ragQuery($text, $topK);
        } else {
            $result = $this->enginePost(
                '/rag/query?'.http_build_query($flags),
                ['query' => $text, 'top_k' => $topK],
                'rag.query'
            );
        }

        return ApiResponse::data([
            'answer' => (string) ($result['answer'] ?? ''),
            'citations' => (array) ($result['citations'] ?? $result['chunks'] ?? []),
        ]);
    }

    // ------------------------------------------------------------------
    // Enterprise RAG (additive; `query()` default path is untouched).
    //
    // The extended call goes through `Http` directly instead of
    // `AiEngineClient`: the client surface is pinned by
    // `EngineClientContractTest::test_every_public_method_of_the_client_is_pinned_here`,
    // so adding public methods there would break the existing suite. The
    // envelope handling below mirrors `AiEngineClient::unwrap()`.
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|array<int, mixed>
     */
    private function enginePost(string $path, array $payload, string $operation): array
    {
        try {
            $response = Http::asJson()->acceptJson()
                ->withHeaders($this->engineHeaders())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->post($this->engineUrl($path), $payload);
        } catch (ConnectionException $exception) {
            throw new AiEngineException(
                'AI engine unreachable at '.rtrim((string) config('ai_engine.base_url'), '/').'. Is the fastapi service running?',
                503,
                $operation,
            );
        }

        return $this->unwrap($response, $operation);
    }

    /** @return array<string, string> */
    private function engineHeaders(): array
    {
        return [
            (string) config('ai_engine.service_key_header', 'X-Service-Key') => (string) config('ai_engine.service_key', ''),
            'X-Client' => 'laravel-orchestrator',
        ];
    }

    private function engineUrl(string $path): string
    {
        // `$path` may carry a query string (`/rag/query?hybrid=false`), which
        // must survive the concatenation verbatim.
        [$route, $queryString] = array_pad(explode('?', $path, 2), 2, null);
        $url = rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim((string) $route, '/');

        return $queryString === null || $queryString === '' ? $url : $url.'?'.$queryString;
    }

    /**
     * Mirror of `AiEngineClient::unwrap()` for the direct-Http proxy above.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private function unwrap(Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new AiEngineException(
                $this->engineErrorMessage($response),
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
                throw new AiEngineException($this->engineErrorMessage($response), 422, $operation, $body);
            }

            return (array) ($body['data'] ?? []);
        }

        return $body;
    }

    private function engineErrorMessage(Response $response): string
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
}
