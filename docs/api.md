# API Reference (contract)

Base URLs: Laravel `http://laravel:8000` (public `/`, direct `:8080`); FastAPI
`http://fastapi:8000` (public `/ai-api/` via Nginx strip, direct `:8001`).

- **Laravel** `/api/*` — public surface, session (Blade) or Sanctum bearer
  (`Authorization: Bearer <token>`). Owns users, datasets, job metadata, audit.
- **FastAPI** `/api/v1/*` — internal. Auth: `X-Service-Key: $SERVICE_API_KEY`
  (header name configurable via `SERVICE_API_KEY_HEADER`, default
  `X-Service-Key`). No browser ever calls it directly in production.

> Job ids are **integers**, not UUIDs. The engine's `import_jobs.id` is the id
> returned by `POST /imports/upload` and polled at `GET /imports/jobs/{job_id}`.
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
| GET | `/up` (laravel) | none | health probe, the container healthcheck target |
| GET | `/api/health` (laravel) | bearer | engine health, `200` even when the engine is down |
| GET | `/api/v1/health` (engine) | none | `{"status":"ok","app","env","version"}` |
| GET | `/api/v1/readiness` (engine) | none | `{"ready":bool,"checks":{"db","redis"}}` |
| GET | `/api/v1/liveness` (engine) | none | `{"alive":true}` |
| GET | `/metrics` (engine) | none/internal | Prometheus text |
| GET | `/docs`, `/redoc`, `/openapi.json` (engine) | none | Swagger |

None of the engine rows use the envelope. `require_service_auth` is a dependency
of every business route and of nothing else, so `/health`, `/readiness`,
`/liveness`, `/metrics` and the schema routes answer bare objects and text —
which is why a green health check says nothing about service-key configuration.
The engine also exposes unauthenticated root aliases of the first three
(`/health`, `/readiness`, `/liveness`); the root `/readiness` is a fixed
`{"ready":true}` stub, so only `/api/v1/readiness` performs the dependency
checks. Nginx clears `X-Service-Key` on `/ai-api/` and 404s `/ai-api/metrics` outright, so
every business call through that prefix is rejected and no scrape can be relayed from
outside the network — it is for the docs and the probe routes only.

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
| GET | `/api/datasets/{uuid}/quality` | admin, analyst | runs the profile and writes the result back to the row, so it is a write behind a GET; `score`/`verdict`/`checks`/`issues` |
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

Status values are exactly `uploaded` (created by the upload), `queued` (`run_async`
accepted the job), `done` and `done_with_errors` (terminal, written by the ETL),
and `failed` (validation rejected the file, or the task exhausted its retries).

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
| GET | `/api/analytics/kpi/definitions` | `GET /api/v1/analytics/kpi/definitions` |
| POST | `/api/analytics/kpi/definitions` | `POST /api/v1/analytics/kpi/definitions` |
| GET | `/api/analytics/kpi/history` | `GET /api/v1/analytics/kpi/history` |
| POST | `/api/analytics/compare` | `POST /api/v1/analytics/compare` |
| POST | `/api/analytics/drilldown` | `POST /api/v1/analytics/drilldown` |
| POST | `/api/analytics/dashboards/resolve` | `POST /api/v1/analytics/dashboards/resolve` |
| POST | `/api/analytics/export` | `POST /api/v1/analytics/export` |

All accept `date_from`, `date_to`, `branch`, `category`, `granularity`
(`daily`|`weekly`|`monthly`).

## Machine learning (Laravel proxies the engine)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/ml/models` | any | registry list |
| GET | `/api/ml/models/{modelId}` | any | model + `versions[]` |
| POST | `/api/ml/train` | admin, analyst | `{model_type,name,params{}}`, `202` |
| POST | `/api/ml/models/{modelId}/promote` | admin | governance gate, `{version_id,to_status}` |
| GET | `/api/ml/experiments` | any | experiment list |
| POST | `/api/ml/experiments` | admin, analyst | create experiment |
| POST | `/api/ml/experiments/{experimentId}/compare` | any | compare experiments |
| POST | `/api/ml/experiments/{experimentId}/promote` | admin | promote experiment |
| POST | `/api/ml/models/{modelId}/rollback` | admin | rollback model |
| GET | `/api/ml/models/{modelId}/events` | any | audit events |
| GET | `/api/ml/models/{modelId}/detail` | any | model detail |
| POST | `/api/ml/batch-predict` | admin, analyst | batch prediction |

`model_type`: `forecast`, `churn`, `segmentation`, `anomaly`, `recommend`.
`to_status`: `PRODUCTION`, `STAGED`, `ARCHIVED`.

