# AIDataPlatform — Enterprise AI Data Platform

Laravel (app/orchestration) + FastAPI AI Engine (ingestion, quality, ML, agent, RAG, decisions, alerts, semantic glossary) + MySQL 8 + Redis/Celery + Prometheus/Grafana, all behind Nginx.

UI highlights: Tabler shell with Executive/DATA/BI/AI/Decision/Governance nav, Alert Center, Decision Center, AI Cost dashboard, report history, product images on ABC, trend + branch charts.

```
                         +------------------+
                         |      Nginx :80   |
                         |      :443 (ssl)  |
                         +--------+---------+
                                  |
                +-----------------+------------------+
                |                                    |
        GET /  -> laravel:8000              /ai-api/ -> fastapi:8000/
        (Blade UI + /api/* REST)            (stripped to /*, /api/v1/*, /docs, /metrics)
                |                                    |
                +--------+              +------------+-----------+
                         |              |                        |
                   +------+------+ +-----+------+ +------+-------+ +------+
                   |  MySQL:3306  | | Redis:6379 | | Celery worker| | Beat |
                   |  utf8mb4     | | broker     | | imports,ml.. | | sched|
                   +------+------+ +-----+------+ +--------------+ +------+
                         |              |
                  +------+------+ +-----+------+ +--------------+ +------+
                  | Prometheus:9090    | Grafana:3000 | laravel-queue    | laravel-
                  +---------------------+--------------+ datasets,default | schedule
```

`laravel-queue` and `laravel-schedule` are the same image as `laravel` with the
entrypoint cleared, so they share its environment, volumes and build.

**Service map:** `laravel` (UI + REST orchestration, calls FastAPI with `SERVICE_API_KEY`), `fastapi` (stateless API + enqueues Celery), `celery-worker` (imports/quality/ML/agent/RAG jobs), `celery-beat` (engine schedule: `nightly-data-sync` at 01:15, `hourly-ai-report` at :00, `alert-evaluation` every minute, all `Asia/Jakarta`), `laravel-queue` (Laravel queue worker for the `datasets` and `default` queues), `laravel-schedule` (`php artisan schedule:work --whisper`: `sync:import-status` at 02:15, `sync:quality` at 02:45, and `report:generate --period=weekly` Mondays at 06:00), `mysql` (all tables live in the single `aidata` database, `utf8mb4`, and are namespaced by prefix: `raw_*`, `staging_*`, `fact_*`/`dim_*`, `ml_*`, `rag_*`, `ai_*`, `alert_*`, `audit_logs`; RAG embeddings are stored as JSON and scored Python-side), `redis` (cache + broker), `prometheus`/`grafana` (metrics).

## Repo layout

```
aidataplatform/
├─ application/          Laravel 12 app: Blade UI, REST API (Sanctum), migrations, seeders
│  ├─ app/Http/Controllers/     Blade + API controllers
│  ├─ app/Services/             AiEngineClient, DatasetIngestionService
│  ├─ config/ai_engine.php      every AI engine env key Laravel reads
│  ├─ database/migrations/      users, datasets, audit, chat, tokens, cache, jobs
│  ├─ database/seeders/         UserSeeder, DatasetSeeder, ChatSeeder, AuditLogSeeder
│  └─ resources/views/          Blade + Alpine + Tailwind (built by Vite)
├─ ai-engine/            FastAPI engine: ingestion, analytics, ML, assistant, RAG, Celery
│  ├─ app/api/v1/               /imports, /analytics, /training, /ai, /rag, /health
│  ├─ app/core/                 config, security (service key), errors
│  ├─ alembic/                  engine schema migrations
 │  └─ docker-entrypoint.sh      waits for MySQL, runs `alembic upgrade head`
├─ infrastructure/       nginx, docker (laravel assets+runtime image), monitoring, scripts
├─ docs/                 Indonesian documentation set (api.md is the English contract)
├─ tests/                cross-service smoke tests: run.sh, README.md, fixtures/
├─ .github/workflows/    laravel.yml, python.yml (CI), docker.yml (image build), deploy.yml
├─ docker-compose.yml    the whole stack
├─ .env.example          every variable Compose interpolates
└─ Makefile              up | migrate | seed | test | health | backup | restore
```

## What runs where

Two services share one MySQL database, and they own different tables.

