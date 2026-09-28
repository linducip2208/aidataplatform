# API Reference (contract)

Base URLs: Laravel `http://laravel:8000` (public `/`, direct `:8080`); FastAPI
`http://fastapi:8000` (public `/ai-api/` via Nginx strip, direct `:8001`).

- **Laravel** `/api/*` — public surface, session (Blade) or Sanctum bearer
  (`Authorization: Bearer <token>`). Owns users, datasets, job metadata, audit.
- **FastAPI** `/api/v1/*` — internal. Auth: `X-Service-Key: $SERVICE_API_KEY`
  (header name configurable via `SERVICE_API_KEY_HEADER`, default
  `X-Service-Key`). No browser ever calls it directly in production.

> Job ids are **integers**, not UUIDs. The engine's `import_jobs.id` is the id
> returned by `POST /imports/upload` and polled at `GET /imports/jobs/{id}`.
> Datasets are identified in the Laravel API by a UUID (`datasets.uuid`).

## Envelope conventions

Every FastAPI business endpoint answers with one of:

```json
{"success": true,  "data": {...}}
{"success": false, "error": {"message": "..."}}
```

`GET /api/v1/health` and the `/metrics`, `/docs`, `/redoc`, `/openapi.json`
routes are the exceptions: they return plain objects/text.

Laravel responses:

- single resource: `{"data": {...}}`
- list: `{"data": [...], "meta": {"total": 0, "page": 1, "per_page": 20, "last_page": 0}, "query": {...}}`
- error: `{"message": "...", "code": "...", "errors": {}}`

## Health / meta

| Method | Path | Auth | Response |
|---|---|---|---|
| GET | `/up` (laravel) | none | `200` health probe |
| GET | `/api/health` (laravel) | bearer | engine health, `200` even when the engine is down |
| GET | `/api/v1/health` (engine) | none | `{"status":"ok","app","env","version"}` |
| GET | `/api/v1/readiness` (engine) | none | `{"ready":bool,"checks":{"db","redis"}}` |
| GET | `/metrics` (engine) | none/internal | Prometheus text |
| GET | `/docs`, `/redoc`, `/openapi.json` (engine) | none | Swagger |

## Auth (Laravel)

| Method | Path | Auth | Response |
|---|---|---|---|
| POST | `/api/login` | none | `{"data":{"token":"<plain>","user":{...}}}` |
| POST | `/api/logout` | bearer | revokes the current token |
| GET | `/api/me` | bearer | `{"data":{"id","name","email","role","role_label","is_active","last_login_at"}}` |

`device_name` is an optional field on login (default `api-token`).
Wrong credentials or a deactivated account → `422`.

## Datasets (Laravel)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/datasets` | any | paginated; filters `q`, `dataset_type`, `status`; `sort=-created_at` |
| POST | `/api/datasets` | admin, analyst | multipart `file`, optional `name`, required `dataset_type`; `201` |
| GET | `/api/datasets/{uuid}` | any | includes `columns`, `mappings`, `metadata` |
| GET | `/api/datasets/{uuid}/quality` | any | runs the profile, `score`/`verdict`/`checks`/`issues` |
| POST | `/api/datasets/{uuid}/mapping` | admin, analyst | `{"mappings":{"src":"target"},"save_as_template":"name"?}` |
| POST | `/api/datasets/{uuid}/commit` | admin, analyst | `{"run_async":true}`, `202` |
| DELETE | `/api/datasets/{uuid}` | admin, analyst | deletes the row and the stored file |

Limits: `MAX_UPLOAD_MB` (default 500) and the extension allowlist from
`config('ai_engine.allowed_extensions')` — `csv, xlsx, xls, json, parquet, zip, txt`.
`per_page` is clamped to 100.

`status` values: `uploaded`, `previewing`, `mapped`, `importing`, `committed`,
`quarantined`, `failed`.

## Import jobs

| Method | Path | Auth | Response |
|---|---|---|---|
| GET | `/api/import-jobs/{importJobId}` | bearer | `{"data":{"job_id","type":"import","status","progress","total_rows","processed_rows","error_rows","report","error"}}` |

Backed by the engine's `GET /api/v1/imports/jobs/{id}`; `404` when the job is
unknown to the engine.

## Ingestion (engine, service key)

