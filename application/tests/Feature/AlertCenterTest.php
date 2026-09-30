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
 * Alert center: engine-backed lists, acknowledge, rule CRUD, role gates.
 *
 * The engine is faked at the Http layer with the `{success, data}` envelope;
 * assertions target what Laravel renders/sends, never invented engine data.
 */
class AlertCenterTest extends TestCase
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

            $payload = match (true) {
                str_ends_with($url, '/alerts/alerts') => [
                    [
                        'id' => 7,
                        'rule_id' => 3,
                        'severity' => 'high',
                        'message' => 'Pendapatan turun di bawah ambang',
                        'status' => 'open',
                        'triggered_at' => '2026-09-29T01:15:00+07:00',
                    ],
                ],
                str_ends_with($url, '/alerts/rules') && $request->method() === 'POST' => [
                    'id' => 4, 'name' => 'Aturan baru', 'metric' => 'sales.revenue',
                    'operator' => '<', 'threshold' => 100.0, 'is_active' => true,
                ],
                str_contains($url, '/alerts/rules') => [
                    [
                        'id' => 3, 'name' => 'Pendapatan turun', 'metric' => 'sales.revenue',
                        'operator' => '<', 'threshold' => 100.0, 'is_active' => true,
                    ],
                ],
                str_ends_with($url, '/alerts/7/ack') => [
                    'id' => 7, 'status' => 'acknowledged',
                ],
                str_ends_with($url, '/alerts/metrics') => [
                    'metrics' => [['name' => 'sales.revenue']],
                    'statuses' => ['open', 'acknowledged', 'resolved'],
                ],
                default => [],
            };

            $status = str_ends_with($url, '/alerts/rules') && $request->method() === 'POST' ? 201 : 200;

            return Http::response(['success' => true, 'data' => $payload], $status);
        });
    }

    public function test_web_index_renders_alerts_and_rules(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertViewIs('alerts.index')
            ->assertSee('Pusat peringatan')
            ->assertSee('Pendapatan turun di bawah ambang')
            ->assertSee('Pendapatan turun')
            ->assertSee('Tinggi');
    }

    public function test_web_index_degrades_when_the_engine_is_down(): void
    {
        $this->engineDown = true;

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Mesin AI tidak tersedia')
            ->assertSee('Belum ada peringatan');
    }

    public function test_web_acknowledge_marks_the_alert_read(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('alerts.index'))
            ->post(route('alerts.ack', ['id' => 7]))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/alerts/7/ack'));
    }

    public function test_web_viewer_cannot_acknowledge_or_manage_rules(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('alerts.ack', ['id' => 7]))
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('alerts.rules.store'), [
                'name' => 'x', 'metric' => 'sales.revenue', 'operator' => '<', 'threshold' => 1,
            ])
            ->assertForbidden();
    }

    public function test_web_rule_toggle_flips_active_flag(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('alerts.index'))
            ->post(route('alerts.rules.toggle', ['id' => 3]), ['is_active' => false])
            ->assertRedirect()
            ->assertSessionHas('status');

        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first(
            fn (ClientRequest $request): bool => $request->method() === 'PATCH'
                && str_ends_with((string) strtok($request->url(), '?'), '/alerts/rules/3')
        );

        $this->assertNotNull($request);
        $this->assertFalse($request->data()['is_active']);
    }

    public function test_web_rule_creation_validates_its_payload(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->from(route('alerts.index'))
            ->post(route('alerts.rules.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['name', 'metric', 'operator', 'threshold']);
    }

    public function test_api_lists_alerts_and_rules(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->getJson(route('api.alerts.index'))
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Pendapatan turun di bawah ambang');

        $this->getJson(route('api.alerts.rules'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Pendapatan turun');
    }

    public function test_api_acknowledge_and_rule_writes(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.alerts.ack', ['id' => 7]))
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $this->postJson(route('api.alerts.rules.store'), [
            'name' => 'Aturan baru', 'metric' => 'sales.revenue', 'operator' => '<', 'threshold' => 100,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Aturan baru');
    }

    public function test_api_viewer_is_forbidden_on_writes(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson(route('api.alerts.ack', ['id' => 7]))->assertForbidden();
        $this->postJson(route('api.alerts.rules.store'), [])->assertForbidden();
    }

    public function test_api_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(route('api.alerts.index'))->assertUnauthorized();
        $this->postJson(route('api.alerts.ack', ['id' => 7]))->assertUnauthorized();
    }
}