- **Laravel owns** users, roles, personal access tokens, datasets plus their columns, mappings and metadata, chat threads and messages, audit logs, and the cache/job tables. It serves the Blade UI and the public `/api/*` surface.
- **The engine owns** `raw_uploads`, `staging_tables`, `import_jobs`, `mapping_templates`, `data_quality_reports`, the star schema (`fact_sales`, `fact_purchases`, `fact_expenses`, `fact_inventory`, `dim_*`), `ml_models`/`model_versions`/`training_runs`/`prediction_runs`, `rag_documents`/`rag_chunks`, `ai_conversations`/`ai_messages` and `alert_rules`/`alerts`/`alert_events`.
- **The browser never calls the engine.** Laravel proxies it: the UI talks to `/api/*`, Laravel calls the engine on `AI_ENGINE_URL` with the `X-Service-Key` header, and returns `502`/`503` when the engine is unreachable or rejects the key.
- **Migrations are split.** Laravel's tables come from `php artisan migrate` (run by the Laravel container on start), the engine's from `alembic upgrade head` (run by `ai-engine/docker-entrypoint.sh`). Run both after changing a schema: `make migrate`.
- **No table has two owners.** `audit_logs` is declared only by the Laravel migration; the engine's Alembic revision deliberately does not create it, because the two containers start concurrently and whichever ran second would fail on "relation already exists".

## Prerequisites

- Docker Desktop 4.30+ (or Docker Engine 26+ + Compose v2) — 8 GB RAM minimum, 20 GB disk.
- Git, Make (`choco install make` on Windows or use Git Bash / WSL2).
- Node.js 20+ **only for the local non-Docker path** — the Docker image compiles the frontend itself, so nothing is needed on the host for `docker compose up`. Plus PHP 8.3 and Python 3.13 for that same local path.
- Ports free: 80, 443, 8080, 8001, 9090, 3000, 3306, 6379.
- (Optional prod) Ubuntu 24.04 VM + domain + LLM key (OpenRouter).

## Quickstart (Docker — recommended)

```bash
git clone <repo-url> aidataplatform
cd aidataplatform
cp .env.example .env

# 1) edit .env and set the values marked [CHANGE]:
#    MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD, APP_KEY, SERVICE_API_KEY, GRAFANA_ADMIN_PASSWORD,
#    LLM_API_KEY / OPENROUTER_API_KEY
#    service key: python -c "import secrets; print(secrets.token_urlsafe(48))"
#    app key:     openssl rand -base64 32   (prefix with base64:)

# 2) start the stack
docker compose up -d --build
docker compose ps
bash infrastructure/scripts/healthcheck.sh        # Linux/macOS/Git Bash
powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1   # Windows
```

The frontend assets do **not** need to be built on the host. `infrastructure/docker/laravel.Dockerfile` has a `node:22-alpine` assets stage that runs `npm ci && npm run build` inside the image, and `laravel-entrypoint.sh` installs the result into `public/build` on every start. The only host-side prerequisite is that `application/package-lock.json` is committed — the build stage runs `npm ci` and fails loudly if the lockfile is missing.

Open:

| What | URL |
|---|---|
| App (Laravel via Nginx) | http://localhost/ |
| Laravel direct | http://localhost:8080/ — `/up` health |
| AI Engine direct | http://localhost:8001/docs — `/api/v1/health` health |
| AI via Nginx | http://localhost/ai-api/docs |
| Prometheus | http://localhost:9090 |
| Grafana | http://localhost:3000 (admin / `$GRAFANA_ADMIN_PASSWORD`) — the Prometheus datasource and the dashboard are provisioned automatically |

Migrate + seed:

```bash
# The Laravel image runs `php artisan migrate --force` and the engine runs
# `alembic upgrade head` on start; run them again explicitly after any change:
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan db:seed --force
# or, both services in one go:
make migrate seed
```

## Local dev WITHOUT Docker (Windows + Laragon)

Laragon (Apache/Nginx + PHP 8.3/8.4 + MySQL) for `application/`, venv for `ai-engine/`. You also need Redis, Node.js 20+ and Python 3.13.

```powershell
# 1) PHP/Laravel
# Laragon: Menu > PHP > Version > php-8.3.x; Menu > MySQL > start; create db `aidata` (utf8mb4)
#           Redis on 127.0.0.1:6379 (Menu > Redis > start)
cd application
composer install
copy .env.example .env   # already points at DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1
php artisan key:generate
npm install
npm run build            # REQUIRED: the Blade layouts call @vite(), every page
                         # 500s with "Vite manifest not found" without this.
                         # Use `npm run dev` instead while iterating on CSS/JS.
php artisan migrate --seed
php artisan serve --port=8080

# 2) Python AI engine (new terminal)
cd ai-engine
py -3.13 -m venv .venv; .\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
copy .env.example .env
#   set SERVICE_API_KEY to the SAME value as application/.env, and point
#   DATABASE_URL / REDIS_URL at your local MySQL and Redis
alembic upgrade head
uvicorn app.main:app --reload --port 8001

# 3) worker, beat, and the Laravel queue/scheduler (further terminals)
celery -A app.workers.celery_app:celery_app worker -l info -P solo -Q default,imports,quality,ml,agent,rag
celery -A app.workers.celery_app:celery_app beat -l info --scheduler celery.beat.PersistentScheduler
# Windows: solo pool (-P solo) is required for Celery
# Laravel side: `php artisan queue:work --queue=datasets,default --tries=3`
#               `php artisan schedule:work`
```

