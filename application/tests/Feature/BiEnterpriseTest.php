<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AnalyticsController as ApiAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected bool $engineDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/api/analytics/kpi/definitions', [ApiAnalytics::class, 'kpiDefinitions'])->name('bi.definitions.index');
            Route::post('/api/analytics/kpi/definitions', [ApiAnalytics::class, 'storeKpiDefinition'])->name('bi.definitions.store');
            Route::get('/api/analytics/kpi/history', [ApiAnalytics::class, 'kpiHistory'])->name('bi.history.index');
            Route::post('/api/analytics/compare', [ApiAnalytics::class, 'compare'])->name('bi.compare');
            Route::post('/api/analytics/drilldown', [ApiAnalytics::class, 'drilldown'])->name('bi.drilldown');
            Route::post('/api/analytics/dashboards/resolve', [ApiAnalytics::class, 'dashboard'])->name('bi.dashboard');
            Route::post('/api/analytics/export', [ApiAnalytics::class, 'export'])->name('bi.export');
        });

        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            // `$request->url()` keeps the query string (`?limit=5`), so match
            // on the path only — `str_ends_with` on the full URL misses every
            // GET with query parameters.
            $url = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($url, '/api/v1/analytics/kpi/definitions')) {
                if ($request->method() === 'POST') {
                    $data = $request->data();

                    return Http::response(['success' => true, 'data' => [
                        'name' => $data['name'] ?? 'revenue',
                        'description' => $data['description'] ?? 'Total',
                        'formula' => $data['formula'] ?? 'sum(x)',
                        'unit' => $data['unit'] ?? 'IDR',
                        'target' => 100.0,
                        'warn_threshold' => 80.0,
                        'crit_threshold' => 50.0,
                        'higher_is_better' => true,
                        'is_active' => true,
                    ]], 200);
                }

                return Http::response(['success' => true, 'data' => [[
                    'name' => 'revenue',
                    'description' => 'Total net revenue',
                    'formula' => 'sum(revenue)',
                    'unit' => 'IDR',
                    'target' => 10000000.0,
                    'warn_threshold' => 8000000.0,
                    'crit_threshold' => 5000000.0,
                    'higher_is_better' => true,
                    'is_active' => true,
                ]]], 200);
            }

            if (str_ends_with($url, '/api/v1/analytics/kpi/history')) {
                return Http::response(['success' => true, 'data' => [[
                    'id' => 1,
                    'kpi_name' => 'revenue',
                    'value' => 12000000.0,
                    'target' => 10000000.0,
                    'status' => 'ok',
                    'period' => 'weekly',
                    'filters' => [],
                    'meta' => [],
                    'computed_at' => '2026-09-28T00:00:00+00:00',
                ]]], 200);
            }

            if (str_ends_with($url, '/api/v1/analytics/compare')) {
                return Http::response(['success' => true, 'data' => [
                    'kpis' => ['revenue' => ['current' => 120.0, 'previous' => 100.0, 'delta' => 20.0, 'delta_pct' => 20.0]],
                ]], 200);
            }

            if (str_ends_with($url, '/api/v1/analytics/drilldown')) {
                return Http::response(['success' => true, 'data' => [[
                    'dimension' => 'branch', 'label' => 'JKT', 'revenue' => 550.0,
                    'orders' => 3, 'units' => 5.0, 'share_pct' => 73.33,
                ]]], 200);
            }

            if (str_ends_with($url, '/api/v1/analytics/dashboards/resolve')) {
                return Http::response(['success' => true, 'data' => [
                    'dashboard' => 'executive',
                    'title' => 'Executive',
                    'description' => 'Board view',
                    'widgets' => [[
                        'id' => 'executive.kpi', 'title' => 'KPI periode',
                        'endpoint' => 'kpi', 'chart_type' => 'stat',
                        'data' => ['revenue' => 750.0],
                    ]],
                ]], 200);
            }

            if (str_ends_with($url, '/api/v1/analytics/export')) {
                return Http::response(
                    "﻿a,b\r\n1,x\r\n",
                    200,
                    ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="export.csv"']
                );
            }

            if (str_ends_with($url, '/api/v1/ai/report')) {
                return Http::response(['success' => true, 'data' => [
                    'period' => 'weekly', 'kpi' => ['revenue' => 100.0], 'finance' => [],
                    'narrative' => 'Ringkasan.', 'sections' => [], 'html' => '', 'degraded' => false,
                ]], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_kpi_definitions_proxy_passes_through(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/analytics/kpi/definitions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'revenue')
            ->assertJsonPath('data.0.unit', 'IDR');
    }

    public function test_store_definition_forwards_the_payload(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/analytics/kpi/definitions', [
            'name' => 'revenue',
            'formula' => 'sum(revenue)',
            'unit' => 'IDR',
        ])->assertCreated()->assertJsonPath('data.name', 'revenue');

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/analytics/kpi/definitions')) {
                return false;
            }

            return $request->method() === 'POST' && $request['name'] === 'revenue';
        });
    }

    public function test_store_definition_validates_the_name(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/analytics/kpi/definitions', ['name' => '1bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_history_compare_drilldown_proxies_pass_through(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->getJson('/api/analytics/kpi/history?limit=5')
            ->assertOk()
            ->assertJsonPath('data.0.kpi_name', 'revenue');

        $this->postJson('/api/analytics/compare', ['current' => [], 'previous' => []])
            ->assertOk()
            ->assertJsonPath('data.kpis.revenue.delta', 20);

        $this->postJson('/api/analytics/drilldown', ['dimension' => 'branch'])
            ->assertOk()
            ->assertJsonPath('data.0.label', 'JKT');
    }

    public function test_drilldown_rejects_an_unknown_dimension(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/analytics/drilldown', ['dimension' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dimension');
    }

    public function test_dashboard_resolve_proxy_passes_through(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson('/api/analytics/dashboards/resolve', ['dashboard' => 'executive'])
            ->assertOk()
            ->assertJsonPath('data.dashboard', 'executive')
            ->assertJsonPath('data.widgets.0.id', 'executive.kpi');
    }

    public function test_export_streams_a_download(): void
    {
        Sanctum::actingAs($this->analyst);

        $response = $this->postJson('/api/analytics/export', ['format' => 'csv', 'dataset' => 'trend']);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        // Streamed responses buffer through `streamedContent()` in tests;
        // `getContent()` stays empty for them.
        $this->assertStringContainsString('a,b', (string) $response->streamedContent());
    }

    public function test_export_rejects_pdf_before_the_engine_is_hit(): void
    {
        Sanctum::actingAs($this->analyst);

        $before = count(Http::recorded());

        $this->postJson('/api/analytics/export', ['format' => 'pdf'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');

        $this->assertCount($before, Http::recorded());
    }

    public function test_analytics_page_renders_with_bi_sections(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertViewIs('analytics.index')
            ->assertSee('Target &amp; ambang KPI', false)
            ->assertSee('Perbandingan periode')
            ->assertSee('Dasbor eksekutif')
            ->assertSee('Ekspor dataset');
    }

    public function test_reports_page_lists_automated_snapshots_with_download_links(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Laporan otomatis')
            ->assertSee('revenue')
            ->assertSee('Unduh snapshot (CSV)');
    }

    public function test_engine_down_fallbacks_render_instead_of_failing(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertViewHas('engineAvailable', false)
            ->assertSee('Mesin AI tidak tersedia');

        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewHas('engineAvailable', false)
            ->assertSee('Mesin AI tidak tersedia');
    }

    public function test_bi_api_requires_authentication(): void
    {
        $this->getJson('/api/analytics/kpi/definitions')->assertUnauthorized();
        $this->postJson('/api/analytics/compare', [])->assertUnauthorized();
    }
}
