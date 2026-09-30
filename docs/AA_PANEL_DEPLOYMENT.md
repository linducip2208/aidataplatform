# aaPanel Production Deployment (native Linux, no Docker)

This is the production path. Docker Compose stays as the developer/test
environment only. Every step below runs on the host OS; nothing here assumes
a container runtime.

Target host: Ubuntu 22.04/24.04, 4 vCPU / 8 GB RAM minimum, 40 GB disk.

## 1. aaPanel + services

1. Install aaPanel, then from its App Store install: **Nginx 1.2x**, **PHP
   8.3** (or newer 8.x), **MySQL 8.0**, **Redis 7**, **Supervisor Manager**,
   **Node.js version manager** (Node 20+), **Python Manager** (Python 3.13).
2. PHP extensions (aaPanel > PHP > Install extensions): `fileinfo`,
   `redis`, `pdo_mysql`, `bcmath`, `intl`, `zip`, `gd`, `opcache`,
   `pcntl`, `mbstring`. These mirror
   `infrastructure/docker/laravel.Dockerfile`.
3. PHP settings for 500 MB dataset uploads (`php.ini` or aaPanel config):
   `upload_max_filesize=500M`, `post_max_size=550M`, `memory_limit=1G`,
   `max_execution_time=300`.
4. MySQL: create database `aidata` (`utf8mb4`, `utf8mb4_unicode_ci`) and
   user `aidata`; Redis: set a password and keep it on 127.0.0.1.
5. Firewall (aaPanel > Firewall): open 80/443 only. MySQL (3306), Redis
   (6379) and FastAPI (8001) stay loopback-only.
6. SSL: aaPanel > Website > SSL > Let's Encrypt **after** §4 verifies on
   :80, then uncomment the HSTS line in the nginx template.

## 2. Site + code

```bash
sudo mkdir -p /www/wwwroot/aidata
sudo chown www:www /www/wwwroot/aidata
sudo -u www git clone <repo-url> /www/wwwroot/aidata
```

aaPanel > Website > Add site: domain `data.example.com`, root
`/www/wwwroot/aidata/application/public`, PHP 8.3, **no database creation
here** (already done in §1).

## 3. Environment

Two env files (one per runtime; values mirror the root `.env.example`):

| File | Key values |
|---|---|
| `application/.env` | `APP_URL=https://data.example.com`, `APP_DEBUG=false`, `DB_HOST=127.0.0.1`, `DB_DATABASE=aidata`, `DB_USERNAME=aidata`, `DB_PASSWORD=<strong>`, `REDIS_HOST=127.0.0.1`, `REDIS_PASSWORD=<redis>`, `AI_ENGINE_URL=http://127.0.0.1:8001`, `SERVICE_API_KEY=<48+ random>`, `APP_KEY=` (generated) |
| `ai-engine/.env` | same `SERVICE_API_KEY`, `DATABASE_URL=mysql+aiomysql://aidata:<pw>@127.0.0.1:3306/aidata`, `SYNC_DATABASE_URL=mysql+pymysql://...` (same host), `REDIS_URL`/`CELERY_BROKER_URL` with the Redis password, `LLM_*` provider keys, `TZ=Asia/Jakarta` |

Generate the service key with
`python3 -c "import secrets; print(secrets.token_urlsafe(48))"`.

Or run `infrastructure/aapanel/deploy-aapanel.sh`, which creates both files
from the shipped `.env.example`s and aborts (exit 10) until the secrets are
filled.

## 4. Build + migrate + seed

```bash
cd /www/wwwroot/aidata/application
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # package-lock.json is committed; never build without it
php artisan key:generate
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force      # idempotent demo accounts + datasets + organization
php artisan config:cache && php artisan route:cache && php artisan view:cache

cd /www/wwwroot/aidata/ai-engine
/usr/bin/python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
SYNC_DATABASE_URL='mysql+pymysql://aidata:<pw>@127.0.0.1:3306/aidata' \
  .venv/bin/python -m alembic upgrade head
```

## 5. Processes (Supervisor)

Install the four templates from `infrastructure/aapanel/supervisor/`
(`aidata-laravel-queue`, `aidata-fastapi`, `aidata-celery-worker`,
`aidata-celery-beat`), replacing `{{APP_USER}}`, `{{APP_DIR}}`,
`{{PHP_BIN}}`. Exactly one beat process: two beats double-fire the nightly
sync, the hourly report and the per-minute alert evaluation.

```bash
supervisorctl reread && supervisorctl update
supervisorctl status aidata-laravel-queue aidata-fastapi aidata-celery-worker aidata-celery-beat
```

## 6. Nginx

Replace the aaPanel-generated server block with
`infrastructure/aapanel/nginx/aidata.conf` (fill `{{DOMAIN}}`,
`{{APP_DIR}}`, `{{PHP_FPM_SOCK}}`), then `nginx -t && nginx -s reload`.
Path contract: `/` → Laravel via php-fpm, `/ai-api/` → FastAPI on
127.0.0.1:8001 with the prefix stripped and credentials cleared; the
engine's authenticated surface 404s at the edge; `/ai-api/metrics` 404s.

## 7. Cron

One line only (aaPanel > Cron, shell script) — see
`infrastructure/aapanel/cron.txt`:

```cron
* * * * * www /www/server/php/83/bin/php /www/wwwroot/aidata/application/artisan schedule:run >> /www/wwwlogs/aidata-schedule.log 2>&1
```

This fires `sync:import-status` (02:15), `sync:quality` (02:45) and
`report:generate --period=weekly` (Mon 06:00). Two copies double-fire
everything.

## 8. Verify

```bash
curl -fsS https://data.example.com/up
curl -fsS http://127.0.0.1:8001/api/v1/health
curl -fsS https://data.example.com/ai-api/api/v1/health
sudo -u www php /www/wwwroot/aidata/application/artisan platform:doctor
```

## 9. Backup / restore

Nightly: `infrastructure/aapanel/backup-native.sh` (mysqldump +
datasets + model artifacts, 14-day retention). Restore: create an empty
`aidata` DB, `gunzip -c aidata_*.sql.gz | mysql`, re-extract the tarballs,
`php artisan migrate --force`, restart Supervisor processes. Full
procedures: `docs/backup-restore.md`.

## 10. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| 500 + "Vite manifest not found" | `npm run build` never ran; rebuild |
| Engine 502s | `SERVICE_API_KEY` differs between the two `.env` files; Supervisor fastapi down |
| Async work stuck | Celery worker/beat down (`supervisorctl status`); Redis password mismatch |
| 413 on upload | `client_max_body_size` < file; PHP limits; `MAX_UPLOAD_MB` — raise all three |
| Queue silent | `laravel-queue` consuming wrong queues; check program args match `datasets,default` |

## 11. What Docker is still for

Local development and CI image builds only. No production step above
needs it, and no production step may depend on it.
