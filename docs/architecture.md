# Architecture

AIDataPlatform is a two-runtime data platform: **Laravel** (UI + orchestration) and
**FastAPI AI Engine** (ingestion, analytics, ML, assistant, RAG), sharing one PostgreSQL
database (pgvector) and one Redis, behind Nginx. The ASCII service map lives in `README.md`.

## 1. Runtime split

| Concern | Owner | Reason |
|---|---|---|
| Users, roles, tokens, audit, dataset metadata, Blade UI, public `/api/*` | `laravel` (`application/`) | PHP velocity, Sanctum bearer auth, session UI |
| File parsing, quality profiling, ETL, analytics, ML, assistant, RAG | `fastapi` (`ai-engine/`) | Python data/ML ecosystem, pandas/scikit-learn, Celery |
| Sync contract | HTTP + `X-Service-Key: $SERVICE_API_KEY` | Server-to-server only; the browser never holds the key |

The browser talks to Laravel. Laravel proxies every engine-backed call through
`App\Services\AiEngineClient` (`application/app/Services/AiEngineClient.php`), which is the
only place that knows `AI_ENGINE_URL`, the service key, and the timeouts. It unwraps the
engine envelope `{"success":bool,"data":…}` so callers see plain arrays, and raises
`App\Exceptions\AiEngineException` for every failure mode.

## 2. Request flows

**Upload** — `POST /api/datasets` (Laravel) → validate extension and size → store the file
under `/var/www/html/storage/app/datasets/YYYY/MM/` → `POST {AI_ENGINE_URL}/api/v1/imports/upload`
(multipart, service key) → the engine writes `raw_uploads` + `import_jobs` and returns
`data.import_job_id` (integer) → Laravel stores that integer in the soft column
`datasets.import_job_id`. Laravel does **not** create an `import_jobs` row; the engine owns
that table.

**Preview → mapping → quality → commit** — the UI posts to
`/datasets/{uuid}/{preview,mapping,quality,commit}` (`DatasetWorkflowController`), each call
forwarded to `GET /api/v1/imports/preview/{job_id}`,
`POST /api/v1/imports/mapping/suggest`, `POST /api/v1/imports/mapping`,
`GET /api/v1/imports/quality/{job_id}` and `POST /api/v1/imports/commit`.
`DatasetIngestionService` mirrors each transition onto the local `datasets.status`
(`uploaded|previewing|mapped|importing|committed|quarantined|failed`) so the UI stays useful
while the engine works. There is no webhook: the client polls
`GET /api/import-jobs/{importJobId}`, and `php artisan sync:import-status` reconciles in bulk.

**ML** — `POST /api/ml/train` (admin, analyst) → `POST /api/v1/training/train`. Training is
**synchronous** in the request handler: `app/ml/training.py` fits the model, writes a
joblib artifact under `MODEL_PATH`, and records `ml_models` / `model_versions` /
`training_runs` rows. Laravel still answers `202`. Promotion is a separate admin-only call,
`POST /api/ml/models/{modelId}/promote` → `POST /api/v1/models/{model_id}/promote`.

**Assistant / RAG** — `POST /api/agent/chat` → `POST /api/v1/ai/chat`. The engine parses the
intent into at most 8 read-only tool calls (`app/ai/tools.py`), gathers `evidence[]`, and
synthesises the answer with the LLM. Conversations persist to `ai_conversations` /
`ai_messages`; Laravel mirrors them into `chat_threads` / `chat_messages`.

## 3. Data plane: one schema, split DDL ownership

Every table lives in the default `public` schema. There are no `raw.` / `warehouse.` / `ml.`
/ `ai.` schemas — `infrastructure/docker/postgres/init.sql` creates extensions and those
schemas, but no code writes to them, so they stay empty. Namespacing is by **table-name
prefix**, not by schema.

DDL ownership is split on purpose, so that each table has exactly one source of truth:

| Owner | Mechanism | Tables |
|---|---|---|
| Engine | Alembic, `ai-engine/alembic/versions/0001_initial_data_platform.py`, applied by `ai-engine/docker-entrypoint.sh` on container start | `raw_uploads`, `import_jobs`, `staging_tables`, `mapping_templates`, `dim_customer`, `dim_product`, `dim_branch`, `dim_supplier`, `dim_warehouse`, `dim_date`, `dim_department`, `fact_sales`, `fact_inventory`, `fact_purchases`, `fact_expenses`, `ml_models`, `model_versions`, `training_runs`, `prediction_runs`, `ai_conversations`, `ai_messages`, `rag_documents`, `rag_chunks`, `alert_rules`, `alerts`, `alert_events`, `data_quality_reports` |
| Laravel | `application/database/migrations/`, applied by `php artisan migrate --force` | `users` (+ `role`, `is_active`, `last_login_at`), `datasets`, `chat_threads`, `chat_messages`, `audit_logs`, plus the framework tables `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens` |

Because each table has one owner, Laravel references engine-owned ids as **soft integer
columns with no foreign keys**: `datasets.import_job_id` → `import_jobs.id`, and
`chat_threads.ai_conversation_id` → `ai_conversations.id`. Never add a foreign key from a
Laravel migration to an Alembic-owned table; it would make `php artisan migrate` depend on a
schema it does not own. The same rule in reverse: the engine never references `datasets`.

`application/app/Support/PlatformHealth.php` encodes this split twice, as
`PlatformHealth::ENGINE_TABLES` and `PlatformHealth::LARAVEL_TABLES`; `php artisan
platform:doctor` reports either list as missing when the matching migration was not run.