## AI assistant and RAG

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/agent/chat` | bearer | `{message,conversation_id?}` → `data` carries `reply` **and** `answer` (same value), `conversation_id`, `evidence[]`, `steps` |
| GET | `/api/ai/usage` | bearer | usage ledger proxy for `GET /api/v1/ai/usage`, `?conversation_id=` optional |
| POST | `/api/rag/query` | bearer | `{question or query,top_k?}` → `{answer,citations[]}` |

`reply` is kept as an alias of `answer` so older clients keep working.

## Data catalog (Laravel)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/catalog/datasets/{uuid}` | admin, analyst, viewer | catalog entry with `schema_hash`, counts, contract presence |
| GET | `/api/catalog/datasets/{uuid}/columns` | admin, analyst, viewer | column metadata ordered by name |
| GET | `/api/catalog/datasets/{uuid}/versions` | admin, analyst, viewer | version history, newest last |
| GET | `/api/catalog/datasets/{uuid}/contracts` | admin, analyst, viewer | active contract plus `evaluation`; `404` with `contract_not_found` when none |
| GET | `/api/catalog/datasets/{uuid}/health` | admin, analyst, viewer | freshness, quality, contract and drift summary |
| GET | `/api/schema-registry/datasets/{uuid}` | admin, analyst, viewer | registry schema plus `schema_hash` |
| POST | `/api/catalog/datasets/{uuid}/versions` | admin, analyst | register a version snapshot; `201` |
| POST | `/api/catalog/datasets/{uuid}/columns/{column}/annotate` | admin, analyst | annotate `business_description`, `sensitivity`, `is_pii`; `201` |
| POST | `/api/catalog/datasets/{uuid}/contracts` | admin, analyst | upsert the data contract; `201` |
| POST | `/api/schema-registry/datasets/{uuid}/drift` | admin, analyst | compute-only schema drift check, nothing persisted |

## Lineage (Laravel)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/lineage/datasets/{uuid}/graph` | admin, analyst, viewer | lineage graph both directions, `?depth=` 1-10 default 3 |
| GET | `/api/lineage/{nodeType}/{nodeId}/upstream` | admin, analyst, viewer | upstream traversal, `?depth=` 1-10 default 5 |
| GET | `/api/lineage/{nodeType}/{nodeId}/downstream` | admin, analyst, viewer | downstream traversal, `?depth=` 1-10 default 5 |
| POST | `/api/lineage` | admin, analyst | record one lineage edge; `201`, idempotent on identical edge |

## Data quality governance (Laravel proxies the engine for evaluate/history)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/quality/rules` | any | list local rules; filters `dataset_type`, `rule_type`, `active` |
| POST | `/api/quality/rules` | admin, analyst | create a rule; `201` |
| POST | `/api/quality/evaluate` | admin, analyst | evaluate via `POST /api/v1/quality/evaluate`; `201` |
| GET | `/api/quality/history` | any | engine history via `GET /api/v1/quality/history`; read-only |
| GET | `/api/quality/runs/{id}` | any | run detail; read-only |

