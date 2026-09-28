<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function store(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
        ], [], [
            'message' => 'message',
            'conversation_id' => 'conversation id',
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

        $result = $engine->chat($validated['message'], $conversationId);

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
}
