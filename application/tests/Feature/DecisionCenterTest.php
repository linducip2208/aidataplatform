<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Decision Center: cases, recommendations, scenarios, human audits.
 */
class DecisionCenterTest extends TestCase
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

            if (str_ends_with($url, '/decision/cases') && $request->method() === 'GET') {
                return Http::response(['success' => true, 'data' => [
                    ['id' => 7, 'subject' => ['branch' => 'BR-01'], 'status' => 'recommended', 'created_at' => '2026-09-29T00:00:00+00:00'],
                ]], 200);
            }

            if (str_ends_with($url, '/decision/cases/7')) {
                return Http::response(['success' => true, 'data' => [
                    'id' => 7,
                    'subject' => ['branch' => 'BR-01'],
                    'status' => 'recommended',
                    'created_at' => '2026-09-29T00:00:00+00:00',
                    'recommendations' => [
                        [
                            'id' => 1, 'action' => 'Tinjau harga BR-01',
                            'impact' => [], 'confidence' => 0.6, 'evidence' => [],
                            'explanation' => ['summary' => 'Penurunan terdeteksi.'],
                            'score' => 55.0, 'rule' => 'revenue_drop',
                        ],
                    ],
                    'audits' => [
                        ['id' => 1, 'actor' => 'system', 'decision' => 'recommended', 'rationale' => 'r', 'created_at' => '2026-09-29T00:00:00+00:00'],
                    ],
                ]], 200);
            }

            if (str_ends_with($url, '/decision/recommend')) {
                return Http::response(['success' => true, 'data' => [
                    'case_id' => 7, 'subject' => [], 'recommendations' => [],
                    'evidence' => [], 'generated_at' => '2026-09-29T00:00:00+00:00',
                ]], 201);
            }

            if (str_ends_with($url, '/decision/scenarios/run')) {
                return Http::response(['success' => true, 'data' => [
                    'supported' => true,
                    'deltas' => ['baseline_revenue' => 100.0, 'scenario_revenue' => 95.0],
                ]], 200);
            }

            if (str_ends_with($url, '/decision/cases/7/audit')) {
                return Http::response(['success' => true, 'data' => [
                    'id' => 9, 'case_id' => 7, 'actor' => 'Analis', 'decision' => 'setuju', 'rationale' => '',
                ]], 201);
            }

            if (str_ends_with($url, '/decision/rules')) {
                return Http::response(['success' => true, 'data' => []], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_index_renders_cases(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('decisions.index'))
            ->assertOk()
            ->assertViewIs('decisions.index')
            ->assertSee('Pusat keputusan')
            ->assertSee('Minta rekomendasi')
            ->assertSee('BR-01');
    }

    public function test_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('decisions.index'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia')
            ->assertSee('Belum ada kasus keputusan');
    }

    public function test_show_renders_recommendations_and_audits(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('decisions.show', ['id' => 7]))
            ->assertOk()
            ->assertViewIs('decisions.show')
            ->assertSee('Tinjau harga BR-01')
            ->assertSee('revenue_drop')
            ->assertSee('Catat keputusan');
    }

    public function test_recommend_redirects_to_the_new_case(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('decisions.index'))
            ->post(route('decisions.recommend'), ['branch' => 'BR-01'])
            ->assertRedirect(route('decisions.show', ['id' => 7]))
            ->assertSessionHas('status');
    }

    public function test_scenario_result_is_shown_inline(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('decisions.index'))
            ->post(route('decisions.scenarios.run'), ['type' => 'price_change_pct', 'value' => -5])
            ->assertRedirect()
            ->assertSessionHas('scenario_result');

        $this->actingAs(User::factory()->analyst()->create())
            ->withSession(['scenario_result' => ['supported' => true, 'deltas' => ['baseline_revenue' => 100.0]]])
            ->get(route('decisions.index'))
            ->assertOk()
            ->assertSee('Hasil skenario')
            ->assertSee('baseline_revenue');
    }

    public function test_scenario_validates_its_payload(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('decisions.index'))
            ->post(route('decisions.scenarios.run'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['type', 'value']);
    }

    public function test_audit_records_the_human_decision(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('decisions.show', ['id' => 7]))
            ->post(route('decisions.audit', ['id' => 7]), ['decision' => 'setuju'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', ['action' => 'decision.audited']);
    }

    public function test_viewer_cannot_write(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->post(route('decisions.recommend'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('decisions.scenarios.run'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('decisions.audit', ['id' => 7]), [])->assertForbidden();
        $this->actingAs($viewer)->get(route('decisions.index'))->assertOk();
    }
}
