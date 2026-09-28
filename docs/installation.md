# Installation

Two paths: **Docker** (recommended, cross-platform) and **local dev** (Laragon + venv on
Windows). The production Ubuntu path is in `deployment.md`. For the stack topology see
`architecture.md`; the full variable list is in the root `.env.example`.

## A. Docker install

1. Install Docker Desktop 4.30+ (Windows: enable the WSL2 backend) or Docker Engine 26+ with
   Compose v2. Budget 8 GB RAM and 20 GB disk. Free host ports: 80, 443, 8080, 8001, 9090,
   3000, 5432, 6379.
2. Clone and create the root `.env`:

```bash
git clone <repo-url> aidataplatform
cd aidataplatform
cp .env.example .env
```

3. Nothing to build on the host. `infrastructure/docker/laravel.Dockerfile` compiles the
   frontend in a `node:22-alpine` assets stage (`npm ci && npm run build`) and
   `laravel-entrypoint.sh` installs the result into `public/build` on every container start, so
   the `./application` bind mount never leaves a page without a Vite manifest. The one
   host-side requirement is a committed `application/package-lock.json`: the assets stage runs
   `npm ci` and aborts the build if the lockfile is missing.

4. Edit `.env`. Minimum for a working install:

```
POSTGRES_PASSWORD=<strong>
SERVICE_API_KEY=<python -c "import secrets; print(secrets.token_urlsafe(48))">
APP_KEY=<base64:...>
```

   Two values are easy to get wrong:

   - `SERVICE_API_KEY` must be a real value, not the shipped placeholder. The engine treats
     empty, `change-me` and `changeme` as "not configured" and rejects every caller, so a
     placeholder gives you a platform that boots but returns `502` on every engine-backed
     page.
   - `SERVICE_API_KEY_HEADER` must stay `X-Service-Key`. Compose injects the one root-`.env`
     value into `laravel`, `fastapi` and both Celery services, and both services resolve it the
     same way, so the two cannot drift apart. The engine still falls back to `X-Service-Key` if
     the configured name is not a valid header token, so a malformed value fails quietly.

   `APP_ENV` is shared by both services and must be one of `dev`, `demo`, `local`, `test`,
   `staging`, `stage`, `prod`, `production`. The engine raises at startup on anything else
   rather than guessing, so a typo there stops the `fastapi` container. Compose's own default is
   `production` and the shipped root `.env.example` uses `prod`; keep one of the two for a real
   deployment.

   `LLM_API_KEY` / `OPENROUTER_API_KEY` are optional but recommended: without one the
   assistant, RAG and reports fall back to a local echo summary while ingestion, analytics
   and ML keep working. Generate `APP_KEY` with `openssl rand -base64 32` (keep the
   `base64:` prefix) or `php artisan key:generate --show` after the first boot.

5. Boot and check:

```bash
docker compose up -d --build
docker compose ps
bash infrastructure/scripts/healthcheck.sh
```

   `healthcheck.sh` probes `GET /up` on Laravel, `POST /api/login` + `GET /api/me` with the
   seeded admin, `GET /api/v1/health` and `GET /api/v1/readiness` on the engine, the
   `/ai-api/` prefix strip through Nginx, Nginx `GET /health`, `pg_isready`, `redis-cli ping`
   (authenticated when `REDIS_PASSWORD` is set), and that `celery-worker` and `celery-beat` are
   up. It does not probe `laravel-queue` or `laravel-schedule`; check those with
   `docker compose ps`. On Windows use
   `powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1`.

6. Confirm both schemas landed. The Laravel image entrypoint runs
   `php artisan migrate --force` and `ai-engine/docker-entrypoint.sh` runs
   `alembic upgrade head`, but re-run them explicitly after any change:

```bash
make migrate          # artisan migrate --force + alembic upgrade head
docker compose exec laravel php artisan db:seed --force
```

7. Optional but worth running once — it checks configuration, the service key, both schemas
   and storage in one pass:

```bash
docker compose exec laravel php artisan platform:doctor
```

| URL | What |
|---|---|
| `http://localhost/` | App via Nginx |
| `http://localhost:8080/up` | Laravel health |
| `http://localhost:8001/docs`, `/api/v1/health` | Engine Swagger and health |
| `http://localhost/ai-api/docs` | The same engine through Nginx, prefix stripped |
| `http://localhost:9090` | Prometheus |
| `http://localhost:3000` | Grafana (`admin` / `$GRAFANA_ADMIN_PASSWORD`) |

The three seeded demo accounts are listed in `README.md`; sign in at
`http://localhost/login`. If `pg_isready` or `redis-cli ping` fail, go to
`troubleshooting.md`.

