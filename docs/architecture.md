# Architecture

AIDataPlatform is a two-runtime enterprise AI data platform: **Laravel** (UI + orchestration)
and **FastAPI AI Engine** (heavy data/ML work), sharing Postgres (pgvector) + Redis/Celery
behind Nginx. See `README.md` for the ASCII service map.

## 1. Runtime split (why two services)

| Concern | Owner | Reason |
|---|---|---|
| Auth, UI (Blade), REST `/api/*`, billing, audit | `laravel` (`application/`) | PHP team velocity, Sanctum, queues for UI jobs |
| Ingestion, profiling, quality, ML training, agent, RAG, embeddings | `fastapi` (`ai-engine/`) | Python data/ML ecosystem, Celery, pgvector |
| Sync contract | HTTP + `X-Service-Key` (`SERVICE_API_KEY`) | Laravel never trusts browser for AI calls; server-to-server only |

Laravel is the **system of record for users/jobs metadata**; FastAPI is the **system of
record for data content** (schemas `raw/staging/warehouse`, `ml.*`, `ai.*`). No direct
browser → FastAPI in production (goes via Nginx `/ai-api/` with same service key).

## 2. Request flows

**Upload:** Browser → Nginx `/` → Laravel (validate ≤ `MAX_UPLOAD_MB`, store
`storage/app/datasets`) → `POST {AI_ENGINE_URL}/api/v1/ingest` (service key) →
FastAPI returns `{job_id}` → Laravel persists `import_jobs` row → Celery `imports`
queue runs `raw → staging → warehouse` + quality profile → webhooks/poll update Laravel.

**ML train:** Analyst → Laravel `/api/ml/train` → FastAPI `POST /api/v1/ml/train`
→ Celery `ml` queue → artifacts in `/app/data/models` + `ml.*` registry rows.

**Agent/RAG chat:** UI → Laravel → FastAPI `POST /api/v1/agent/chat` or
`/api/v1/rag/query` → retrieval from `ai.embeddings` (pgvector) + LLM
(`LLM_PROVIDER`, default OpenRouter) → answer + citations.

## 3. Data plane (schemas)

`raw` (byte-faithful landings) → `staging` (typed/cleaned) → `warehouse`
(star schema: `dim_*`, `fact_*`) → `analytics` (marts/views) → `ml` (features,
experiments, registry) → `ai` (embeddings, chat memory). DDL via Laravel migrations
(metadata) + Alembic (data tables); bootstrap in `infrastructure/docker/postgres/init.sql`.

## 4. Async plane (Celery)

Broker + result backend: Redis DB 1/2 (`CELERY_BROKER_URL`, `CELERY_RESULT_BACKEND`).
Queues: `default,imports,quality,ml,agent,rag` (`CELERY_QUEUES`). Beat (RedBeat
scheduler) runs nightly quality re-checks + retention purges. Workers are stateless;
scale with `docker compose up -d --scale celery-worker=3`.

## 5. Observability & ops

FastAPI exposes `/metrics` (Prometheus), `/api/v1/health`, `/docs`. Laravel exposes
`/up` (health) and optionally `/metrics` (needs exporter lib). Prometheus scrapes both
(`infrastructure/monitoring/prometheus.yml`); Grafana dashboard JSON ships prebuilt.
Backups: `backup.sh` (pg_dump gz + manifest); deploy: idempotent `deploy-ubuntu24.sh`.

## 6. Scaling notes

- Stateless services (laravel/fastapi/workers) scale horizontally; state lives in
  Postgres/Redis/volumes (`pgdata`, `datasets-data`, `models-cache`).
- Uploads are the bottleneck: Nginx `client_max_body_size 500M` + 300 s timeouts;
  large files stream to disk, never into RAM/DB.
- pgvector HNSW indexes on `ai.embeddings`; partition `warehouse.fact_*` by month
  past ~100 M rows (see `data-dictionary.md`).

## 7. Repo map

`application/` (Laravel, other agent) · `ai-engine/` (FastAPI, other agent) ·
`infrastructure/` (nginx/docker/monitoring/scripts — this agent) ·
`docs/` (contracts) · `tests/run.sh` (integration checklist) ·
`.github/workflows/` (CI/CD templates).