One collision is known: both `ai-engine/alembic/versions/0001_initial_data_platform.py` and
`application/database/migrations/2026_09_28_000400_create_audit_logs_table.php` declare
`audit_logs`, with different columns. Alembic skips a table that already exists, so whichever
migration runs first defines the shape and the other service sees the shorter definition.
Laravel's definition is the intended one (`user_id`, `resource_id`, `ip`, plus `actor`,
`action`, `resource`, `detail`, `created_at`), because `App\Models\AuditLog` writes to all of
those.

## 4. Ingestion pipeline

`run_etl` (`ai-engine/app/ingestion/etl.py`) reads the file in `chunksize=20000` chunks,
applies the column mapping, normalises types, runs the quality checks per chunk, then upserts
the star schema. Before loading it deletes any rows the same `import_job_id` already wrote, so
a replay of the same job id is idempotent. Final job status is `done`, or
`done_with_errors` when a chunk failed; `progress` runs 0.0 → 1.0.

`sales`, `inventory`, `purchases` and `expenses` are loaded into the warehouse. `customers`
and `generic` are accepted as `dataset_type` by `config('ai_engine.dataset_types')` but have
no loader branch, so they import without writing facts.

## 5. Async plane (Celery)

Broker comes from `CELERY_BROKER_URL` and the result backend from
`CELERY_RESULT_BACKEND`, each falling back to `REDIS_URL`
(`ai-engine/app/workers/celery_app.py`). Compose builds those from `REDIS_PASSWORD`,
`CELERY_BROKER_DB` (1) and `CELERY_RESULT_DB` (2), so Celery gets its own Redis databases
while Laravel's cache, session and queue stay on `REDIS_DB` (0).

`TASK_ROUTES` covers all ten tasks: imports and transform go to `imports`, validation to
`quality`, training/forecast/features/anomaly to `ml`, the AI report to `agent`, embeddings
to `rag`, and the nightly sync to `default`. The worker subscribes to `CELERY_QUEUES`, whose
Compose default is `default,imports,quality,ml,agent,rag` — the same tuple the module
declares as `QUEUES`. A task routed to a queue outside that list is enqueued and never
consumed, so a new task needs both a route and a queue name in the `-Q` list.

`beat_schedule` has two entries: `nightly-data-sync` at 01:15 (Asia/Jakarta, from `TZ`) and
`hourly-ai-report` at :00. Both call into `app/workers/tasks.py`; `scheduled_data_sync` is
still a placeholder that returns a status message and does no work. There is no nightly
quality re-profile, retention purge or vacuum — schedule those yourself with
`php artisan sync:quality` and `php artisan sync:import-status`. `CELERY_BEAT_SCHEDULER` must
stay `celery.beat.PersistentScheduler`; Compose's own default is
`redbeat.RedBeatScheduler` and `redbeat` is not in `ai-engine/requirements.txt`, so leaving it
unset makes the beat container crash-loop on start.

## 6. Observability

The engine exposes `GET /metrics` (Prometheus text) and declares exactly two metrics:
`http_requests_total{method,path,status}` and `http_request_latency_seconds` (a
`Histogram`, so latency percentiles come from `..._bucket`). Health surfaces are
`GET /api/v1/health`, `GET /api/v1/readiness` and `GET /api/v1/liveness`, plus unauthenticated
root aliases of the same three. Laravel exposes `GET /up` for container health and
`php artisan platform:doctor` (optionally `--json`) for a full configuration, schema and
storage report. Details in `monitoring.md`.

## 7. Scaling notes

- `laravel`, `fastapi` and `celery-worker` hold no state; it lives in PostgreSQL, Redis and
  the `datasets-data` / `models-cache` volumes. Scale with
  `docker compose up -d --scale celery-worker=3`.
- Uploads are the bottleneck: Nginx `client_max_body_size 500M` with 300 s send/read
  timeouts, and the engine's `AI_ENGINE_UPLOAD_TIMEOUT` (`300`) on the Laravel side.
- Volume paths matter. The Laravel datasets volume is mounted at
  `/var/www/html/storage/app/datasets` and is where uploads survive. The engine's
  `STORAGE_PATH` and `MODEL_PATH` default to `./datasets` and `./models` relative to its
  `/code` working directory, which is **not** where `docker-compose.yml` mounts
  `datasets-data` and `models-cache`; set those two variables explicitly if you need engine
  artifacts to survive a container rebuild.

## 8. Repo map

| Path | Contents |
|---|---|
| `application/` | Laravel 12.69.2, PHP `^8.2`, Sanctum `^4.0`. `routes/web.php` (Blade UI), `routes/api.php` (token API), `config/ai_engine.php` (every engine env key), `app/Services/AiEngineClient.php`, `app/Services/DatasetIngestionService.php`, `app/Support/PlatformHealth.php`, `app/Enums/`, `app/Models/`, `database/migrations/`, `database/seeders/` |
| `ai-engine/` | FastAPI engine. `app/api/v1/*.py` (one module per tag), `app/core/` (config, security, errors, logging), `app/ingestion/`, `app/analytics/`, `app/ml/`, `app/ai/`, `app/workers/`, `alembic/`, `docker-entrypoint.sh` |
| `infrastructure/` | `nginx/default.conf`, `docker/` (laravel.Dockerfile, postgres/init.sql), `monitoring/` (prometheus.yml, grafana-dashboard.json), `scripts/` (healthcheck, backup, restore, deploy-ubuntu24) |
| `docs/` | This documentation set. `api.md` is the English endpoint contract and the source of truth for every path. |
| `tests/` | `run.sh` (integration checklist), `fixtures/sample_sales.csv`, `README.md` |
| `.github/workflows/` | `laravel.yml`, `python.yml`, `docker.yml`, `deploy.yml` |
| `docker-compose.yml`, `.env.example`, `Makefile` | The whole stack, its variables, and the task shortcuts |
