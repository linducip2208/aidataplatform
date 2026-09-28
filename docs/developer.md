# Developer Guide

API-first integration against the contract in `api.md`. That file is the source of truth for
every path and payload; this one is the how. Inter-service auth is always
`X-Service-Key: $SERVICE_API_KEY`, server-side only.

## 1. Call the engine directly

For a one-off, the engine accepts the service key on its own — that is how `tests/run.sh` and
`infrastructure/scripts/healthcheck.sh` work.

```bash
BASE=http://localhost:8001
KEY=$SERVICE_API_KEY

# health needs no key
curl -s $BASE/api/v1/health

# upload a file: data.import_job_id is an integer
J=$(curl -s -X POST $BASE/api/v1/imports/upload -H "X-Service-Key: $KEY" \
  -F file=@tests/fixtures/sample_sales.csv -F dataset_type=sales | jq -r .data.import_job_id)

# poll it
curl -s "$BASE/api/v1/imports/jobs/$J" -H "X-Service-Key: $KEY"
```

Use the published engine port, not the Nginx path. `/ai-api/` strips the prefix correctly and
serves `/docs`, `/redoc` and the unauthenticated probes, but `infrastructure/nginx/default.conf`
sets `proxy_set_header X-Service-Key ""` on that location, so any credential you send through
it is dropped and every business route answers `401`.

Every engine response is an envelope: `{"success": true, "data": …}` or
`{"success": false, "error": {"message": "…"}}`. The exception is `GET /api/v1/health`,
`/readiness`, `/liveness` and the `/metrics`, `/docs`, `/redoc`, `/openapi.json` routes, which
return bare objects or text.

From PHP inside Laravel, never construct the URL by hand — inject the client:

```php
use App\Services\AiEngineClient;

public function __construct(private readonly AiEngineClient $engine) {}

// $engine->importJob($id) already returns the unwrapped data array.
$job = $this->engine->importJob((int) $dataset->import_job_id);
```

The client is the single place that knows the base URL, the header name, the timeouts and the
envelope. It sets `X-Client: laravel-orchestrator` alongside the service key, retries twice at
250 ms, and throws `App\Exceptions\AiEngineException` with the upstream status on any failure.

From Python:

```python
import os, httpx
r = httpx.post(
    f"{os.environ['AI_ENGINE_URL']}/api/v1/training/train",
    headers={"X-Service-Key": os.environ["SERVICE_API_KEY"]},
    json={"model_type": "forecast", "name": "f1", "params": {"horizon": 14}},
    timeout=120,
)
r.raise_for_status()
data = r.json()["data"]
```

Never read the service key from a browser, from Blade, or from a Vite-exposed env var.

## 2. Call the platform from a client

Laravel's public surface is `/api/*` with Sanctum bearer tokens. Get one and use it:

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"Admin123!","device_name":"cli"}' | jq -r .data.token)

curl -s "http://localhost:8080/api/analytics/kpi?granularity=monthly" -H "Authorization: Bearer $TOKEN"
```

Laravel envelopes differ from the engine's: single resources are `{"data": {…}}`, lists are
`{"data": […], "meta": {total, page, per_page, last_page}, "query": {…}}`, errors are
`{"message": "…", "code": "…", "errors": {}}`. Datasets are addressed by UUID, not by the
integer `id` — `datasets.import_job_id` is the only place the engine's integer ids surface.

## 3. Asynchronous work

Two shapes, and only two:

- **Laravel `202`.** `POST /api/datasets/{uuid}/commit` (`run_async` defaults to `true`) and
  `POST /api/ml/train` answer `202`. There is no job id in the train response and no queue
  behind it — training runs inside the request. For commit, poll
  `GET /api/import-jobs/{importJobId}` until `status` is `done` or `done_with_errors`.
- **Celery.** `POST /api/v1/imports/commit` with `run_async: true` enqueues
  `app.workers.tasks.import_file` on the `imports` queue. The engine has no callback or
  webhook mechanism, so polling is the only way to observe completion.
- **Laravel queue and scheduler.**   `php artisan sync:quality --queue` pushes one
  `App\Jobs\RefreshQualityScoreJob` per dataset onto the `datasets` queue, which
  `docker-compose.yml` runs as the `laravel-queue` service. `application/routes/console.php`
  schedules `sync:import-status` and `sync:quality` daily, run by `laravel-schedule`
  (`php artisan schedule:work --whisper`).

Progress is a 0.0–1.0 fraction on `import_jobs.progress`, not 0–100. There is no
`GET /api/v1/jobs/{id}` catch-all; the import job status lives at
`GET /api/v1/imports/jobs/{job_id}` and nowhere else.

For bulk reconciliation prefer the commands over polling from your own code:
`php artisan sync:import-status` and `php artisan sync:quality`, both with `--dry-run`.

## 4. Schemas and migrations

Two owners, one table each. Data tables come from a new Alembic revision in
`ai-engine/alembic/versions/`; app tables from a Laravel migration in
`application/database/migrations/`. Both must update `data-dictionary.md` in the same PR.

Cross-service references are soft integer columns with no foreign keys —
`datasets.import_job_id`, `chat_threads.ai_conversation_id`. Do not add a foreign key from a
Laravel migration to an engine-owned table: it couples `php artisan migrate` to a schema it
does not own, and the ordering between the two migration systems is not guaranteed.

To change a column type that other code reads, land the Alembic revision and the code change
together, then `make migrate` and `php artisan platform:doctor`.

## 5. Error handling

| Situation | What the caller sees |
|---|---|
| Engine unreachable, not configured | `503`, `code: ai_engine_error` |
| Engine `401` (service key rejected) | `502` — the platform is misconfigured, not the client |
| Engine `404` on a proxied resource | `404`, `code: not_found` |
| Engine `5xx`, or `429` from its rate limiter | `502` |
| Engine `4xx` other than the above | `422` |
| Validation failure in Laravel | `422` with `errors` keyed by field |
| Wrong role | `403`, `code: forbidden` |
| Unauthenticated | `401` |

`AiEngineException::statusForClient()` is the single place that mapping lives. The engine sets
`X-Request-ID` on every response and the failure body names the operation (`imports.upload`,
`analytics.kpi`, …); log both when reporting a problem.

The engine's own auth is fail-closed: a missing, wrong or placeholder `SERVICE_API_KEY` always
yields `401`, in every environment. Expect `401` and not `403` on a rejected key, and do not
treat `GET /api/v1/health` answering `200` as evidence that the key is configured.

## 6. Testing your integration

`bash tests/run.sh` is the contract checklist — health, token login, role enforcement,
upload, job poll, quality, analytics, engine-direct registry call, missing-key rejection and a
RAG query. It defaults to `BASE_LARAVEL=http://localhost:8080` and
`BASE_AI=http://localhost:8001`; it reads `SERVICE_API_KEY` from the root `.env` and skips
steps 5–8 when that is empty.

Add a step to `run.sh` when you extend the contract, in the same PR as the `api.md` change.
Unit suites: `application/tests/` (PHPUnit/Pest) and `ai-engine/tests/` (pytest); `make test`
runs both. Fixtures belong in `tests/fixtures/`.

## 7. Versioning

URL-scoped: `/api/v1` on the engine, `/api` on Laravel. Breaking changes ship as a new prefix
with a six-month overlap. Adding an optional JSON key is non-breaking — clients must ignore
unknown keys, and the engine already returns `reply` alongside `answer` for exactly that
reason. Deprecations are announced in release notes.

Retry on `429` and `503` with backoff. The engine's limiter allows 120 requests per minute per
service key and path; Laravel already retries engine calls twice at 250 ms.
