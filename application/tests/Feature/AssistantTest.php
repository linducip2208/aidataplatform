<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected User $otherAnalyst;

    /**
     * Read at request time, not at fake-registration time: stub callbacks are
     * matched first-registered-wins, so a later `Http::fake()` call could never
     * override the single closure registered in `setUp()`. Per-test variation
     * has to go through state.
     */
    protected bool $engineDown = false;

    protected int $engineConversationId = 88;

    protected int $engineSteps = 3;

    /** @var list<array{source: string, data: array<string, mixed>}> */
    // Non-whole floats on purpose: PHP's json_encode renders 8120000.0 as
    // `8120000`, which decodes back as int and would fail a strict comparison.
    protected array $engineEvidence = [
        ['source' => 'query_sales', 'data' => ['rows' => [['branch' => 'BR-03', 'revenue' => 8120000.5]]]],
        ['source' => 'get_kpi', 'data' => ['revenue' => 1250000.25, 'orders' => 412]],
    ];

    protected string $engineAnswer = 'Cabang BR-03 turun 12% dibanding minggu lalu.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->otherAnalyst = User::factory()->analyst()->create();
        $this->fakeEngine();
    }

    /**
     * One closure stub rather than a URL map: `Http::response()` returns a
     * promise, and stub callbacks are resolved first-registered-wins, so a
     * second `Http::fake()` in a test could never override a map registered in
     * `setUp()`.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = $request->url();

            $payload = match (true) {
                str_ends_with($url, '/api/v1/ai/chat') => $this->chatResult(),
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** `POST /api/v1/ai/chat` -> `app/ai/agent.py::run_agent`. */
    protected function chatResult(): array
    {
        return [
            'answer' => $this->engineAnswer,
            'conversation_id' => $this->engineConversationId,
            'evidence' => $this->engineEvidence,
            'steps' => $this->engineSteps,
        ];
    }

    /** @return Collection<int, ClientRequest> */
    protected function chatRequests(): Collection
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/api/v1/ai/chat'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->values();
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_the_index_renders_when_the_user_has_no_threads(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('assistant.index'))
            ->assertOk()
            ->assertViewIs('assistant.index')
            ->assertViewHas('threads', fn ($threads): bool => $threads->isEmpty())
            ->assertViewHas('messages', fn ($messages): bool => $messages->isEmpty())
            ->assertViewHas('thread', null)
            ->assertSee('Belum ada percakapan');
    }

    public function test_the_index_lists_only_the_signed_in_users_threads(): void
    {
        $mine = ChatThread::factory()->forUser($this->analyst)->titled('Analisis penjualan', 2)->create();
        ChatThread::factory()->forUser($this->otherAnalyst)->titled('Rahasia rival', 2)->create();

        $this->actingAs($this->analyst)
            ->get(route('assistant.index'))
            ->assertOk()
            ->assertViewHas('threads', fn ($threads): bool => $threads->count() === 1
                && $threads->first()->is($mine))
            ->assertDontSee('Rahasia rival');
    }

    public function test_the_index_opens_the_most_recent_thread_by_default(): void
    {
        ChatThread::factory()->forUser($this->analyst)
            ->titled('Percakapan lama', 2, now()->subWeek())->create();
        $newest = ChatThread::factory()->forUser($this->analyst)
            ->titled('Percakapan terbaru', 4, now())->create();

        $this->actingAs($this->analyst)
            ->get(route('assistant.index'))
            ->assertOk()
            ->assertViewHas('thread', fn ($thread): bool => $thread !== null && $thread->is($newest))
            ->assertViewHas('threads', fn ($threads): bool => $threads->count() === 2);
    }

    public function test_a_thread_without_messages_renders_its_empty_state(): void
    {
        $thread = ChatThread::factory()->forUser($this->analyst)->titled('Percakapan kosong', 0)->create();

        $this->actingAs($this->analyst)
            ->get(route('assistant.index', ['thread' => $thread->getKey()]))
            ->assertOk()
            ->assertViewHas('thread', fn ($selected): bool => $selected !== null && $selected->is($thread))
            ->assertViewHas('messages', fn ($messages): bool => $messages->isEmpty())
            ->assertSee('Belum ada pesan');
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_posting_a_message_persists_the_user_message(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => '  Cabang mana yang paling turun?  '])
            ->assertRedirect();

        $this->assertDatabaseHas('chat_messages', [
            'chat_thread_id' => ChatThread::firstOrFail()->getKey(),
            'role' => 'user',
            'content' => 'Cabang mana yang paling turun?',
        ]);
    }

    public function test_the_first_turn_creates_a_thread_titled_from_the_message(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Cabang mana yang paling turun?']);

        $this->assertSame('Cabang mana yang paling turun?', ChatThread::firstOrFail()->title);
    }

    public function test_a_long_first_message_produces_a_truncated_thread_title(): void
    {
        $message = str_repeat('a', 40).' '.str_repeat('b', 40);

        $this->actingAs($this->analyst)->post(route('assistant.store'), ['message' => $message]);

        $title = (string) ChatThread::firstOrFail()->title;

        $this->assertSame(60, mb_strlen($title));
        $this->assertSame(mb_substr($message, 0, 60), $title);
    }

    public function test_posting_a_message_calls_the_engine_exactly_once(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Cabang mana yang paling turun?']);

        Http::assertSentCount(1);
    }

    public function test_the_engine_receives_the_message_and_a_null_conversation_id_on_the_first_turn(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Cabang mana yang paling turun?']);

        $request = $this->chatRequests()->first();

        $this->assertNotNull($request);
        $this->assertSame([
            'message' => 'Cabang mana yang paling turun?',
            'conversation_id' => null,
            'context' => [],
        ], $request->data());
    }

    public function test_the_second_turn_forwards_the_thread_conversation_id_to_the_engine(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Pertanyaan pertama']);

        $thread = ChatThread::firstOrFail();

        $this->assertSame($this->engineConversationId, $thread->fresh()->ai_conversation_id);

        $this->actingAs($this->analyst)->post(route('assistant.store'), [
            'message' => 'Pertanyaan kedua',
            'thread_id' => $thread->getKey(),
        ]);

        $requests = $this->chatRequests();

        $this->assertCount(2, $requests);
        $this->assertNull($requests->get(0)['conversation_id']);
        $this->assertSame($this->engineConversationId, $requests->get(1)['conversation_id']);
        $this->assertSame('Pertanyaan kedua', $requests->get(1)['message']);
    }

    public function test_the_assistant_message_stores_the_engine_answer(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Cabang mana yang paling turun?']);

        $this->assertDatabaseHas('chat_messages', [
            'chat_thread_id' => ChatThread::firstOrFail()->getKey(),
            'role' => 'assistant',
            'content' => $this->engineAnswer,
        ]);
    }

    public function test_the_assistant_message_stores_the_engine_evidence_and_steps(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Cabang mana yang paling turun?']);

        $message = ChatMessage::query()
            ->where('role', 'assistant')
            ->firstOrFail();

        $this->assertSame($this->engineSteps, $message->steps);
        $this->assertSame($this->engineEvidence, $message->evidence);
    }

    public function test_a_completed_turn_counts_both_messages_on_the_thread(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Pertanyaan pertama']);

        $thread = ChatThread::firstOrFail();

        $this->assertSame(2, $thread->messages()->count());
        $this->assertSame(2, $thread->fresh()->message_count);
    }

    public function test_a_second_turn_grows_the_thread_message_count(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Pertanyaan pertama']);

        $thread = ChatThread::firstOrFail();

        $this->actingAs($this->analyst)->post(route('assistant.store'), [
            'message' => 'Pertanyaan kedua',
            'thread_id' => $thread->getKey(),
        ]);

        $fresh = $thread->fresh();

        $this->assertSame(4, $fresh->messages()->count());
        $this->assertSame(4, $fresh->message_count);
    }

    public function test_a_completed_turn_stamps_the_thread_last_message_at(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 09:15:00'));

        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Pertanyaan pertama']);

        $this->assertSame(
            '2026-09-28 09:15:00',
            ChatThread::firstOrFail()->fresh()->last_message_at->toDateTimeString()
        );
    }

    public function test_a_completed_turn_stores_the_engine_conversation_id_on_the_thread(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), ['message' => 'Pertanyaan pertama']);

        $this->assertSame($this->engineConversationId, ChatThread::firstOrFail()->fresh()->ai_conversation_id);
    }

    public function test_an_engine_failure_does_not_leave_a_half_written_turn(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->from(route('assistant.index'))
            ->post(route('assistant.store'), ['message' => 'Berapa revenue minggu ini?'])
            ->assertRedirect(route('assistant.index'))
            ->assertSessionHas('error');

        $thread = ChatThread::firstOrFail();

        $this->assertSame(
            $thread->messages()->count(),
            (int) $thread->fresh()->message_count,
            'A failed engine call left messages on the thread that its counters do not describe.'
        );
    }

    public function test_posting_a_message_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('assistant.store'), ['message' => 'ApaKabur?'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // ownership / destroy
    // ------------------------------------------------------------------

    public function test_another_users_thread_is_not_readable(): void
    {
        $thread = ChatThread::factory()->forUser($this->otherAnalyst)->create();

        $this->actingAs($this->analyst)
            ->get(route('assistant.threads.show', $thread))
            ->assertNotFound();
    }

    public function test_another_users_thread_cannot_be_deleted(): void
    {
        $thread = ChatThread::factory()->forUser($this->otherAnalyst)->create();

        $this->actingAs($this->analyst)
            ->delete(route('assistant.threads.destroy', $thread))
            ->assertNotFound();

        $this->assertDatabaseHas('chat_threads', ['id' => $thread->getKey()]);
    }

    public function test_a_message_posted_against_another_users_thread_does_not_touch_that_thread(): void
    {
        $thread = ChatThread::factory()->forUser($this->otherAnalyst)->titled('Rahasia rival', 2)->create();

        $this->actingAs($this->analyst)
            ->post(route('assistant.store'), [
                'message' => 'Isi thread orang lain',
                'thread_id' => $thread->getKey(),
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('chat_messages', [
            'chat_thread_id' => $thread->getKey(),
            'content' => 'Isi thread orang lain',
        ]);
        $this->assertDatabaseCount('chat_threads', 2);
    }

    public function test_deleting_a_thread_removes_it_and_its_messages(): void
    {
        $thread = ChatThread::factory()->forUser($this->analyst)->titled('Percakapan lama', 4)->create();
        ChatMessage::factory()->count(3)->forThread($thread)->create();

        $this->actingAs($this->analyst)
            ->from(route('assistant.index'))
            ->delete(route('assistant.threads.destroy', $thread))
            ->assertRedirect(route('assistant.index'));

        $this->assertDatabaseMissing('chat_threads', ['id' => $thread->getKey()]);
        $this->assertDatabaseCount('chat_messages', 0);
    }
}
