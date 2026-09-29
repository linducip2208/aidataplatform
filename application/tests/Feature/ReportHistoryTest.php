<?php

namespace Tests\Feature;

use App\Models\GeneratedReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scheduled + on-demand report history.
 */
class ReportHistoryTest extends TestCase
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

            if (str_ends_with($url, '/ai/report')) {
                return Http::response(['success' => true, 'data' => [
                    'period' => 'weekly',
                    'narrative' => 'Penjualan naik 4,2% dibanding minggu lalu.',
                    'kpi' => ['revenue' => 100.0],
                    'finance' => [],
                    'sections' => [],
                ]], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    public function test_index_still_calls_the_engine_report(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.index'))
            ->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with(
            (string) strtok($request->url(), '?'), '/api/v1/ai/report'
        ));
    }

    public function test_index_lists_history(): void
    {
        GeneratedReport::query()->create([
            'period' => 'weekly',
            'status' => 'generated',
            'payload' => ['narrative' => 'Arsip minggu lalu.'],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Riwayat laporan')
            ->assertSee('Arsip minggu lalu.');
    }

    public function test_on_demand_generation_stores_and_audits(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('reports.index'))
            ->post(route('reports.store'), ['period' => 'weekly'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('generated_reports', ['period' => 'weekly', 'status' => 'generated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generated']);
    }

    public function test_generation_validates_period(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('reports.index'))
            ->post(route('reports.store'), ['period' => 'yearly'])
            ->assertRedirect()
            ->assertSessionHasErrors('period');

        $this->assertDatabaseCount('generated_reports', 0);
    }

    public function test_viewer_cannot_generate(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('reports.store'), ['period' => 'weekly'])
            ->assertForbidden();

        $this->assertDatabaseCount('generated_reports', 0);
    }

    public function test_engine_failure_stores_nothing(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('reports.index'))
            ->post(route('reports.store'), ['period' => 'weekly'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('generated_reports', 0);
    }

    public function test_artisan_command_generates(): void
    {
        $this->artisan('report:generate', ['--period' => 'monthly'])
            ->assertSuccessful();

        $this->assertDatabaseHas('generated_reports', ['period' => 'monthly']);
    }

    public function test_artisan_command_rejects_unknown_period(): void
    {
        $this->artisan('report:generate', ['--period' => 'yearly'])
            ->assertFailed();

        $this->assertDatabaseCount('generated_reports', 0);
    }
}
