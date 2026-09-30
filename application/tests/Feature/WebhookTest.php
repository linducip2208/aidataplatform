<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound webhooks: HMAC-signed fan-out with SSRF guard, retry and replay.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_crud_and_secret_shown_once(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.webhooks.index'))
            // 198.51.100.0/24 is documentation space: public, so the SSRF
            // guard passes without DNS or network (the send itself is faked).
            ->post(route('admin.webhooks.store'), [
                'name' => 'Tim',
                'url' => 'https://198.51.100.7/hook',
                'events' => ['dataset.committed'],
            ])
            ->assertRedirect(route('admin.webhooks.index'))
            ->assertSessionHas('webhook_secret');

        $webhook = Webhook::query()->where('name', 'Tim')->firstOrFail();
        $this->assertTrue($webhook->is_active);

        // Secret at rest is encrypted, never plaintext.
        $raw = $webhook->getAttributes()['secret'];
        $this->assertStringNotContainsString('http', $raw);
        $this->assertNotEmpty($webhook->secret);

        // The secret flashes exactly once: visible on the next page…
        $this->actingAs($admin)
            ->get(route('admin.webhooks.index'))
            ->assertOk()
            ->assertSee('Tim')
            ->assertSee($webhook->secret);

        // …and never again afterwards or anywhere else.
        $html = $this->actingAs($admin)
            ->get(route('admin.webhooks.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($webhook->secret, $html);
    }

    public function test_private_url_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.webhooks.store'), [
                'name' => 'Lokal',
                'url' => 'http://127.0.0.1:9000/hook',
                'events' => ['dataset.committed'],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('webhooks', 0);
    }

    public function test_unresolvable_host_is_refused(): void
    {
        $dispatcher = app(WebhookDispatcher::class);

        $this->expectException(\RuntimeException::class);
        $dispatcher->assertPublicUrl('https://nonexistent-domain-xyz-12345.test/hook');
    }

    public function test_dispatch_fans_out_only_to_subscribers(): void
    {
        Http::fake([
            '198.51.100.7/*' => Http::response('ok', 200),
        ]);

        Webhook::query()->create([
            'name' => 'Lain',
            'url' => 'http://198.51.100.7/other',
            'secret' => 's',
            'events' => ['alert.acknowledged'],
            'is_active' => true,
        ]);

        // Sync queue in tests: dispatch runs the job inline (faked HTTP).
        $ids = app(WebhookDispatcher::class)->dispatch('report.generated', ['report_id' => 3]);

        $this->assertSame([], $ids);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_signature_is_verifiable_hmac(): void
    {
        Http::fake([
            '198.51.100.7/*' => Http::response('ok', 200),
        ]);

        $webhook = Webhook::query()->create([
            'name' => 'Tim',
            'url' => 'http://198.51.100.7/hook',
            'secret' => 's3cr3t-key',
            'events' => ['alert.acknowledged'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event' => 'alert.acknowledged',
            'payload' => ['alert_id' => 7],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        // 198.51.100.0/24 is documentation space (public, non-routable):
        // passes the SSRF guard without touching the real network (faked).
        app(WebhookDispatcher::class)->send($delivery);

        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame(200, $delivery->fresh()->http_status);

        $request = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first();
        $this->assertNotNull($request);

        $sent = $request->data();
        $this->assertSame('alert.acknowledged', $sent['event']);

        $expected = 'sha256='.hash_hmac('sha256', (string) $request->body(), 's3cr3t-key');
        $this->assertSame($expected, $request->header('X-Signature-256')[0]);
        $this->assertSame((string) $delivery->getKey(), $request->header('X-Request-Id')[0]);
    }

    public function test_failed_delivery_marks_failed_and_replay_requeues(): void
    {
        Http::fake([
            '198.51.100.7/*' => Http::response('boom', 500),
        ]);

        $admin = User::factory()->admin()->create();
        $webhook = Webhook::query()->create([
            'name' => 'Tim',
            'url' => 'http://198.51.100.7/hook',
            'secret' => 's3cr3t-key',
            'events' => ['alert.acknowledged'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event' => 'alert.acknowledged',
            'payload' => [],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        try {
            app(WebhookDispatcher::class)->send($delivery);
            $this->fail('send() must throw on HTTP 500.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
        }

        $this->actingAs($admin)
            ->post(route('admin.webhooks.replay', $delivery))
            ->assertRedirect();

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->getKey(),
            'event' => 'alert.acknowledged',
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
    }

    public function test_matching_dispatch_creates_and_delivers(): void
    {
        Http::fake([
            '198.51.100.7/*' => Http::response('ok', 200),
        ]);

        $webhook = Webhook::query()->create([
            'name' => 'Tim',
            'url' => 'http://198.51.100.7/hook',
            'secret' => 's',
            'events' => ['report.generated'],
            'is_active' => true,
        ]);

        $ids = app(WebhookDispatcher::class)->dispatch('report.generated', ['report_id' => 3]);

        $this->assertCount(1, $ids);
        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $ids[0],
            'webhook_id' => $webhook->getKey(),
            'status' => WebhookDelivery::STATUS_DELIVERED,
        ]);
    }

    public function test_disabled_webhook_receives_nothing(): void
    {
        Http::fake();

        Webhook::query()->create([
            'name' => 'Mati',
            'url' => 'https://webhook.example.test/hook',
            'secret' => 's',
            'events' => ['dataset.committed'],
            'is_active' => false,
        ]);

        $ids = app(WebhookDispatcher::class)->dispatch('dataset.committed', []);

        $this->assertSame([], $ids);
        Http::assertNothingSent();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->get(route('admin.webhooks.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('admin.webhooks.store'), [])
            ->assertForbidden();
    }
}