### Engine variables

Everything in the root `.env` reaches both services through Compose, except the four
`AI_ENGINE_*_TIMEOUT` values and `MAX_UPLOAD_MB`/`QUALITY_THRESHOLD` on the Laravel side,
which also come from `application/.env` inside the container. The engine's own settings live
in `ai-engine/app/core/config.py`; `ai-engine/.env` is only read when you run it outside
Compose.

| Variable | Default | Notes |
|---|---|---|
| `SERVICE_API_KEY` | placeholder | Shared secret; a placeholder is treated as unset and rejects every call |
| `SERVICE_API_KEY_HEADER` | `X-Service-Key` | Compose injects it into laravel, fastapi and celery, so the two sides cannot drift; a malformed value silently falls back to the default |
| `APP_ENV` | `production` (Compose), `prod` (root `.env.example`) | Must be one of `dev, demo, local, test, staging, stage, prod, production` |
| `QUALITY_THRESHOLD` | Laravel `0.75`, engine `0.6` | Compose injects `0.75` into both, so the shipped stack agrees; set it explicitly anyway — see `data-quality.md` §3 |
| `MAX_UPLOAD_MB` | Laravel `500`, engine `200` | Compose injects `500` into both, overriding the engine default; the engine does not enforce it on the upload path, which Laravel owns |
| `RATE_LIMIT_PER_MINUTE` | `120` | Per credential, and 3× that per path |
| `METRICS_ENABLED` / `METRICS_ALLOW_PUBLIC` | `true` / `false` | `/metrics` is internal-only by default |
| `DOCS_ENABLED` | `true` | Set `false` to close `/docs`, `/redoc`, `/openapi.json` |
| `STORAGE_PATH` / `MODEL_PATH` | `/code/data/datasets`, `/code/data/models` (Compose) | The engine's own defaults are `./datasets` and `./models` beside the code; Compose points them at the `datasets-data` and `models-cache` volumes — see `architecture.md` §7 |
| `CHUNK_ROWS` | `20000` | Read size for the ETL, and the basis of the preview row limit |
| `LLM_PROVIDER`, `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL` | `openrouter`, `https://openrouter.ai/api/v1`, … | Compose overrides `ai-engine/.env.example`'s `openai` default; see the table in `ai-agent.md` §4 |
| `LLM_EMBEDDING_MODEL` or `EMBED_MODEL` | `text-embedding-3-small` (engine default), `sentence-transformers/all-MiniLM-L6-v2` (root `.env`) | `LLM_EMBEDDING_MODEL` is the canonical name and `EMBED_MODEL` the accepted alias; Compose forwards `EMBED_MODEL`, so under Docker the root value wins. Keep the two identical |
| `LLM_TIMEOUT_SECONDS`, `LLM_MAX_RETRIES` | `60`, `3` | Capped at 5 attempts by `MAX_RETRIES_CEILING` |
| `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL` | empty, `https://openrouter.ai/api/v1` | Used when `LLM_PROVIDER=openrouter`, which is the Compose default |
| `CORS_ORIGINS` | `http://localhost:3000,http://localhost:8000` | Only relevant if a browser ever calls the engine, which it should not |
| `JWT_SECRET`, `JWT_ALGORITHM`, `JWT_EXPIRE_MINUTES` | placeholder, `HS256`, `60` | The Bearer path. Compose injects none of these, so the engine runs on the placeholder and the Bearer path never validates; a placeholder `JWT_SECRET` makes it refuse to mint tokens |
| `DATABASE_URL` / `SYNC_DATABASE_URL` | Compose assembles both | Compose passes an async driver in `DATABASE_URL` and a sync one in `SYNC_DATABASE_URL`; the settings class rewrites the async driver rather than letting it reach `create_engine()` |
| `REDIS_DB`, `REDIS_CACHE_DB` | `0`, `1` | Compose injects both into Laravel, which is why `application/.env` cannot override them |
| `CELERY_BROKER_DB`, `CELERY_RESULT_DB` | `1`, `2` | Assemble `CELERY_BROKER_URL` and `CELERY_RESULT_BACKEND`; set the URLs themselves only for a non-Docker Celery run |
| `NETWORK_NAME`, `VOLUME_PREFIX` | `aidata-appnet`, `aidata` | Name of the compose network and prefix for every named volume |
| `AUTO_MIGRATE`, `AUTO_MIGRATE_STRICT` | `true`, `false` | Control the `alembic upgrade head` in `ai-engine/docker-entrypoint.sh` and the `artisan migrate --force` in `laravel-entrypoint.sh`; `celery-worker` and `celery-beat` set `AUTO_MIGRATE=false` so only `laravel` and `fastapi` migrate |
| `PG_WAIT_ATTEMPTS`, `PG_WAIT_INTERVAL` | `60`, `2` | Postgres wait budget before migrating (~120 s) |
| `TZ` | `Asia/Jakarta` | The Celery timezone; Compose does not forward it, so it comes from `ai-engine/.env` |
| `ALERT_WEBHOOK_URL` | empty | Read with `os.environ` by `app/alerts/notifiers.py`; empty means alerts are recorded but not delivered |
| `LOG_LEVEL` | `INFO` | Anything unrecognised falls back to `INFO` |

