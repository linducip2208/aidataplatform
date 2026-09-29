<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RAG document ACL: Laravel maps the caller's role to the engine
 * `allow` list on every query. The caller never chooses it.
 */
class RagAclTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/v1/rag/query' => Http::response(['success' => true, 'data' => [
                'answer' => 'Jawaban.',
                'citations' => [],
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    private function allowSent(): ?string
    {
        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first(
            fn (ClientRequest $request): bool => str_contains(strtok($request->url(), '?'), '/api/v1/rag/query')
        );

        if ($request === null) {
            return null;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['allow']) ? (string) $query['allow'] : null;
    }

    public function test_viewer_is_scoped_to_public_documents(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson(route('api.rag.query'), ['question' => 'laporan?'])
            ->assertOk();

        $this->assertSame('public', $this->allowSent());
    }

    public function test_analyst_adds_internal_documents(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.rag.query'), ['question' => 'laporan?'])
            ->assertOk();

        $this->assertSame('public,internal', $this->allowSent());
    }

    public function test_admin_keeps_the_unfiltered_pinned_path(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(route('api.rag.query'), ['question' => 'laporan?'])
            ->assertOk();

        $this->assertNull($this->allowSent());
    }
}
