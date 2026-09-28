# API Reference (contract)

Base URLs: Laravel `http://laravel:8000` (public `/`, direct `:8080`); FastAPI
`http://fastapi:8000` (public `/ai-api/` via Nginx strip, direct `:8001`).
FastAPI prefix: `/api/v1`. Auth: users → Sanctum `Authorization: Bearer <token>`;
service → `X-Service-Key: $SERVICE_API_KEY` (`SERVICE_API_KEY_HEADER`).

## Health / meta

| Method | Path | Auth | Response |
|---|---|---|---|
| GET | `/up` (laravel) | none | `200 {"status":"ok"}` |
| GET | `/api/v1/health` (fastapi) | none (or service key if hardened) | `200 {"status":"ok","db":"up","redis":"up"}` |
| GET | `/metrics` (fastapi) | none/internal | Prometheus text |
| GET | `/docs`, `/redoc`, `/openapi.json` | none | Swagger |

## Ingestion

- `POST /api/v1/ingest` (service) — multipart `file` (+ `dataset_name`, `schema_hint`).
  → `202 {"job_id":"uuid","status":"queued","queue":"imports"}`. Limits: 500 MB, csv/xlsx/parquet/json/zip.
- `GET /api/v1/jobs/{job_id}` (service) — `{"job_id","type":"import","status":"queued|running|succeeded|failed","progress":0-100,"error":null}`.
- Laravel mirrors: `POST /api/datasets` (user upload), `GET /api/datasets`, `GET /api/import-jobs/{id}`.

## Data quality

- `POST /api/v1/quality/run` `{"dataset_id":"uuid"}` → `202 {"job_id","queue":"quality"}`.
- `GET /api/v1/quality/{dataset_id}` → `{"dataset_id","score":0.0-1.0,"threshold":0.75,"verdict":"pass|quarantine","checks":{"null_rate":..,"dupe_rate":..,"schema_violations":..},"profiled_at":"iso"}`.

## Machine learning

- `POST /api/v1/ml/train` `{"dataset_id","target","task":"classification|regression","model":"auto|xgb|rf|lr","params":{},"n_splits":5}` → `202 {"job_id","experiment_id","queue":"ml"}`.
- `GET /api/v1/ml/jobs/{job_id}` → status + `metrics` (accuracy/rmse etc.) when done.
- `GET /api/v1/ml/models` → registry list; `POST /api/v1/ml/models/{id}/approve` (admin, governance gate).

## AI agent

- `POST /api/v1/agent/chat` `{"session_id?","dataset_id?","message":"..."}` → `{"reply":"...","tool_calls":[...],"usage":{}}`. Stateless unless `session_id` (memory in `ai.chat_memory`).

## RAG

- `POST /api/v1/rag/index` `{"dataset_id","chunk_size":1000,"overlap":150}` → `202 {"job_id","queue":"rag"}`.
- `POST /api/v1/rag/query` `{"dataset_id","question":"...","top_k":8}` → `{"answer":"...","citations":[{"chunk_id","score","preview"}]}`.

## Errors / conventions

- Errors: `{"detail":"..."}` (4xx/5xx) with `X-Request-Id` header; validation → 422.
- Long tasks always `202 + job_id` (queues `imports,quality,ml,agent,rag`); poll `GET /jobs/{id}`.
- Timeouts: 300 s Nginx ceiling; client timeout 120 s for LLM paths (`LLM_TIMEOUT_S`).
- Curl smoke: `tests/run.sh` exercises health → ingest → job poll → quality → RAG query.

## Auth details

- Browser → Laravel: Sanctum bearer (`Authorization: Bearer <token>` from login
  `POST /api/login {"email","password"}` → `{token}`); logout `POST /api/logout`.
- Laravel → FastAPI: `X-Service-Key: $SERVICE_API_KEY` on every call. Header name is
  configurable (`SERVICE_API_KEY_HEADER`) but defaults must match both services.
- Nginx `/ai-api/` passthrough (if publicly exposed) requires the same service key;
  recommended: keep FastAPI internal-only and proxy all UI traffic through Laravel.

## Pagination / filtering

List endpoints (`GET /api/datasets`, `/api/v1/ml/models`, quality history) accept
`?page=1&per_page=20&sort=-created_at` and return `{data:[...], meta:{total, page,
per_page}}`. Filter params: `status`, `verdict`, `stage`, `dataset_id` where applicable.
Max `per_page=100`; over-limit → 422.

## Versioning / deprecation

Contract version is URL-scoped (`/api/v1`). Breaking changes ship as `/api/v2` with a
6-month overlap; deprecated fields carry a `Sunset` response header + changelog entry.
Additive fields (new optional JSON keys) are non-breaking and need no version bump —
clients must ignore unknown keys. status enums (`queued|running|succeeded|failed`,
`pass|quarantine`, `draft|staged|production|archived`) only ever gain values; document
new values in this file + `tests/run.sh` in the same PR.
