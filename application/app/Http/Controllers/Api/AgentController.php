<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiEngineException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function store(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
            'template' => ['nullable', 'string', 'max:64'],
        ], [], [
            'message' => 'message',
            'conversation_id' => 'conversation id',
            'template' => 'template',
        ]);

        $conversationId = isset($validated['conversation_id'])
            ? (int) $validated['conversation_id']
            : null;

        // The engine conversation carries the prior turns and their context, so
        // continuing someone else's would read their history. The id is
        // sequential and guessable, and an unknown id is indistinguishable from
        // another user's unless it is checked here.
        if ($conversationId !== null) {
            $owned = ChatThread::query()
                ->where('ai_conversation_id', $conversationId)
                ->where('user_id', $request->user()->getKey())
                ->exists();

            if (! $owned) {
                throw ValidationException::withMessages([
                    'conversation_id' => ['Unknown conversation for this account.'],
                ]);
            }
        }

        $result = $engine->chat($validated['message'], $conversationId, $this->templateContext($validated));

        $answer = (string) ($result['answer'] ?? '');
        $engineConversationId = isset($result['conversation_id'])
            ? (int) $result['conversation_id']
            : null;

        if ($engineConversationId !== null) {
            // Claim the engine conversation for this account so the next turn
            // passes the ownership check above.
            ChatThread::query()
                ->where('user_id', $request->user()->getKey())
                ->whereNull('ai_conversation_id')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit(1)
                ->get()
                ->each(fn (ChatThread $thread) => $thread->forceFill([
                    'ai_conversation_id' => $engineConversationId,
                ])->save());
        }

        AuditLog::record('agent.chat', 'agent', $engineConversationId, [
            'message_length' => mb_strlen($validated['message']),
            'steps' => (int) ($result['steps'] ?? 0),
        ]);

        return ApiResponse::data([
            'reply' => $answer,
            'answer' => $answer,
            'conversation_id' => $engineConversationId,
            'evidence' => (array) ($result['evidence'] ?? []),
            'steps' => (int) ($result['steps'] ?? 0),
        ]);
    }

    /**
     * Prompt template key forwarded to the engine inside `context`.
     *
     * The engine also accepts `?template=` and `context.template`; this
     * controller sends it as `context.template` so the pinned
     * `AiEngineClient::chat()` body is untouched. Absent means `[]`, exactly
     * the body older clients sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function templateContext(array $validated): array
    {
        $template = trim((string) ($validated['template'] ?? ''));

        return $template === '' ? [] : ['template' => $template];
    }

    // ------------------------------------------------------------------
    // Enterprise AI (additive; `store()` above is untouched in behaviour).
    //
    // Usage proxy goes through `Http` directly instead of `AiEngineClient`:
    // the client surface is pinned by
    // `EngineClientContractTest::test_every_public_method_of_the_client_is_pinned_here`,
    // so adding public methods there would break the existing suite. The
    // envelope handling below mirrors `AiEngineClient::unwrap()` — the engine
    // answers `{success, data}` and failures surface as `AiEngineException`
    // rendered by `bootstrap/app.php` with `code: ai_engine_error`.
    // Suggested route (wired by master): `GET /api/ai/usage`.
    // ------------------------------------------------------------------

    public function usage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
        ], [], [
            'conversation_id' => 'conversation id',
        ]);

        // Same ownership rule as store(): a conversation id is sequential and
        // guessable, so an unknown id must be indistinguishable from another
        // user's. Without this, account A could read account B's usage ledger.
        if (isset($validated['conversation_id'])) {
            $owned = ChatThread::query()
                ->where('ai_conversation_id', (int) $validated['conversation_id'])
                ->where('user_id', $request->user()->getKey())
                ->exists();

            if (! $owned) {
                throw ValidationException::withMessages([
                    'conversation_id' => ['Unknown conversation for this account.'],
                ]);
            }
        }

        $query = array_filter([
            'conversation_id' => $validated['conversation_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return ApiResponse::data($this->engineGet('/ai/usage', $query, 'ai.usage'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|array<int, mixed>
     */
    private function engineGet(string $path, array $query, string $operation): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->engineHeaders())
                ->timeout((int) config('ai_engine.timeout', 60))
                ->get($this->engineUrl($path), $query);
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
        return rtrim((string) config('ai_engine.base_url'), '/').'/api/v1/'.ltrim($path, '/');
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