`UPLOAD_MAX_MB` and `QUALITY_MIN_SCORE` are the deprecated engine-local spellings of
`MAX_UPLOAD_MB` and `QUALITY_THRESHOLD`; they still work and log a deprecation warning.

## B. Local dev without Docker (Windows + Laragon)

Laragon supplies PHP, PostgreSQL and Redis; a venv supplies the engine. You also need
Node.js 20+ and Python 3.13.

1. Laragon: full edition, PHP 8.3 or 8.4, start PostgreSQL and Redis. Create the database
   `aidata` and enable the extensions:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

   `vector` needs the pgvector binaries for your PostgreSQL build; without them the engine
   still runs and stores `rag_chunks.embedding` as JSONB, so retrieval falls back to keyword
   matching. If installing pgvector locally is a hassle, run only PostgreSQL in Docker and
   keep PHP on the host.

2. Laravel:

```powershell
cd application
composer install
copy .env.example .env
php artisan key:generate
npm install
npm run build
# application/.env: DB_HOST=127.0.0.1 DB_DATABASE=aidata DB_USERNAME=... REDIS_HOST=127.0.0.1
#                  AI_ENGINE_URL=http://127.0.0.1:8001
#                  SERVICE_API_KEY must equal the engine's
php artisan migrate --seed
php artisan serve --port=8080
```

   `application/.env.example` already ships `AI_ENGINE_URL=http://127.0.0.1:8001`, so the
   engine has to listen on 8001. The seeder is idempotent and creates the three demo
   accounts; run `php artisan platform:doctor` if the engine-backed pages return `502`.

3. Engine, in a second terminal:

```powershell
cd ai-engine
py -3.13 -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
copy .env.example .env
# set SERVICE_API_KEY to the same value as application/.env, and point
# DATABASE_URL / REDIS_URL at your local Postgres and Redis
alembic upgrade head
uvicorn app.main:app --reload --port 8001
```

4. Worker and beat, in a third and fourth terminal. Windows needs the `solo` pool:

```powershell
celery -A app.workers.celery_app:celery_app worker -l info -P solo -Q default,imports,quality,ml,agent,rag
celery -A app.workers.celery_app:celery_app beat -l info --scheduler celery.beat.PersistentScheduler
```

Keep `-Q` a superset of the queues `celery_app.py` routes to (`TASK_ROUTES`): a task routed
outside the list is enqueued and never consumed. Keep the built-in scheduler —
`redbeat` is not in `ai-engine/requirements.txt` and the beat container cannot load it.

5. The Laravel queue worker and scheduler, in two more terminals. `docker-compose.yml` runs
   them as `laravel-queue` and `laravel-schedule`; outside Docker they are plain commands:

```powershell
cd application
php artisan queue:work --queue=datasets,default --tries=3 --backoff=30 --max-time=3600
php artisan schedule:work --whisper
```

`datasets` is the queue `App\Jobs\RefreshQualityScoreJob` is pushed onto by
`sync:quality --queue`; `default` catches anything dispatched without an explicit queue. Use
`schedule:work`, not `schedule:run` — the latter fires the due commands once and exits.
`--whisper` only silences the "no scheduled commands were ready" line each minute.

## C. Verify the install

```bash
curl -s http://localhost:8080/up
curl -s http://localhost:8001/api/v1/health
docker compose exec laravel php artisan platform:doctor
bash tests/run.sh      # integration checklist; expects the Docker stack to be up
```

`tests/run.sh` logs in as `admin@example.com`, uploads
`tests/fixtures/sample_sales.csv`, polls the import job, runs the quality profile, and calls
the analytics, model-registry and RAG endpoints. It reads `SERVICE_API_KEY` from the root
`.env`; without it, steps 5–8 are skipped as warnings. It defaults to
`BASE_LARAVEL=http://localhost:8080` and `BASE_AI=http://localhost:8001`; override both to
point at a local non-Docker run.

Next: `development.md` for the working loop, `developer.md` for API integration.
