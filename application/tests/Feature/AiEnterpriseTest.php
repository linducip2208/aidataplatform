<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\RagController;
use App\Http\Controllers\AssistantController;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Enterprise AI: template / hybrid / rerank pass-through, usage ledger proxy
 * and persistence, destructive-SQL refusal surfacing.
 *
 * Self-contained by design: the routes below mirror the real
 * `/api/agent/chat`, `/api/rag/query` and assistant web routes (wired by
 * master at integration) without touching `routes/*.php`, so this file never
 * conflicts with another agent's wiring. Engine answers are faked at the
 * `Http` layer; assertions target the wire bodies Laravel sends and the rows
 * it persists.
 */
class AiEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected bool $engineDown = false;

    /**
     * Per-test engine answer override. Read at request time, not at
     * fake-registration time: stub callbacks are first-registered-wins, so a
     * later `Http::fake()` call could never override the closure registered
     * in `setUp()`.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $forcedChatResult = null;

    /** @var array<string, mixed> */
    protected array $engineUsage = [
        'recorded' => true,
        'conversation_id' => 43,
        'model' => 'gpt-4o-mini',
        'provider' => 'openai-compatible',
        'prompt_tokens' => 1000,
        'completion_tokens' => 250,
        'total_tokens' => 1250,
        'estimated_cost' => 0.0003,
        'currency' => 'USD',
        'cost_note' => '',
        'estimated' => false,
    ];

    protected string $refusalAnswer = 'Kueri SQL ditolak oleh guardrail dan TIDAK dijalankan (refused:blocked_table — table(s) not in the read allowlist: users).';

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();

        // Mirrors of the real surface; master wires the production URIs.
        Route::post('/_enterprise/agent/chat', [AgentController::class, 'store']);
        Route::post('/_enterprise/rag/query', [RagController::class, 'query']);
        Route::get('/_enterprise/ai/usage', [AgentController::class, 'usage']);
        Route::post('/_enterprise/assistant/threads', [AssistantController::class, 'store'])
            ->middleware(['web', 'auth', 'role:admin,analyst']);

        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = $request->url();

            $payload = match (true) {
                str_ends_with($url, '/api/v1/ai/chat') => $this->forcedChatResult ?? $this->chatResult(),
                str_ends_with($url, '/api/v1/rag/query') => $this->ragResult(),
                str_ends_with($url, '/api/v1/ai/usage') => $this->usageResult(),
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** @return array<string, mixed> */
    protected function chatResult(): array
    {
        return [
            'answer' => 'Ringkasan: revenue 1250000.25 dari 412 pesanan.',
            'conversation_id' => 43,
            'evidence' => [['source' => 'fact_sales', 'data' => ['revenue' => 1250000.25]]],
            'steps' => 2,
            'confidence' => 0.9,
            'limitations' => [],
            'usage' => $this->engineUsage,
            'template' => 'assistant.v2',
        ];
    }

    /** @return array<string, mixed> */
    protected function ragResult(): array
    {
        return [
            'answer' => 'Refund maksimal 14 hari.',
            'citations' => [[
                'chunk_id' => 9, 'document_id' => 5, 'chunk_index' => 1,
                'source' => 'handbook.pdf', 'title' => 'Panduan refund',
                'score' => 0.87, 'char_start' => 0, 'char_end' => 6,
            ]],
            'confidence' => 0.72,
            'limitations' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected function usageResult(): array
    {
        return [
            'usage' => [[
                'id' => 1, 'conversation_id' => 43, 'model' => 'gpt-4o-mini',
                'provider' => 'openai-compatible', 'prompt_tokens' => 1000,
                'completion_tokens' => 250, 'total_tokens' => 1250,
                'estimated_cost' => 0.0003, 'currency' => 'USD', 'cost_note' => '',
                'created_at' => '2026-09-29T00:00:00+00:00',
            ]],
            'totals' => [
                'turns' => 1, 'prompt_tokens' => 1000, 'completion_tokens' => 250,
                'total_tokens' => 1250, 'estimated_cost_total' => 0.0003,
                'currency' => 'USD', 'unpriced_rows' => 0,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // agent chat: template pass-through, byte-identical default
    // ------------------------------------------------------------------

    public function test_the_chat_response_carries_exactly_the_documented_keys(): void
    {
        $data = $this->actingAs($this->analyst)
            ->postJson('/_enterprise/agent/chat', ['message' => 'Bagaimana margin Q1?'])
            ->assertOk()
            ->json('data');

        $keys = array_keys($data);
        sort($keys);
        $this->assertSame(['answer', 'conversation_id', 'evidence', 'reply', 'steps'], $keys);
    }

    public function test_the_template_reaches_the_engine_inside_context(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/agent/chat', ['message' => 'Halo', 'template' => 'assistant.v2'])
            ->assertOk();

        $request = Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/api/v1/ai/chat'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->first();

        $this->assertNotNull($request);
        $this->assertSame('Halo', $request['message']);
        $this->assertSame(['template' => 'assistant.v2'], $request['context']);
    }

    public function test_the_default_chat_body_is_unchanged_when_no_template_is_sent(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/agent/chat', ['message' => 'Halo'])
            ->assertOk();

        $request = Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/api/v1/ai/chat'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->first();

        $this->assertNotNull($request);
        $this->assertSame(
            ['message' => 'Halo', 'conversation_id' => null, 'context' => []],
            $request->data()
        );
    }

    public function test_an_over_long_template_is_a_422_keyed_by_template(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/agent/chat', ['message' => 'Halo', 'template' => str_repeat('v', 65)])
            ->assertStatus(422)
            ->assertJsonPath('errors.template.0', fn ($message): bool => is_string($message) && $message !== '');

        Http::assertNothingSent();
    }

    public function test_a_destructive_sql_refusal_surfaces_as_the_answer_with_its_evidence(): void
    {
        $this->forcedChatResult = [
            'answer' => $this->refusalAnswer,
            'conversation_id' => null,
            'evidence' => [[
                'source' => 'sql',
                'data' => ['error' => $this->refusalAnswer, 'reason' => 'refused:blocked_table', 'empty' => true],
            ]],
            'steps' => 1,
        ];

        $data = $this->actingAs($this->analyst)
            ->postJson('/_enterprise/agent/chat', ['message' => 'SELECT * FROM users'])
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('ditolak oleh guardrail', $data['answer']);
        $this->assertStringContainsString('TIDAK dijalankan', $data['answer']);
        $this->assertSame('refused:blocked_table', $data['evidence'][0]['data']['reason']);
    }

    // ------------------------------------------------------------------
    // rag query: hybrid / rerank pass-through, byte-identical default
    // ------------------------------------------------------------------

    public function test_the_rag_response_carries_answer_and_citations_with_offsets(): void
    {
        $data = $this->actingAs($this->analyst)
            ->postJson('/_enterprise/rag/query', ['question' => 'Berapa lama refund?', 'top_k' => 5])
            ->assertOk()
            ->json('data');

        $keys = array_keys($data);
        sort($keys);
        $this->assertSame(['answer', 'citations'], $keys);
        $this->assertSame('Refund maksimal 14 hari.', $data['answer']);
        $this->assertSame(9, $data['citations'][0]['chunk_id']);
        $this->assertSame(0, $data['citations'][0]['char_start']);
        $this->assertSame(6, $data['citations'][0]['char_end']);
    }

    public function test_hybrid_and_rerank_flags_reach_the_engine_as_query_params(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/rag/query', [
                'question' => 'Berapa lama refund?', 'hybrid' => false, 'rerank' => true,
            ])
            ->assertOk();

        $request = Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/api/v1/rag/query'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->first();

        $this->assertNotNull($request);
        $this->assertStringContainsString('hybrid=false', $request->url());
        $this->assertStringContainsString('rerank=true', $request->url());
        $this->assertSame('Berapa lama refund?', $request['query']);
    }

    public function test_the_default_rag_body_is_unchanged_when_no_flags_are_sent(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/rag/query', ['question' => 'Berapa lama refund?'])
            ->assertOk();

        $request = Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/api/v1/rag/query'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->first();

        $this->assertNotNull($request);
        $this->assertSame(['query' => 'Berapa lama refund?', 'top_k' => 5], $request->data());
        $this->assertStringNotContainsString('hybrid', $request->url());
    }

    public function test_a_non_boolean_hybrid_is_a_422_and_never_reaches_the_engine(): void
    {
        $this->actingAs($this->analyst)
            ->postJson('/_enterprise/rag/query', ['question' => 'q', 'hybrid' => 'kadang'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hybrid');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // usage proxy + persistence on the web thread
    // ------------------------------------------------------------------

    public function test_the_usage_proxy_returns_rows_and_totals(): void
    {
        $data = $this->actingAs($this->analyst)
            ->getJson('/_enterprise/ai/usage')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['totals']['turns']);
        $this->assertSame(1250, $data['totals']['total_tokens']);
        $this->assertSame(0.0003, $data['totals']['estimated_cost_total']);
        $this->assertSame('gpt-4o-mini', $data['usage'][0]['model']);
    }

    public function test_the_usage_proxy_scopes_by_conversation_id(): void
    {
        ChatThread::factory()->forUser($this->analyst)->create(['ai_conversation_id' => 43]);

        $this->actingAs($this->analyst)
            ->getJson('/_enterprise/ai/usage?conversation_id=43')
            ->assertOk();

        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first();

        $this->assertNotNull($request);
        $this->assertStringContainsString('conversation_id=43', $request->url());
    }

    public function test_the_usage_proxy_rejects_a_foreign_conversation_id(): void
    {
        $other = User::factory()->analyst()->create();
        ChatThread::factory()->forUser($other)->create(['ai_conversation_id' => 44]);

        $this->actingAs($this->analyst)
            ->getJson('/_enterprise/ai/usage?conversation_id=44')
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');

        Http::assertNothingSent();
    }

    public function test_an_unreachable_engine_on_the_usage_proxy_is_a_503(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->getJson('/_enterprise/ai/usage')
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    public function test_the_assistant_turn_persists_the_usage_summary_on_message_meta(): void
    {
        $this->actingAs($this->analyst)
            ->post('/_enterprise/assistant/threads', [
                'message' => 'Ringkasan kpi minggu ini',
                'template' => 'assistant.v2',
            ])
            ->assertRedirect();

        $message = ChatMessage::query()->where('role', 'assistant')->firstOrFail();

        // Evidence keeps the exact engine shape; usage lives on `meta`.
        $this->assertSame(
            [['source' => 'fact_sales', 'data' => ['revenue' => 1250000.25]]],
            $message->evidence
        );
        $this->assertSame($this->engineUsage, json_decode((string) $message->meta, true));

        // The template travelled inside context, not as a top-level engine key.
        $request = Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/api/v1/ai/chat'))
            ->map(fn (array $pair): ClientRequest => $pair[0])
            ->first();

        $this->assertNotNull($request);
        $this->assertSame(['template' => 'assistant.v2'], $request['context']);
    }

    public function test_a_turn_without_engine_usage_leaves_meta_empty(): void
    {
        $this->forcedChatResult = [
            'answer' => 'ok', 'conversation_id' => 1, 'evidence' => [], 'steps' => 1,
        ];

        $this->actingAs($this->analyst)
            ->post('/_enterprise/assistant/threads', ['message' => 'Halo'])
            ->assertRedirect();

        $message = ChatMessage::query()->where('role', 'assistant')->firstOrFail();
        $this->assertNull($message->meta);
    }

    public function test_posting_as_a_viewer_is_forbidden_on_the_mirrored_web_route(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post('/_enterprise/assistant/threads', ['message' => 'Halo'])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, ChatThread::query()->count());
    }
}
