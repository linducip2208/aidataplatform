<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI cost dashboard: engine aggregation rendered as stats and tables.
 */
class AiCostTest extends TestCase
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

            return Http::response(['success' => true, 'data' => [
                'days' => 30,
                'totals' => [
                    'turns' => 12, 'prompt_tokens' => 10000, 'completion_tokens' => 2500,
                    'total_tokens' => 12500, 'estimated_cost_total' => 0.0031,
                    'currency' => 'USD', 'unpriced_rows' => 1,
                ],
                'by_model' => [
                    [
                        'model' => 'gpt-4o-mini', 'provider' => 'openrouter',
                        'turns' => 11, 'total_tokens' => 12000, 'estimated_cost_total' => 0.0031,
                    ],
                ],
                'by_day' => [
                    ['day' => '2026-09-29', 'turns' => 12, 'total_tokens' => 12500, 'estimated_cost_total' => 0.0031],
                ],
            ]], 200);
        });
    }

    public function test_web_index_renders_totals_and_breakdowns(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('ai.usage'))
            ->assertOk()
            ->assertViewIs('aicost.index')
            ->assertSee('Biaya AI')
            ->assertSee('gpt-4o-mini')
            ->assertSee('2026-09-29')
            ->assertSee('12.500');
    }

    public function test_web_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('ai.usage'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia')
            ->assertSee('Belum ada pemakaian');
    }

    public function test_web_viewer_may_read(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('ai.usage'))
            ->assertOk();
    }

    public function test_api_summary_proxies_the_engine(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->getJson(route('api.ai.usage.summary', ['days' => 7]))
            ->assertOk()
            ->assertJsonPath('data.totals.turns', 12)
            ->assertJsonPath('data.by_model.0.model', 'gpt-4o-mini');

        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first();
        $this->assertNotNull($request);
        $this->assertStringContainsString('days=7', $request->url());
    }

    public function test_api_summary_rejects_a_bad_window(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->getJson(route('api.ai.usage.summary', ['days' => 400]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }

    public function test_api_summary_requires_auth(): void
    {
        $this->getJson(route('api.ai.usage.summary'))->assertUnauthorized();
    }
}