| Method | Path | Body | Response `data` |
|---|---|---|---|
| POST | `/api/v1/imports/upload` | multipart `file`, `dataset_type` | `{upload_id, import_job_id, validation, stored_path}` |
| GET | `/api/v1/imports/preview/{job_id}` | — | `{filename,row_count,column_count,columns[],sample_rows[],duplicate_count,warnings[],errors[]}` |
| POST | `/api/v1/imports/mapping/suggest` | `{columns[],dataset_type}` | `[{source_column,target_field,confidence,method}]` |
| POST | `/api/v1/imports/mapping` | `{import_job_id,dataset_type,mappings{},save_as_template?}` | `{mappings{}}` |
| GET | `/api/v1/imports/quality/{job_id}` | — | `{score,breakdown{completeness,uniqueness,validity,consistency},issues[],passed}` |
| POST | `/api/v1/imports/commit` | `{import_job_id,dataset_type,mappings{},run_async}` | ETL result, or `{import_job_id,status:"queued"}` |
| GET | `/api/v1/imports/jobs/{job_id}` | — | `{id,status,progress,total_rows,processed_rows,error_rows,report}` |

Statuses only ever gain values; `queued|uploaded|running|succeeded|failed`.

## Analytics (Laravel proxies the engine)

| Method | Path | Engine call |
|---|---|---|
| GET | `/api/analytics/kpi` | `POST /api/v1/analytics/kpi` |
| GET | `/api/analytics/trend` | `POST /api/v1/analytics/trend` |
| GET | `/api/analytics/rfm` | `POST /api/v1/analytics/rfm` |
| GET | `/api/analytics/abc` | `POST /api/v1/analytics/abc` |
| GET | `/api/analytics/cohort` | `POST /api/v1/analytics/cohort` |
| GET | `/api/analytics/branches` | `GET /api/v1/analytics/branches` |
| GET | `/api/analytics/finance` | `GET /api/v1/analytics/finance` |

All accept `date_from`, `date_to`, `branch`, `category`, `granularity`
(`daily`|`weekly`|`monthly`).

## Machine learning (Laravel proxies the engine)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/ml/models` | any | registry list |
| GET | `/api/ml/models/{modelId}` | any | model + `versions[]` |
| POST | `/api/ml/train` | admin, analyst | `{model_type,name,params{}}`, `202` |
| POST | `/api/ml/models/{modelId}/promote` | admin | governance gate, `{version_id,to_status}` |

`model_type`: `forecast`, `churn`, `segmentation`, `anomaly`, `recommend`.
`to_status`: `PRODUCTION`, `STAGED`, `ARCHIVED`.

## AI assistant and RAG

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/agent/chat` | bearer | `{message,conversation_id?}` → `data` carries `reply` **and** `answer` (same value), `conversation_id`, `evidence[]`, `steps` |
| POST | `/api/rag/query` | bearer | `{question\|query,top_k?}` → `{answer,citations[]}` |

`reply` is kept as an alias of `answer` so older clients keep working.

Engine-side, the assistant is `POST /api/v1/ai/chat` (`{message,conversation_id,context}`)
and RAG indexing is `POST /api/v1/rag/ingest` (`{title,content,source,doc_type}`).

## Errors

- Laravel validation → `422` with `errors` keyed by field.
- Unauthenticated → `401`; wrong role → `403` with `code: forbidden`.
- Engine down / connection refused → `503`, `code: ai_engine_error`.
- Engine 5xx or a rejected service key → `502` (the platform, not the browser,
  is misconfigured). Upstream 4xx (e.g. a malformed mapping) → `422`.
- The engine sets `X-Request-Id` on every response; the failure body carries the
  operation name (`imports.upload`, `analytics.kpi`, …).

## Pagination

List endpoints accept `?page=1&per_page=20&sort=-created_at` and return
`{data, meta:{total,page,per_page,last_page}}`. Filters are per-endpoint
(`status`, `verdict`, `dataset_type`, `q`, `action`, `actor`). `per_page` is
clamped to 100.

## Versioning

The contract is URL-scoped (`/api/v1` on the engine, `/api` on Laravel).
Breaking changes ship as a new prefix with a six-month overlap; additive
optional JSON keys are non-breaking and clients must ignore unknown keys.
