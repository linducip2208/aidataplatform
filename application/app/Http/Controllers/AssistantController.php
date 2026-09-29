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
    /** Sidebar width: a user with hundreds of threads still gets a usable page. */
    private const THREAD_LIMIT = 25;

    /** Older messages are reachable by starting a new thread, not by unbounded growth. */
    private const MESSAGE_LIMIT = 100;

    public function index(Request $request): View
    {
        $threads = ChatThread::where('user_id', $request->user()->getKey())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::THREAD_LIMIT)
            ->get();

        $thread = null;

        if ($request->filled('thread')) {
            $thread = $threads->firstWhere('id', (int) $request->query('thread'));
        }

        $thread ??= $threads->first();

        // Bounded, but still shown oldest-first: take the newest page and reverse
        // it rather than loading a thread that has been chatted with for a year.
        $messages = $thread
            ? $thread->messages()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MESSAGE_LIMIT)
                ->get()
                ->reverse()
                ->values()
            : collect();

        return view('assistant.index', [
            'threads' => $threads,
            'thread' => $thread,
            'messages' => $messages,
            'messagesTruncated' => $thread !== null && $thread->message_count > self::MESSAGE_LIMIT,
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
            'template' => ['nullable', 'string', 'max:64'],
        ], [], [
            'message' => 'pesan',
            'thread_id' => 'percakapan',
            'template' => 'template',
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

        // The user's message is committed and the thread counters are updated
        // before the engine is called. If the engine then fails, the thread is
        // left showing one message and a matching count, rather than a persisted
        // message next to a `message_count` of 0.
        $thread->messages()->create([
            'role' => 'user',
            'content' => $message,
        ]);

        $thread->update($this->threadCounters($thread));

        $result = $engine->chat($message, $thread->ai_conversation_id, $this->templateContext($validated));

        $assistantMessage = $thread->messages()->create([
            'role' => 'assistant',
            'content' => (string) ($result['answer'] ?? ''),
            'evidence' => (array) ($result['evidence'] ?? []),
            'steps' => (int) ($result['steps'] ?? 0),
        ]);

        // The engine's token/cost ledger summary for the turn. Stored on the
        // `meta` JSON column (added by `2026_09_30_030000_*`), never merged
        // into `evidence`, so the evidence readers and their tests keep
        // seeing exactly what the engine returned. Encoded explicitly:
        // `ChatMessage` carries no `meta` cast, and an uncast array would hit
        // the driver as a literal "Array".
        $usage = $result['usage'] ?? null;

        if (is_array($usage) && $usage !== []) {
            $assistantMessage->forceFill([
                'meta' => json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->save();
        }

        $attributes = $this->threadCounters($thread);

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

    /**
     * Prompt template key forwarded to the engine inside `context`.
     *
     * Absent means `[]`, exactly the body older clients sent — see
     * `Api\AgentController::templateContext()` for the shared rationale.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function templateContext(array $validated): array
    {
        $template = trim((string) ($validated['template'] ?? ''));

        return $template === '' ? [] : ['template' => $template];
    }

    /**
     * Counters are refreshed from the database rather than incremented, so the
     * thread never advertises more or fewer messages than it holds — including
     * after a partially completed turn.
     *
     * @return array<string, mixed>
     */
    private function threadCounters(ChatThread $thread): array
    {
        return [
            'message_count' => $thread->messages()->count(),
            'last_message_at' => now(),
        ];
    }
}
