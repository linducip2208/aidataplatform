# Webhooks (outbound)

Domain events fan out to subscriber URLs with HMAC signatures, background
retry, and a delivery log with replay. Admin > Webhooks.

## Events

`dataset.committed`, `report.generated`, `alert.acknowledged`,
`decision.audited` — emitted from the service layer, so web, API and
console callers all fan out. Emission never breaks the raising operation:
failures are logged as `webhook.emit_failed` and swallowed.

## Delivery

- `POST` JSON `{event, data, timestamp, delivery_id}`, 10 s timeout.
- Headers: `X-Signature-256: sha256=<hmac of the raw body>`,
  `X-Request-Id: <delivery id>`, `User-Agent: AIDataPlatform-webhook/1.0`.
- Queue job `DispatchWebhook` (`$tries = 3`, backoff 30/120/600 s);
  terminal failures land in `failed` with the reason, replayable from the UI
  (replay creates a fresh delivery row, never mutates history).

## SSRF protection

Subscription URLs must be absolute http(s), must resolve, and no resolved
IP may be private/loopback/link-local/reserved — checked at subscribe time
*and* at send time (a record swap in between is still caught).

## Secrets

Generated server-side (`random_bytes(32)`), `encrypted` cast at rest, shown
exactly once after creation, never logged, never in audit detail. Rotation
= delete + re-create (new secret); revocation = disable or delete.
