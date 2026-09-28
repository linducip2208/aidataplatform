# Developer Guide

API-first integration against the contracts in `api.md` + `data-dictionary.md`.
Inter-service auth: `X-Service-Key: $SERVICE_API_KEY` always (server-side only).

## 1. Call the platform (curl → code)

```bash
BASE=http://localhost:8001   # or http://localhost/ai-api (strip prefix, same paths)
KEY=$SERVICE_API_KEY
curl -s $BASE/api/v1/health
J=$(curl -s -X POST $BASE/api/v1/ingest -H "X-Service-Key: $KEY" \
  -F file=@tests/fixtures/sample_sales.csv -F dataset_name=demo | jq -r .job_id)
curl -s $BASE/api/v1/jobs/$J -H "X-Service-Key: $KEY"
```

Python: `httpx.post(f"{AI_ENGINE_URL}/api/v1/ml/train", headers={"X-Service-Key": KEY},
json={...}, timeout=120)`. PHP (Laravel): central HTTP client with `AI_ENGINE_URL`,
retry 3×, 120 s timeout; never expose KEY to Blade/JS.

## 2. Async pattern (202 + poll)

All heavy ops return `202 {job_id, queue}`; poll `GET /api/v1/jobs/{job_id}` with
backoff (2 s → 30 s cap) until `succeeded|failed`. Webhook alternative: configure
Laravel callback URL in dataset meta (ai-engine `POST`s on completion — HMAC with KEY).

## 3. Schemas / migrations

New data tables: Alembic revision in `ai-engine/` targeting the right schema
(`warehouse`/`ml`/`ai`); meta tables: Laravel migration in `application/`. Both must
update `data-dictionary.md` in the same PR. Never `SELECT *` across schemas from the
browser — go through versioned endpoints.

## 4. Laravel ↔ FastAPI examples

- Upload proxy: validate → `Storage::putFile('datasets', $req->file)` → forward
  multipart to `/api/v1/ingest` → store `job_id` → return to UI.
- Predict proxy: `POST /api/predict` (Sanctum) → check model `stage=production` →
  forward to `/api/v1/ml/predict` → return (strip internals).
- RAG proxy: enforce `dataset_id` ACL by role before forwarding `/rag/query`.

## 5. Testing your integration

`bash tests/run.sh` (needs stack up) covers health → ingest → job poll → quality →
ML dry-run → RAG query skeleton. Add your endpoint to that script when extending the
contract. CI templates: `laravel.yml` (composer + sqlite migrate + Pest), `python.yml`
(pytest) — wire your new suites there.

## 6. Versioning

URL versioning (`/api/v1`); breaking changes → `/api/v2` + 6-month overlap. Deprecations
announced in release notes + `Sunset` header. Client rule: pin `top_k/chunk` params
explicitly (defaults may evolve); handle `429/503` with backoff (Beat/LLM backpressure).
