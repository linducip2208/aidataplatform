# Installation

Two paths: **Docker** (recommended, works on Windows/Linux) and **local dev**
(Laragon + venv, Windows). Production Ubuntu path is in `deployment.md`.

## A. Docker install (5 min)

1. Install Docker Desktop 4.30+ (Windows: enable WSL2 backend) or Engine 26+Compose v2.
2. Clone + env:

```bash
git clone <repo-url> aidataplatform
cd aidataplatform
cp .env.example .env
```

3. Edit `.env` (minimum): `POSTGRES_PASSWORD`, `SERVICE_API_KEY`
   (`python -c "import secrets; print(secrets.token_urlsafe(48))"`),
   `LLM_API_KEY`/`OPENROUTER_API_KEY`, `APP_KEY` (set after first boot via
   `docker compose exec laravel php artisan key:generate --show`), `GRAFANA_ADMIN_PASSWORD`.
4. Boot:

```bash
docker compose up -d --build
docker compose ps
bash infrastructure/scripts/healthcheck.sh
```

5. Migrate + seed:

```bash
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan db:seed --force
```

6. Open: App http://localhost/ · Laravel :8080 `/up` · FastAPI :8001 `/docs` and
   `/api/v1/health` · Prometheus :9090 · Grafana :3000.

## B. Local dev without Docker (Windows + Laragon)

1. Laragon: install full edition, switch PHP to 8.3/8.4, start Postgres + Redis
   (or Memurai/redis-windows). Create DB `aidata`, enable `vector` + `pg_trgm`
   (`CREATE EXTENSION vector;` — needs pgvector binaries; else use Docker postgres only).
2. Laravel:

```powershell
cd application
composer install
copy .env.example .env
php artisan key:generate
# .env: DB_HOST=127.0.0.1 DB_DATABASE=aidata DB_USERNAME=... REDIS_HOST=127.0.0.1 AI_ENGINE_URL=http://127.0.0.1:8000
php artisan migrate --seed
php artisan serve --port=8080
```

3. AI engine (second terminal):

```powershell
cd ai-engine
py -3.13 -m venv .venv; .\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
$env:SERVICE_API_KEY="same-as-laravel"; $env:DATABASE_URL="postgresql+asyncpg://..."
uvicorn app.main:app --reload --port 8000
# worker (third terminal, Windows needs solo pool):
celery -A app.celery_app.celery_app worker -l info -P solo
```

## C. Verify install

```bash
curl http://localhost:8080/up
curl http://localhost:8001/api/v1/health
bash tests/run.sh   # integration checklist (expects docker stack up)
```

If `pg_isready`/`redis-cli ping` fail, see `troubleshooting.md`. Next: `development.md`
(workflow) or `deployment.md` (Ubuntu 24.04 prod).
