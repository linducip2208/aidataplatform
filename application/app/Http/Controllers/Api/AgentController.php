<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $result = $engine->chat($validated['message'], $conversationId);

        $answer = (string) ($result['answer'] ?? '');
        $engineConversationId = isset($result['conversation_id'])
            ? (int) $result['conversation_id']
            : null;

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
