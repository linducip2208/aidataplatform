<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Monthly AI spend ceiling on the expensive endpoints.
 */
class AiBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected float $monthSpend = 0.0;

    protected bool $engineDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = (string) strtok($request->url(), '?');

            if (str_ends_with($url, '/ai/usage/summary')) {
                return Http::response(['success' => true, 'data' => [
                    'days' => 30,
                    'totals' => [
                        'turns' => 5, 'prompt_tokens' => 1000, 'completion_tokens' => 200,
                        'total_tokens' => 1200, 'estimated_cost_total' => $this->monthSpend,
                        'currency' => 'USD', 'unpriced_rows' => 0,
                    ],
                    'by_model' => [],
                    'by_day' => [],
                ]], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_disabled_by_default(): void
    {
        config(['ai_engine.ai_monthly_budget_usd' => 0]);
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.rag.query'), ['question' => 'halo?'])
            ->assertOk();
    }

    public function test_under_budget_passes(): void
    {
        config(['ai_engine.ai_monthly_budget_usd' => 10.0]);
        $this->monthSpend = 4.5;
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.rag.query'), ['question' => 'halo?'])
            ->assertOk();
    }

    public function test_at_budget_is_refused_without_touching_the_engine_task(): void
    {
        config(['ai_engine.ai_monthly_budget_usd' => 1.0]);
        $this->monthSpend = 1.0;
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.agent.chat'), ['message' => 'halo?'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'budget_exceeded');

        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with(
            (string) strtok($request->url(), '?'), '/api/v1/ai/chat'
        ));
    }

    public function test_engine_outage_fails_open(): void
    {
        config(['ai_engine.ai_monthly_budget_usd' => 1.0]);
        $this->engineDown = true;
        $this->monthSpend = 99.0;
        Sanctum::actingAs(User::factory()->analyst()->create());

        // The budget check cannot run, so the request proceeds; the
        // controller's own engine call then fails as it always has.
        $this->postJson(route('api.rag.query'), ['question' => 'halo?'])
            ->assertStatus(503);
    }
}
