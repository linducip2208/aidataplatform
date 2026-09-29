<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trend chart: server-rendered SVG from real trend rows, no JS dependency.
 */
class TrendChartTest extends TestCase
{
    use RefreshDatabase;

    protected bool $trendEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Closure stub read at request time: a second Http::fake() could
        // never override the map registered here (first-registered-wins).
        Http::fake(function (ClientRequest $request) {
            $url = (string) strtok($request->url(), '?');

            if (str_ends_with($url, '/api/v1/analytics/trend') && ! $this->trendEmpty) {
                return Http::response(['success' => true, 'data' => [
                    ['period' => '2026-09-01', 'revenue' => 402000000, 'orders' => 1104, 'units' => 6210],
                    ['period' => '2026-09-02', 'revenue' => 438500000, 'orders' => 1189, 'units' => 6742],
                ]], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_trend_chart_renders_svg_from_real_rows(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('analytics.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Grafik tren pendapatan', $html);
        $this->assertStringContainsString('2026-09-01', $html);
        $this->assertStringContainsString('2026-09-02', $html);
        // One data point per trend row.
        $this->assertSame(2, substr_count($html, '<circle'));
    }

    public function test_empty_trend_renders_no_chart(): void
    {
        $this->trendEmpty = true;

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('analytics.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Belum ada data tren', $html);
        $this->assertStringNotContainsString('Grafik tren pendapatan', $html);
    }
}
