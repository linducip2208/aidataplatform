<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
    }

    protected function fakeKpi(array $payload = []): void
    {
        // Non-whole floats on purpose: PHP's json_encode renders 1250000.0 as
        // `1250000`, which decodes back as int and would hide a type regression.
        $data = $payload === []
            ? '{"revenue":1250000.5,"orders":412,"units":980.25,"aov":3033.25}'
            : json_encode($payload);

        Http::fake([
            '*/api/v1/analytics/kpi' => Http::response(
                '{"success":true,"data":'.$data.'}',
                200,
                ['Content-Type' => 'application/json'],
            ),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    public function test_kpi_returns_the_unwrapped_engine_payload(): void
    {
        $this->fakeKpi();
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi'))
            ->assertOk()
            ->assertJsonPath('data.revenue', 1250000.5)
            ->assertJsonPath('data.orders', 412);
    }

    public function test_kpi_forwards_the_date_and_branch_filters_to_the_engine(): void
    {
        $this->fakeKpi();
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi', [
            'date_from' => '2026-01-01',
            'branch' => 'BR-01',
        ]))->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/analytics/kpi')) {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertSame('2026-01-01', $request['date_from']);
            $this->assertSame('BR-01', $request['branch']);
            $this->assertSame('daily', $request['granularity']);
            $this->assertTrue($request->hasHeader('X-Client', 'laravel-orchestrator'));
            $this->assertTrue($request->hasHeader('X-Service-Key'));

            return true;
        });
    }

    public function test_kpi_omits_blank_filters_from_the_forwarded_body(): void
    {
        $this->fakeKpi();
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi', ['date_to' => '', 'branch' => '']))->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/analytics/kpi')) {
                return false;
            }

            $this->assertArrayNotHasKey('date_to', $request);
            $this->assertArrayNotHasKey('branch', $request);

            return true;
        });
    }

    public function test_an_engine_failure_surfaces_as_502_on_the_api(): void
    {
        Http::fake(['*/api/v1/*' => Http::response(['detail' => 'boom'], 500)]);
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.analytics.kpi'))
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    public function test_health_reports_the_engine_status(): void
    {
        // `/api/v1/health` is declared with `response_model=HealthResponse` in
        // ai-engine/app/api/v1/health.py, so it is the one endpoint that is NOT
        // wrapped in the {success, data} envelope.
        Http::fake([
            '*/api/v1/health' => Http::response(
                '{"status":"ok","app":"ai-engine","env":"prod","version":"1.0.0"}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.health'))
            ->assertOk()
            ->assertJsonPath('data.engine.status', 'ok')
            ->assertJsonPath('data.engine.version', '1.0.0');
    }

    public function test_health_degrades_gracefully_when_the_engine_is_down(): void
    {
        Http::fake(['*/api/v1/health' => Http::response([], 500)]);
        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.health'))
            ->assertOk()
            ->assertJsonPath('data.engine.status', 'unreachable');
    }

    public function test_analytics_endpoints_require_authentication(): void
    {
        $this->fakeKpi();

        $this->getJson(route('api.analytics.kpi'))->assertUnauthorized();
    }
}
