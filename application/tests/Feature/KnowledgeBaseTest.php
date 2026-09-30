<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Knowledge base + glossary pages: engine lists rendered with Tabler.
 */
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected bool $engineDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = (string) strtok($request->url(), '?');

            if (str_ends_with($url, '/rag/documents')) {
                return Http::response(['success' => true, 'data' => [
                    [
                        'id' => 5, 'title' => 'Panduan refund', 'source' => 'web',
                        'doc_type' => 'txt', 'visibility' => 'internal',
                        'owner' => null, 'n_chunks' => 3,
                    ],
                ]], 200);
            }

            if (str_ends_with($url, '/rag/ingest')) {
                return Http::response(['success' => true, 'data' => [
                    'document_id' => 6, 'n_chunks' => 2, 'status' => 'created',
                ]], 200);
            }

            if (str_ends_with($url, '/semantic/metrics')) {
                return Http::response(['success' => true, 'data' => [
                    'version' => 'v1',
                    'metrics' => [
                        [
                            'name' => 'revenue', 'definition' => 'Total pendapatan.',
                            'formula' => 'SUM(fact_sales.revenue)',
                            'source' => 'sales_kpi', 'aliases' => ['revenue', 'pendapatan'],
                        ],
                    ],
                ]], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_knowledge_index_renders_documents(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertViewIs('knowledge.index')
            ->assertSee('Basis pengetahuan')
            ->assertSee('Panduan refund')
            ->assertSee('Internal');
    }

    public function test_knowledge_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia')
            ->assertSee('Belum ada dokumen');
    }

    public function test_knowledge_store_indexes_with_visibility(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('knowledge.index'))
            ->post(route('knowledge.store'), [
                'title' => 'Panduan baru',
                'content' => 'Isi panduan yang cukup panjang.',
                'visibility' => 'private',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first(
            fn (ClientRequest $request): bool => str_ends_with((string) strtok($request->url(), '?'), '/rag/ingest')
        );

        $this->assertNotNull($request);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('private', $query['visibility'] ?? null);
        $this->assertNotEmpty($query['owner'] ?? null);
    }

    public function test_knowledge_store_validates_and_gates_roles(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('knowledge.index'))
            ->post(route('knowledge.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['title', 'content']);

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('knowledge.store'), ['title' => 'x', 'content' => 'y'])
            ->assertForbidden();
    }

    public function test_glossary_index_renders_definitions(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('glossary.index'))
            ->assertOk()
            ->assertViewIs('glossary.index')
            ->assertSee('Glosarium bisnis')
            ->assertSee('revenue')
            ->assertSee('SUM(fact_sales.revenue)');
    }

    public function test_glossary_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('glossary.index'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia')
            ->assertSee('Belum ada definisi');
    }
}