Port 8001 on purpose: `application/.env.example` ships `AI_ENGINE_URL=http://127.0.0.1:8001`, so the engine has to listen there. Laravel reaches the engine on that URL and sends `SERVICE_API_KEY` in the `SERVICE_API_KEY_HEADER` header; if the two values disagree, every engine-backed page returns `502`.

See `docs/development.md` for the full dev workflow (queues, MySQL setup, troubleshooting Windows).

## Env setup

All secrets are `${VAR}` in `docker-compose.yml` with safe defaults; real values live in `.env` (never commit). Minimum to change for any deploy:

```
MYSQL_PASSWORD=  # strong random
MYSQL_ROOT_PASSWORD=  # strong random
SERVICE_API_KEY=    # `python -c "import secrets; print(secrets.token_urlsafe(48))"`
APP_KEY=            # `php artisan key:generate --show` or `openssl rand -base64 32`
LLM_API_KEY= / OPENROUTER_API_KEY=
GRAFANA_ADMIN_PASSWORD=
```

`.env.example` is the complete, grouped reference for every variable Compose interpolates, plus the AI engine timeouts read by `application/config/ai_engine.php`. Two rules that are easy to get wrong:

- `SERVICE_API_KEY_HEADER` must stay `X-Service-Key`. Both services read it and both resolve it the same way (`application/config/ai_engine.php` and `ai-engine/app/core/config.py::service_api_key_header`, via `ai-engine/app/core/security.py::resolve_service_key_header`), and Compose injects the one root-`.env` value into `laravel`, `fastapi` and both Celery services, so the two cannot drift apart. The engine still validates the name: a value that is not a valid RFC 7230 token, or that collides with a load-bearing header (`Authorization`, `Cookie`, `Host`, `X-Request-ID`, …), falls back to `X-Service-Key`.
- `APP_ENV` must be one of `dev`, `demo`, `local`, `test`, `staging`, `stage`, `prod`, `production`. The engine raises at startup on anything else rather than guessing, so a typo stops the `fastapi` container instead of silently running it as a dev box. Compose's own default is `production` and the shipped root `.env.example` uses `prod`.

Rules: no real secrets in repo, rotate the service key per environment, `APP_DEBUG=false` in prod.

## Demo accounts (seeded)

`application/database/seeders/UserSeeder.php` creates three accounts. The seeder is idempotent: passwords are only applied when an account is created for the first time.

| Role | Email | Password | Notes |
|---|---|---|---|
| Admin | admin@example.com | `Admin123!` (change!) | full access, model governance approval, user management |
| Analyst | analyst@example.com | `Analyst123!` | upload datasets, run quality checks, train models, use the assistant |
| Viewer | viewer@example.com | `Viewer123!` | read-only dashboards and reports |

> Change these in production (`docs/security.md`, `docs/administrator.md`).

## Upload demo (happy path)

1. Login → **Datasets** → **Unggah dataset** (New Upload) → pick `tests/fixtures/sample_sales.csv` (or any file in the allowlist `csv, xlsx, xls, json, parquet, zip, txt`, at most `MAX_UPLOAD_MB` = 500 MB).
2. Laravel stores the file under `storage/app/datasets/YYYY/MM/`, forwards it to the engine with `POST /api/v1/imports/upload` (header `X-Service-Key`) and gets back an integer `import_job_id`.
3. On the dataset page, run the four workflow steps: **Pratinjau** (preview) → **Pemetaan kolom** (column mapping) → **Kualitas** (quality profile; score is compared against `QUALITY_THRESHOLD=0.75`, below it the dataset is marked `quarantined`) → **Commit** (rows that pass go to the warehouse).
4. The Celery worker runs `raw → staging → warehouse`. Watch progress on **Imports** (job status), the profile on **Kualitas**, then train a model under **Machine Learning** or ask a question under **Asisten** (chat over the dataset).
5. API equivalent: see `docs/api.md` + `tests/run.sh` for curl examples.

## API docs

