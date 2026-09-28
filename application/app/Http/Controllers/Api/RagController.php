<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiEngineClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagController extends Controller
{
    public function query(Request $request, AiEngineClient $engine): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required_without:query', 'nullable', 'string', 'max:2000'],
            'query' => ['required_without:question', 'nullable', 'string', 'max:2000'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [], [
            'question' => 'question',
            'query' => 'query',
            'top_k' => 'top k',
        ]);

        $text = trim((string) (($validated['question'] ?? null) ?: ($validated['query'] ?? '')));
        $topK = (int) ($validated['top_k'] ?? 5);

        $result = $engine->ragQuery($text, $topK);

        return ApiResponse::data([
            'answer' => (string) ($result['answer'] ?? ''),
            'citations' => (array) ($result['citations'] ?? $result['chunks'] ?? []),
        ]);
    }
}