## AI decisions (Laravel proxies the engine)

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/decisions/rules` | any | rule vocabulary with `RULES_VERSION`; read-only |
| GET | `/api/decisions` | any | case headers, `?limit=` 1-200; read-only |
| GET | `/api/decisions/{id}` | any | case detail; read-only, `404` with `not_found` when unknown |
| POST | `/api/decisions/recommend` | admin, analyst | compute plus persist on the engine; `201` with the stored case |
| POST | `/api/decisions/scenarios/run` | admin, analyst | compute-only scenario; unsupported shapes pass through untouched |
| POST | `/api/decisions/{id}/audit` | admin, analyst | append a human decision audit row; `201` |

## Engine endpoints with no Laravel proxy

Published for completeness. All take the service key and return the standard
envelope; `App\Services\AiEngineClient` has a method for each one.

| Method | Path | Auth | Body / notes |
|---|---|---|---|
| GET | `/api/v1/models` | key | registry list, newest first, capped at 200 rows |
| GET | `/api/v1/models/{model_id}` | key | model plus `versions[]` |
| POST | `/api/v1/models/{model_id}/promote` | key | `{version_id,to_status}` |
| POST | `/api/v1/training/train` | key | `{model_type,name,params{},dataset[]}`, synchronous |
| POST | `/api/v1/training/predict` | key | `{model_type,model_name,payload{}}` |
| POST | `/api/v1/ai/chat` | key | `{message,conversation_id,context}` |
| POST | `/api/v1/ai/report` | key | `{period,branch,format}`; `format: "html"` returns raw HTML, not the envelope |
| POST | `/api/v1/rag/ingest` | key | `{title,content,source,doc_type}`, synchronous |
| POST | `/api/v1/rag/query` | key | `{query,top_k}` |
| POST | `/api/v1/forecast` | key | `{history[],horizon,granularity}` |
| POST | `/api/v1/customers/churn` | key | `{customers[]}` |
| POST | `/api/v1/customers/segment` | key | `{customers[],n_clusters}` |
| POST | `/api/v1/inventory/health` | key | `{}` |
| POST | `/api/v1/anomaly/detect` | key | `{series[],sensitivity}` |
| POST | `/api/v1/recommend` | key | `{customer_id,product_id,top_k}` |
| POST | `/api/v1/decision/recommend` | key | `{subject}` → compute plus persist a case |
| GET | `/api/v1/decision/cases` | key | read-only case headers |
| GET | `/api/v1/decision/cases/{case_id}` | key | read-only case detail |
| POST | `/api/v1/decision/scenarios/run` | key | `{type,params,subject}` compute-only |
| POST | `/api/v1/decision/cases/{case_id}/audit` | key | `{actor,decision,rationale}` append audit |
| GET | `/api/v1/decision/rules` | key | rule vocabulary with `RULES_VERSION` |
| POST | `/api/v1/quality/rules` | key | create a quality rule |
| GET | `/api/v1/quality/rules` | key | list quality rules |
| POST | `/api/v1/quality/evaluate` | key | evaluate a dataset or job, persists the run |
| GET | `/api/v1/quality/history` | key | evaluation history |
| GET | `/api/v1/quality/runs/{run_id}` | key | run detail |
| POST | `/api/v1/imports/{job_id}/cancel` | key | cancel a queued import |
| POST | `/api/v1/imports/{job_id}/resume` | key | resume a cancelled import |
| GET | `/api/v1/imports/{job_id}/checkpoints` | key | chunk checkpoints for resume |
| GET | `/api/v1/imports/{job_id}/dead-letter` | key | malformed rows quarantine |

## Alerting (engine, service key)

Laravel has no proxy for these; call the engine directly or drive them from a
scheduled job. `app/alerts/rules.py` owns the vocabulary and the
open → acknowledged → resolved state machine; `app/alerts/service.py` owns the
SQL and exposes the Celery task the beat schedule runs every minute.

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/alerts/metrics` | key | the metric registry, with unit, default severity and operators |
| GET | `/api/v1/alerts/rules` | key | `?active_only=true` to filter |
| POST | `/api/v1/alerts/rules` | key | `{name,metric,operator,threshold,is_active}`; created |
| GET | `/api/v1/alerts/rules/{rule_id}` | key | one rule |
| PATCH | `/api/v1/alerts/rules/{rule_id}` | key | only the keys in `RULE_FIELDS`; an unknown key is a configuration error |
| DELETE | `/api/v1/alerts/rules/{rule_id}` | key | refused while any alert still references the rule; no content |
| GET | `/api/v1/alerts/alerts` | key | alerts newest first; `?status=`, `?rule_id=`, `?limit=` (default 50, max 200) |
| GET | `/api/v1/alerts/alerts/{alert_id}/events` | key | the alert's history, oldest first |
| POST | `/api/v1/alerts/alerts/{alert_id}/ack` | key | `{note?}`; acknowledging is not resolving |

The list route is also reachable at `GET /api/v1/alerts`, the shorter alias the router
declares. Both call the same handler; the table names the explicit form.

`metric` is one of `sales.revenue`, `sales.orders`, `sales.units`, `sales.aov`,
`sales.growth_pct`, `branch.revenue_max`, `branch.count`,
`inventory.stockout_count`, `inventory.dead_stock_count`,
`inventory.min_days_of_stock`, `finance.net_profit`, `finance.margin_pct`.
`operator` (or `condition`) is one of `>`, `>=`, `<`, `<=`, `==`, `!=`; the
spelled-out aliases (`gt`, `greater`, `less_or_equal`, …) are accepted too.
`alerts.severity` is derived from the metric, not from the rule, because
`alert_rules` has no severity column.

Notification is a single optional webhook: set `ALERT_WEBHOOK_URL` on the engine
to enable it, leave it unset for no delivery. Failures are recorded on the
`alert_events` row and never abort an evaluation. See `monitoring.md` §8.

`GET /api/v1/alerts/alerts` is an unlisted alias of `GET /api/v1/alerts`, hidden from the
schema; prefer the short form.

## Errors

- Laravel validation → `422` with `errors` keyed by field.
- Unauthenticated → `401`; wrong role → `403` with `code: forbidden`.
- Engine down / connection refused → `503`, `code: ai_engine_error`.
- Engine 5xx or a rejected service key → `502` (the platform, not the browser,
  is misconfigured). Upstream 4xx (e.g. a malformed mapping) → `422`.
- The engine sets `X-Request-ID` on every response; the failure body carries the
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
