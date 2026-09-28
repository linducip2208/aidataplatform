<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    /**
     * Read at request time, not at fake-registration time: stub callbacks are
     * matched first-registered-wins, so a later `Http::fake()` call could never
     * override the single closure registered in `setUp()`. Per-test variation
     * has to go through state.
     */
    protected bool $engineDown = false;

    /** @var array<string, mixed> */
    protected array $reportPayload = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->reportPayload = $this->executiveSummary();
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

            return Http::response(['success' => true, 'data' => $this->reportPayload], 200);
        });
    }

    /**
     * `POST /api/v1/ai/report` -> `app/ai/reporting.py::executive_summary`.
     *
     * @return array<string, mixed>
     */
    protected function executiveSummary(string $narrative = 'Penjualan naik 4,2% dibanding minggu lalu.'): array
    {
        return [
            'period' => 'weekly',
            'kpi' => [
                'revenue' => 1250000.0,
                'orders' => 412,
                'units' => 980.25,
                'aov' => 3033.25,
                'growth_pct' => 4.2,
                'margin_pct' => 18.5,
            ],
            'finance' => [
                'revenue' => 1250000.0,
                'cogs' => 812000.0,
                'opex' => 260000.0,
                'net_profit' => 178000.0,
                'margin_pct' => 14.24,
            ],
            'narrative' => $narrative,
            'sections' => [
                'highlight' => ['Pendapatan naik 4,2%.'],
                'risiko' => ['Margin turun 1,1 poin.'],
                'rekomendasi' => ['Tinjau harga di BR-03.'],
            ],
            'html' => '<!DOCTYPE html><html lang="id"></html>',
            'degraded' => false,
        ];
    }

    // ------------------------------------------------------------------
    // period handling
    // ------------------------------------------------------------------

    public function test_the_index_defaults_to_the_weekly_period(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index')
            ->assertViewHas('period', 'weekly')
            ->assertViewHas('engineAvailable', true);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/ai/report')
            && $r->data() === ['period' => 'weekly', 'branch' => null, 'format' => 'json']);
    }

    public function test_the_index_accepts_the_daily_period(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('reports.index', ['period' => 'daily']))
            ->assertOk()
            ->assertViewHas('period', 'daily');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/ai/report')
            && $r['period'] === 'daily');
    }

    public function test_the_index_accepts_the_monthly_period(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('reports.index', ['period' => 'monthly']))
            ->assertOk()
            ->assertViewHas('period', 'monthly');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/ai/report')
            && $r['period'] === 'monthly');
    }

    public function test_the_index_rejects_a_period_outside_the_three_supported_ones(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('reports.index'))
            ->get(route('reports.index', ['period' => 'yearly']))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasErrors('period');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // engine availability
    // ------------------------------------------------------------------

    public function test_an_unavailable_engine_renders_an_empty_report_instead_of_failing(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index')
            ->assertViewHas('engineAvailable', false)
            ->assertViewHas('report', []);
    }

    public function test_the_unavailable_banner_is_rendered_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia');
    }

    // ------------------------------------------------------------------
    // payload tolerance
    // ------------------------------------------------------------------

    public function test_a_partial_report_payload_still_renders(): void
    {
        $this->reportPayload = ['period' => 'weekly', 'narrative' => 'Ringkasan singkat.'];

        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewHas('engineAvailable', true)
            ->assertViewHas('report', $this->reportPayload)
            ->assertSee('Ringkasan singkat.');
    }

    public function test_an_empty_report_payload_still_renders(): void
    {
        $this->reportPayload = [];

        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewHas('report', [])
            ->assertSee('Laporan belum tersedia');
    }

    public function test_the_engine_narrative_is_rendered_on_the_page(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Penjualan naik 4,2% dibanding minggu lalu.')
            ->assertSee('Pendapatan naik 4,2%.');
    }

    // ------------------------------------------------------------------
    // escaping
    // ------------------------------------------------------------------

    public function test_the_llm_narrative_is_escaped_in_the_page(): void
    {
        $this->reportPayload = $this->executiveSummary('<script>alert(1)</script>');

        $response = $this->actingAs($this->analyst)->get(route('reports.index'))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_report_section_items_are_escaped_in_the_page(): void
    {
        $this->reportPayload = $this->executiveSummary();
        $this->reportPayload['sections']['highlight'] = ['<img src=x onerror=alert(1)>'];

        $response = $this->actingAs($this->analyst)->get(route('reports.index'))->assertOk();

        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }
}
