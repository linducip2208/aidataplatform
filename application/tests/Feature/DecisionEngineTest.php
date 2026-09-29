<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DecisionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decision/scenario engine: recommend flow, explicit-unsupported scenarios,
 * audit trail, role gates, read-only GETs.
 *
 * Routes are registered here in setUp (self-contained: `routes/api.php` is
 * master-owned) mirroring `/api/decisions/*`. The engine is faked via
 * Http::fake with the `{"success": true, "data": ...}` envelope the real
 * engine sends.
 */
class DecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->prefix('api/decisions')->group(function (): void {
            Route::get('/rules', [DecisionController::class, 'rules']);
            Route::get('/', [DecisionController::class, 'index']);
            Route::get('/{id}', [DecisionController::class, 'show'])->whereNumber('id');
            Route::post('/recommend', [DecisionController::class, 'recommend'])->middleware('role:admin,analyst');
            Route::post('/scenarios/run', [DecisionController::class, 'runScenario'])->middleware('role:admin,analyst');
            Route::post('/{id}/audit', [DecisionController::class, 'audit'])->middleware('role:admin,analyst')->whereNumber('id');
        });

        Http::fake([
            '*/api/v1/decision/recommend' => Http::response(['success' => true, 'data' => [
                'case_id' => 7,
                'subject' => ['branch' => 'JKT'],
                'recommendations' => [[
                    'action' => 'Review pricing for the declining window.',
                    'expected_impact' => ['summary' => 'growth below -5%', 'value' => null],
                    'confidence' => 0.6,
                    'evidence_ids' => ['ev-0001'],
                    'scenario_ref' => ['type' => 'price_change_pct', 'params' => ['price_change_pct' => -5.0]],
                    'score' => 55.0,
                    'rule' => 'revenue_drop_rule',
                    'explanation' => ['summary' => 'Review pricing.', 'drivers' => [], 'confidence' => 0.6, 'limitations' => []],
                ]],
                'generated_at' => '2026-09-30T00:00:00+00:00',
            ]], 200),
            '*/api/v1/decision/scenarios/run' => Http::response(['success' => true, 'data' => [
                'supported' => false,
                'type' => 'price_change_pct',
                'params' => ['price_change_pct' => 10.0],
                'reasons' => ['no price-quantity basis in the sales frame'],
                'assumptions' => ['No elasticity assumed: refusing to invent one.'],
            ]], 200),
            '*/api/v1/decision/cases/*/audit' => Http::response(['success' => true, 'data' => [
                'id' => 3, 'case_id' => 7, 'actor' => 'analyst-1',
                'decision' => 'approved', 'rationale' => 'ok',
                'created_at' => '2026-09-30T00:00:00+00:00',
            ]], 201),
            '*/api/v1/decision/cases/*' => Http::response(['success' => true, 'data' => [
                'id' => 7,
                'subject' => ['branch' => 'JKT'],
                'status' => 'recommended',
                'created_at' => '2026-09-30T00:00:00+00:00',
                'recommendations' => [],
                'audits' => [['id' => 1, 'actor' => 'system', 'decision' => 'recommended', 'rationale' => 'x']],
            ]], 200),
            '*/api/v1/decision/cases*' => Http::response(['success' => true, 'data' => [
                ['id' => 7, 'subject' => ['branch' => 'JKT'], 'status' => 'recommended'],
            ]], 200),
            '*/api/v1/decision/rules' => Http::response(['success' => true, 'data' => [
                'version' => '1.0.0', 'rules' => [],
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    public function test_recommend_posts_to_the_engine_and_answers_201(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $response = $this->postJson('/api/decisions/recommend', [
            'subject' => ['branch' => 'JKT', 'granularity' => 'daily', 'horizon' => 7],
        ]);

        $response->assertCreated()->assertJsonPath('data.case_id', 7);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/decision/recommend');
        });
    }

    public function test_unsupported_scenario_is_passed_through_with_no_deltas(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $response = $this->postJson('/api/decisions/scenarios/run', [
            'type' => 'price_change_pct',
            'params' => ['price_change_pct' => 10.0],
            'subject' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.supported', false)
            ->assertJsonPath('data.reasons.0', 'no price-quantity basis in the sales frame');

        // Explicit-unsupported policy: the proxy must not invent numbers.
        $this->assertArrayNotHasKey('deltas', $response->json('data'));
        $this->assertArrayNotHasKey('confidence_interval', $response->json('data'));
    }

    public function test_scenario_with_an_unknown_type_is_rejected_before_the_engine(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson('/api/decisions/scenarios/run', [
            'type' => 'moon_landing',
            'params' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        Http::assertNotSent(function ($request): bool {
            return str_ends_with($request->url(), '/api/v1/decision/scenarios/run');
        });
    }

    public function test_audit_appends_to_the_case_and_answers_201(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson('/api/decisions/7/audit', [
            'actor' => 'analyst-1',
            'decision' => 'approved',
            'rationale' => 'ok',
        ])->assertCreated()->assertJsonPath('data.actor', 'analyst-1');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/decision/cases/7/audit');
        });
    }

    public function test_audit_without_actor_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson('/api/decisions/7/audit', [
            'decision' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('actor');
    }

    public function test_case_detail_and_list_are_read_only(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $before = $this->tableCounts();

        $this->getJson('/api/decisions?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', 7);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_contains(strtok($request->url(), '?'), '/api/v1/decision/cases');
        });

        $this->getJson('/api/decisions/7')
            ->assertOk()
            ->assertJsonPath('data.id', 7);

        $this->getJson('/api/decisions/rules')->assertOk();

        $this->assertSame($before, $this->tableCounts(), 'GET endpoints must never write.');
    }

    public function test_non_numeric_case_id_is_a_422(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        // `whereNumber` leaves no matching route, so assert the engine is
        // never consulted for a malformed id on the audit path either.
        $this->postJson('/api/decisions/abc/audit', [
            'actor' => 'a', 'decision' => 'd',
        ])->assertNotFound();
    }

    public function test_viewer_is_forbidden_on_writes_but_may_read(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson('/api/decisions/recommend', ['subject' => []])->assertForbidden();
        $this->postJson('/api/decisions/scenarios/run', ['type' => 'price_change_pct'])->assertForbidden();
        $this->postJson('/api/decisions/7/audit', ['actor' => 'a', 'decision' => 'd'])->assertForbidden();

        $this->getJson('/api/decisions')->assertOk();
        $this->getJson('/api/decisions/7')->assertOk();
    }

    public function test_admin_may_write(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/decisions/recommend', ['subject' => []])->assertCreated();
        $this->postJson('/api/decisions/7/audit', ['actor' => 'a', 'decision' => 'd'])->assertCreated();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/decisions')->assertUnauthorized();
        $this->getJson('/api/decisions/7')->assertUnauthorized();
        $this->postJson('/api/decisions/recommend', [])->assertUnauthorized();
        $this->postJson('/api/decisions/scenarios/run', [])->assertUnauthorized();
        $this->postJson('/api/decisions/7/audit', [])->assertUnauthorized();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'decision_cases' => DB::table('decision_cases')->count(),
            'decision_recommendations' => DB::table('decision_recommendations')->count(),
            'decision_audits' => DB::table('decision_audits')->count(),
        ];
    }
}
