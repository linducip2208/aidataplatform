<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Services\AiEngineClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssistantController extends Controller
{
    public function index(Request $request): View
    {
        $threads = ChatThread::where('user_id', $request->user()->getKey())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $thread = null;

        if ($request->filled('thread')) {
            $thread = $threads->firstWhere('id', (int) $request->query('thread'));
        }

        $thread ??= $threads->first();

        return view('assistant.index', [
            'threads' => $threads,
            'thread' => $thread,
            'messages' => $thread
                ? $thread->messages()->orderBy('created_at')->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function show(Request $request, ChatThread $thread): RedirectResponse|View
    {
        abort_unless($thread->user_id === $request->user()->getKey(), 404);

        return redirect()->route('assistant.index', ['thread' => $thread->getKey()]);
    }

    public function store(Request $request, AiEngineClient $engine): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'thread_id' => ['nullable', 'integer'],
        ], [], [
            'message' => 'pesan',
            'thread_id' => 'percakapan',
        ]);

        $user = $request->user();
        $message = trim($validated['message']);

        $thread = null;

        if (! empty($validated['thread_id'])) {
            $thread = ChatThread::where('user_id', $user->getKey())
                ->find($validated['thread_id']);
        }

        $thread ??= $user->chatThreads()->create([
            'title' => mb_substr($message, 0, 60),
        ]);

        $thread->messages()->create([
            'role' => 'user',
            'content' => $message,
        ]);

        $result = $engine->chat($message, $thread->ai_conversation_id);

        $thread->messages()->create([
            'role' => 'assistant',
            'content' => (string) ($result['answer'] ?? ''),
            'evidence' => (array) ($result['evidence'] ?? []),
            'steps' => (int) ($result['steps'] ?? 0),
        ]);

        $attributes = [
            'message_count' => $thread->messages()->count(),
            'last_message_at' => now(),
        ];

        if (isset($result['conversation_id'])) {
            $attributes['ai_conversation_id'] = (int) $result['conversation_id'];
        }

        $thread->update($attributes);

        AuditLog::record('assistant.chat', 'chat_thread', $thread->getKey(), [
            'engine_conversation_id' => $thread->ai_conversation_id,
            'steps' => (int) ($result['steps'] ?? 0),
        ]);

        return redirect()
            ->route('assistant.index', ['thread' => $thread->getKey()])
            ->with('status', 'Balasan asisten siap di bawah.');
    }

    public function destroy(Request $request, ChatThread $thread): RedirectResponse
    {
        abort_unless($thread->user_id === $request->user()->getKey(), 404);

        $thread->delete();

        return redirect()
            ->route('assistant.index')
            ->with('status', 'Percakapan dihapus.');
    }
}
