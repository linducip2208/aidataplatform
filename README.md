# AIDataPlatform — Enterprise AI Data Platform

Laravel (app/orchestration) + FastAPI AI Engine (ingestion, quality, ML, agent, RAG) + Postgres pgvector + Redis/Celery + Prometheus/Grafana, all behind Nginx.

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
                  | Postgres:5432| | Redis:6379 | | Celery worker| | Beat |
                  | pgvector     | | broker+cache| | imports,ml.. | | sched|
                  +------+------+ +-----+------+ +--------------+ +------+
                         |              |
                  +------+------+ +-----+------+
                  | Prometheus:9090     | Grafana:3000 |
                  +---------------------+--------------+
```

**Service map:** `laravel` (UI + REST orchestration, calls FastAPI with `SERVICE_API_KEY`), `fastapi` (stateless API + enqueues Celery), `celery-worker` (imports/quality/ML/agent/RAG jobs), `celery-beat` (scheduled quality re-checks, retention), `postgres` (raw/staging/warehouse/analytics/ml/ai schemas), `redis` (cache + broker), `prometheus`/`grafana` (metrics).

## Prerequisites

- Docker Desktop 4.30+ (or Docker Engine 26+ + Compose v2) — 8 GB RAM minimum, 20 GB disk.
- Git, Make (`choco install make` on Windows or use Git Bash / WSL2).
- Ports free: 80, 443, 8080, 8001, 9090, 3000, 5432, 6379.
- (Optional prod) Ubuntu 24.04 VM + domain + LLM key (OpenRouter).

## Quickstart (Docker — recommended)

```bash
git clone <repo-url> aidataplatform
cd aidataplatform
cp .env.example .env
# edit .env: set POSTGRES_PASSWORD, SERVICE_API_KEY, LLM_API_KEY/OPENROUTER_API_KEY
# generate service key: python -c "import secrets; print(secrets.token_urlsafe(48))"

docker compose up -d --build
docker compose ps
bash infrastructure/scripts/healthcheck.sh
```

Open:

| What | URL |
|---|---|
| App (Laravel via Nginx) | http://localhost/ |
| Laravel direct | http://localhost:8080/ — `/up` health |
| AI Engine direct | http://localhost:8001/docs — `/api/v1/health` health |
| AI via Nginx | http://localhost/ai-api/docs |
| Prometheus | http://localhost:9090 |
| Grafana | http://localhost:3000 (admin / `$GRAFANA_ADMIN_PASSWORD`) |

Migrate + seed:

```bash
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan db:seed --force
# or
make migrate seed
```

## Local dev WITHOUT Docker (Windows + Laragon)

Laragon (Apache/Nginx + PHP 8.3/8.4 + Postgres) for `application/`, venv for `ai-engine/`:

```powershell
# 1) PHP/Laravel
# Laragon: Menu > PHP > Version > php-8.3.x; Menu > PostgreSQL > start; create db `aidata`
cd application
composer install
copy .env.example .env   # DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8080

# 2) Python AI engine (new terminal)
cd ai-engine
py -3.13 -m venv .venv; .\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
copy .env.example .env   # if present, else set DATABASE_URL/REDIS_URL/SERVICE_API_KEY
uvicorn app.main:app --reload --port 8000
celery -A app.celery_app.celery_app worker -l info -P solo  # Windows: solo pool
```

See `docs/development.md` for full dev workflow (queues, pgvector install, troubleshooting Windows).

## Env setup

All secrets are `${VAR}` in `docker-compose.yml` with safe defaults; real values live in `.env` (never commit). Minimum to change for any deploy:

```
POSTGRES_PASSWORD=  # strong random
SERVICE_API_KEY=    # `python -c "import secrets; print(secrets.token_urlsafe(48))"`
LLM_API_KEY= / OPENROUTER_API_KEY=
APP_KEY=            # `php artisan key:generate --show`
GRAFANA_ADMIN_PASSWORD=
```

Full 70+ var reference: `.env.example`. Rules: no real secrets in repo, rotate service key per environment, `APP_DEBUG=false` in prod.

## Demo accounts (seeded)

Seeders (`application/database/seeders/`) create:

| Role | Email | Password | Notes |
|---|---|---|---|
| Admin | admin@example.com | `Admin123!` (change!) | full access, governance approve |
| Analyst | analyst@example.com | `Analyst123!` | upload, quality, ML train |
| Viewer | viewer@example.com | `Viewer123!` | read-only dashboards |

> Change these in production (`docs/security.md`, `docs/administrator.md`).

## Upload demo (happy path)

1. Login → **Datasets → New Upload** → pick `tests/fixtures/sample_sales.csv` (or any CSV ≤ `MAX_UPLOAD_MB`).
2. Laravel stores file, calls `POST /api/v1/ingest` on FastAPI (header `X-Service-Key`), gets `job_id`.
3. Worker pipeline: `raw → staging → warehouse` + quality profile (score vs `QUALITY_THRESHOLD=0.75`; below → quarantine).
4. Track: **Imports** page (job status) → **Quality** page (score, nulls, dupes) → **ML** (train) → **Agent/RAG** (chat over dataset).
5. API equivalent: see `docs/api.md` + `tests/run.sh` for curl examples.

## API docs

- Laravel REST: `http://localhost:8080/api/docs` (Swagger/Scribe if installed) — auth: Sanctum bearer; internal AI calls use `X-Service-Key`.
- FastAPI: `http://localhost:8001/docs` (Swagger), `/redoc`, `/openapi.json`. Health: `/api/v1/health`. Metrics: `/metrics` (Prometheus).
- Contract summary: `docs/api.md`. Integration checklist: `tests/README.md`, `tests/run.sh`.

## Monitoring

- Prometheus targets: `laravel:8000/metrics` (if exposed), `fastapi:8000/metrics`, `redis`, `postgres-exporter` (optional — see `prometheus.yml` comments).
- Grafana: provisioned dashboard JSON at `infrastructure/monitoring/grafana-dashboard.json` (latency, queue depth, import jobs, ML jobs).
- Details: `docs/monitoring.md`.

## Troubleshooting (top 5)

1. `laravel unhealthy / curl /up fail` → `docker compose logs laravel`; check `APP_KEY` set, DB reachable (`pg_isready`), `storage/` writable.
2. `fastapi /api/v1/health 401/403` → `SERVICE_API_KEY` mismatch between `.env` and request header; must match in both `laravel` and `fastapi` services.
3. `postgres password auth failed` → you changed `.env` after first boot; volume `pgdata` keeps old password → `docker compose down -v` (wipes data!) or `ALTER USER`.
4. `redis NOAUTH` → `REDIS_PASSWORD` set in compose command but empty client config; keep consistent or leave empty in dev.
5. `upload 413` → `client_max_body_size 500M` in nginx + `MAX_UPLOAD_MB=500` + PHP `upload_max_filesize/post_max_size`; raise all three.

Full matrix: `docs/troubleshooting.md`.

## Production

Single path: **`docs/deployment.md`** (Ubuntu 24.04 idempotent script `infrastructure/scripts/deploy-ubuntu24.sh`: Docker install, UFW, `.env`, migrations, seeds, workers, Nginx + Certbot SSL hint, backup cron, logrotate). Also read `docs/security.md`, `docs/backup-restore.md`, `docs/monitoring.md` before go-live.
