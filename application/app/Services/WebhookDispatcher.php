<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound webhook fan-out with HMAC signatures and SSRF protection.
 *
 * Flow: `dispatch($event, $payload)` enqueues one `DispatchWebhook` job per
 * active subscriber; the job calls `send()`, which POSTs
 * `{event, data, timestamp, delivery_id}` signed with
 * `X-Signature-256: sha256=<hmac>` and records the outcome. Failures retry
 * with backoff (job `$tries`/`$backoff`) and land in `failed` with the
 * reason — visible in Admin > Webhooks, replayable from there.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, int> delivery ids created
     */
    public function dispatch(string $event, array $payload = [], ?User $actor = null): array
    {
        $ids = [];

        $subscribers = Webhook::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($subscribers as $webhook) {
            if (! $webhook->subscribes($event)) {
                continue;
            }

            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->getKey(),
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            DispatchWebhook::dispatch($delivery->getKey());

            $ids[] = (int) $delivery->getKey();
        }

        if ($ids !== []) {
            AuditLog::record('webhook.dispatched', 'webhook', null, [
                'event' => $event,
                'deliveries' => count($ids),
            ], $actor);
        }

        return $ids;
    }

    public function republish(WebhookDelivery $delivery): WebhookDelivery
    {
        $copy = WebhookDelivery::query()->create([
            'webhook_id' => $delivery->webhook_id,
            'event' => $delivery->event,
            'payload' => $delivery->payload ?? [],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        DispatchWebhook::dispatch($copy->getKey());

        return $copy->fresh() ?? $copy;
    }

    public function send(WebhookDelivery $delivery): void
    {
        $webhook = $delivery->webhook;

        if ($webhook === null || ! $webhook->is_active) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error' => 'Webhook missing or disabled.',
            ])->save();

            return;
        }

        $this->assertPublicUrl($webhook->url);

        $body = [
            'event' => $delivery->event,
            'data' => $delivery->payload ?? [],
            'timestamp' => now()->toIso8601String(),
            'delivery_id' => $delivery->getKey(),
        ];

        $encoded = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $encoded, (string) $webhook->secret);

        $response = Http::acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature-256' => $signature,
                'X-Request-Id' => (string) $delivery->getKey(),
                'User-Agent' => 'AIDataPlatform-webhook/1.0',
            ])
            ->timeout(10)
            ->withBody($encoded, 'application/json')
            ->post($webhook->url);

        $delivery->forceFill([
            'http_status' => $response->status(),
        ])->save();

        if (! $response->successful()) {
            throw new RuntimeException('Webhook answered HTTP '.$response->status().'.');
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'error' => null,
        ])->save();
    }

    /**
     * SSRF guard: http(s) only, host must resolve, and no resolved IP may be
     * private/loopback/link-local/reserved. DNS is resolved at send time so a
     * record swap between validation and delivery still gets caught here.
     */
    public function assertPublicUrl(string $url): void
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
        ) {
            throw new RuntimeException('Webhook URL must be an absolute http(s) URL.');
        }

        $host = (string) $parts['host'];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new RuntimeException('Webhook host resolves to a non-public address.');
            }

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records) || $records === []) {
            throw new RuntimeException('Webhook host does not resolve.');
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($ip === '' || ! $this->isPublicIp($ip)) {
                throw new RuntimeException('Webhook host resolves to a non-public address.');
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