There is no Swagger/Scribe UI on the Laravel side. The available surfaces are:

- **Engine (interactive):** http://localhost:8001/docs (Swagger UI), `/redoc`, `/openapi.json`; health `/api/v1/health`, dependency check `/api/v1/readiness`, Prometheus text `/metrics`. Through Nginx the same app is at http://localhost/ai-api/docs.
- **Laravel REST:** no generated docs, use the contract in **`docs/api.md`** as the single reference. Get a token with `POST /api/login` and send it as `Authorization: Bearer <token>` (Sanctum). `GET /up` is the unauthenticated health probe; `GET /api/health` (bearer) proxies the engine health.
- Internal Laravel → engine calls are authenticated with `X-Service-Key: $SERVICE_API_KEY`, never from the browser.
- Integration checklist and curl examples: `tests/README.md`, `tests/run.sh`.

## Monitoring

- `infrastructure/monitoring/prometheus.yml` scrapes two targets: `fastapi:8000/metrics` every 15s and Prometheus itself. Laravel has no `/metrics` route, and the `redis` / `mysql` exporter jobs are present only as commented-out blocks, so they are not even registered as DOWN targets. To scrape Laravel, add a Prometheus client library and a `/metrics` route; to add the exporters, uncomment the blocks and start the containers on `aidata-appnet`.
- The engine declares exactly two metrics, `http_requests_total{method,path,status}` and `http_request_latency_seconds` (a `Histogram`).
- Grafana: `docker-compose.yml` provisions both the Prometheus datasource and a dashboard provider, and mounts `infrastructure/monitoring/grafana-dashboard.json` into `/etc/grafana/provisioning/dashboards/`, so the dashboard appears on first start under the **AIDataPlatform** folder. Several of its panels are written against metric names this build does not emit; see `docs/monitoring.md` §5 for the two to edit.
- Details: `docs/monitoring.md`.

## Troubleshooting (top 5)

1. Every page returns `500` / "Vite manifest not found" → the `laravel` image was built without a working assets stage. `docker compose build --no-cache laravel`, then `up -d laravel`. The `laravel` healthcheck tests `public/build/manifest.json` explicitly, so it reports unhealthy rather than waiting for a request. If the build itself failed, the missing piece is almost always an absent `application/package-lock.json`, which the assets stage requires for `npm ci`.
2. `laravel` unhealthy / curl /up fail → `docker compose logs laravel`. Three usual causes: `APP_KEY` still empty (the entrypoint refuses to start and every request would fail on the encrypter), MySQL not reachable (`docker compose exec mysql mysqladmin -h 127.0.0.1 -u aidata ping`), or a `php artisan migrate --force` failure — the entrypoint leaves `storage/framework/migrate_failed` behind, which the same healthcheck detects.
3. Engine-backed pages return `502` / the engine logs `401` → `SERVICE_API_KEY` mismatch between Laravel and the engine. Compose injects the same value into both, so this normally means `application/.env` was edited and diverges, or the value was changed without restarting every service. Note that `GET /api/v1/health` is deliberately unauthenticated and will keep answering `200` while business calls fail.
4. Async work never finishes / the `laravel-queue` or `laravel-schedule` container keeps restarting → check `docker compose ps`. `laravel-queue` consumes the `datasets` and `default` queues, `laravel-schedule` runs `php artisan schedule:work` (the long-running form; `schedule:run` would fire once and exit). For the engine side, `celery-worker` crash-loops with "Cannot load the scheduler class" on `celery-beat` if `CELERY_BEAT_SCHEDULER=redbeat.RedBeatScheduler` — `redbeat` is not in `ai-engine/requirements.txt`, so the shipped `.env.example` defaults to the built-in scheduler.
5. `413` on upload → three separate limits: `client_max_body_size 500M` in `infrastructure/nginx/default.conf`, PHP `upload_max_filesize=500M` / `post_max_size=550M` (baked into the image by `infrastructure/docker/laravel.Dockerfile`), and Laravel's own `max:MAX_UPLOAD_MB` validation rule. Raise all three together; if the file passes the size check but the extension is not in `config('ai_engine.allowed_extensions')`, you get `422` instead of `413`.

Full matrix: `docs/troubleshooting.md`.

## Production

Single path: **`docs/deployment.md`** (Ubuntu 24.04 idempotent script `infrastructure/scripts/deploy-ubuntu24.sh`: Docker install, UFW, `.env`, migrations, seeds, workers, Nginx + Certbot SSL hint, backup cron, logrotate). Also read `docs/security.md`, `docs/backup-restore.md`, `docs/monitoring.md` before go-live.
