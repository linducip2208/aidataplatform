# Troubleshooting

Run first: `bash infrastructure/scripts/healthcheck.sh` (or `.ps1` on Windows),
then `docker compose ps` + `docker compose logs --tail=50 <svc>`.

## 1. Boot / health

| Symptom | Cause → Fix |
|---|---|
| `laravel` unhealthy (`/up` fail) | `APP_KEY` empty → `docker compose exec laravel php artisan key:generate --show`, set in `.env`, `up -d`; or `storage/` perms → `chown www-data` (Dockerfile does, bind-mount may override) |
| `fastapi` `/api/v1/health` 403 | `SERVICE_API_KEY` mismatch → same value in `laravel`+`fastapi` env; restart both |
| `postgres` auth failed after `.env` change | `pgdata` keeps first-boot password → `ALTER USER aidata PASSWORD 'new'` inside psql, or `down -v` (wipes data) |
| `redis` NOAUTH / wrong pass | `REDIS_PASSWORD` inconsistent → set same everywhere or empty in dev; `redis-cli -a` test |
| `nginx` 502 | upstreams not healthy yet → wait 30 s, `healthcheck.sh`; check `default.conf` mount path |

## 2. Uploads / ingest

| Symptom | Cause → Fix |
|---|---|
| `413 Payload Too Large` | raise all three: nginx `client_max_body_size`, `MAX_UPLOAD_MB`, PHP `upload_max_filesize/post_max_size` (Dockerfile sets 500 M/550 M) |
| `419/401` on upload (Laravel) | CSRF/Sanctum token → include bearer + `X-XSRF-TOKEN`; service key is server-side only |
| Job stuck `queued` | worker down or wrong queue → `logs celery-worker`; verify `CELERY_QUEUES` includes `imports`; `up -d --scale celery-worker=2` |
| Job `failed: OOM` | > RAM CSV → convert to Parquet, raise worker mem, or chunk size 10 k (default) |

## 3. Quality / ML / RAG

- Quality always `quarantine`: lower `QUALITY_THRESHOLD` temporarily or fix source nulls/dupes; override needs admin (`model-governance.md`).
- ML `422 quarantined`: expected — pass quality first or admin override with note.
- ML stuck `running`: `CELERY_TASK_TIME_LIMIT=1800` will reap; check VRAM/disk (`models-cache` full).
- RAG empty citations: dataset not indexed → `POST /rag/index` then poll job; wrong `dataset_id` scope.
- LLM `401/429`: `LLM_API_KEY`/`OPENROUTER_API_KEY` invalid or quota — test with curl to provider; fallback model configured?

## 4. Observability

Prometheus target DOWN (laravel/redis/postgres exporters): expected until exporters
installed (see `monitoring.md`); core paging uses container healthchecks + Grafana
fastapi panels. Grafana login fail: `GRAFANA_ADMIN_PASSWORD` from `.env`, reset via
`grafana-cli admin reset-admin-password`.

## 5. Windows notes

Use WSL2 backend; CRLF breaks `.sh` → `dos2unix infrastructure/scripts/*.sh`;
port clashes with Laragon/XAMPP → change `*_PORT` in `.env`. Celery on Windows needs `-P solo`.
