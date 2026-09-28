# Deployment (Ubuntu 24.04)

Production path: a single Docker host, scaled later. `infrastructure/scripts/deploy-ubuntu24.sh`
is idempotent — it detects what is already installed and skips it, so re-running is the normal
way to apply a change.

## 1. Server prep

- Fresh Ubuntu 24.04 LTS, 4 vCPU / 16 GB RAM / 100 GB SSD minimum. A DNS `A` record pointing
  at the host (e.g. `data.example.com`).
- SSH in as a user with sudo, then:

```bash
sudo apt update && sudo apt install -y git
sudo git clone <repo-url> /opt/aidataplatform
cd /opt/aidataplatform
```

## 2. First deploy

```bash
sudo APP_DIR=/opt/aidataplatform DOMAIN=data.example.com bash infrastructure/scripts/deploy-ubuntu24.sh
```

The script runs nine steps: packages (`ca-certificates curl gnupg git make ufw logrotate
cron`, plus `certbot python3-certbot-nginx` when `DOMAIN` is set), the Docker Engine repo and
Compose plugin, the repo, an `aidata` user, UFW (22/80/443), `.env.example → .env`, build and
`up -d`, `php artisan migrate --force` and `db:seed --force`, `healthcheck.sh`, a Certbot hint
and a backup-cron + logrotate install.

On the first run it stops after writing `.env` for you to edit. Fill in:

```
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://data.example.com
POSTGRES_PASSWORD=<strong>
SERVICE_API_KEY=<python -c "import secrets; print(secrets.token_urlsafe(48))">
APP_KEY=base64:<openssl rand -base64 32>
GRAFANA_ADMIN_PASSWORD=<strong>
LLM_API_KEY= / OPENROUTER_API_KEY=<real>
```

Set `SERVICE_API_KEY` to a real random value: the engine treats the shipped placeholder (and
any empty value) as "not configured" and then rejects every caller, so a placeholder boots the
stack but breaks every engine-backed page. `SERVICE_API_KEY_HEADER` must stay `X-Service-Key`
— Compose injects the one root-`.env` value into `laravel`, `fastapi` and both Celery
services, so the two sides cannot drift; a malformed name is the one case where they can,
because the engine falls back to the default.

`APP_ENV` is shared by both services. The engine accepts `dev`, `demo`, `local`, `test`,
`staging`, `stage`, `prod` and `production`, and raises at startup on any other value rather
than guessing — so a typo there stops the `fastapi` container instead of silently running it as
a dev box. The shipped root `.env.example` uses `prod`.

Re-run the same script to build, start, migrate, seed, healthcheck and (re)install the cron
entry. The cron line is `0 2 * * *` for the `aidata` user, running
`infrastructure/scripts/backup.sh` with `BACKUP_DIR=$APP_DIR/backups`.

## 3. TLS

DNS must resolve and port 80 must be open. Then:

```bash
sudo certbot --nginx -d data.example.com
sudo certbot renew --dry-run     # the systemd timer ships with the certbot package
```

Certbot edits `infrastructure/nginx/default.conf` in place, but that file is bind-mounted
read-only into the container, so restart nginx to pick the change up:

```bash
docker compose restart nginx
```

Then uncomment the HSTS line in `infrastructure/nginx/default.conf` and restart nginx again:

```
# add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## 4. Migrations, seeds, workers

Both migration systems must run after any schema change:

```bash
make migrate                                   # artisan migrate --force + alembic upgrade head
docker compose exec laravel php artisan db:seed --force   # first boot only
docker compose ps                              # laravel-queue, laravel-schedule, celery-worker
                                                # and celery-beat must be Up
```

The Laravel image entrypoint already runs `php artisan migrate --force` and
`ai-engine/docker-entrypoint.sh` already runs `alembic upgrade head` on start, so a fresh
`up -d` migrates both. Re-run `make migrate` explicitly after pulling changes, and verify:

```bash
docker compose exec laravel php artisan platform:doctor
```

`platform:doctor` is read-only and runs seventeen checks in four groups: configuration
(`APP_KEY`, `APP_ENV`, `APP_DEBUG`, `AI_ENGINE_URL`, `SERVICE_API_KEY`, `MAX_UPLOAD_MB`
against the PHP limits, `QUALITY_THRESHOLD`), the engine (`/api/v1/health`,
`/api/v1/readiness`, and an authenticated `engine_auth` round trip to `/api/v1/models`),
the database (connection, the `vector` and `pg_trgm` extensions, the 27 engine tables, the
12 Laravel tables, row counts) and the filesystem (`storage/` and the datasets disk
writable). It exits non-zero on any `fail`. `--json` emits the same report for a monitor.

Scale worker throughput when training or imports back up:

```bash
docker compose up -d --scale celery-worker=3
docker compose up -d --scale laravel-queue=3
```

`laravel-queue` consumes the `datasets` and `default` queues;
`laravel-schedule` runs `php artisan schedule:work --whisper` and therefore never exits — a
`laravel-schedule` that is restarting is a real failure, not noise. Both share the `laravel`
image, environment and volumes, so no build is needed.

Keep `CELERY_QUEUES` a superset of the queues `celery_app.py` routes to: `import_file` and
`transform_dataset` go to `imports`, `validate_dataset` to `quality`, the ML tasks to `ml`,
`generate_ai_report` to `agent`, `generate_embeddings` to `rag` and `scheduled_data_sync` to
`default`. A task routed to a queue the worker does not subscribe to is enqueued and never
consumed, which looks exactly like a stuck import. Compose's default list already matches. The
alert evaluation task has no route of its own and lands on `default`, which is why the
per-minute `alert-evaluation` entry in the beat schedule runs without a queue change.

`CELERY_BEAT_SCHEDULER` must be `celery.beat.PersistentScheduler`. Compose's own default is
`redbeat.RedBeatScheduler` and `redbeat` is not in `ai-engine/requirements.txt`, so with the
variable unset the beat container crash-loops with "Cannot load the scheduler class" and the
three scheduled entries (a nightly sync at 01:15, an hourly AI report, and the per-minute
alert evaluation) never run.

## 5. Applying updates

```bash
cd /opt/aidataplatform
bash infrastructure/scripts/backup.sh            # snapshot first
git pull
docker compose up -d --build
make migrate
bash infrastructure/scripts/healthcheck.sh
bash tests/run.sh
```

`tests/run.sh` needs the published ports (`:8080` and `:8001`); it is the real check that the
contract still works, and `healthcheck.sh` only proves the processes answer.

## 6. Hardening checklist

Run through this with `security.md` before go-live.

- UFW allows only 22/80/443; `APP_DEBUG=false`; `APP_ENV=prod`.
- Unique strong secrets for `POSTGRES_PASSWORD`, `SERVICE_API_KEY`, `APP_KEY`,
  `GRAFANA_ADMIN_PASSWORD` and the LLM keys. The shipped `.env.example` defaults are not
  secrets.
- `REDIS_PASSWORD` is empty by design. The Redis healthcheck in `docker-compose.yml` runs
  `redis-cli -a "$REDIS_PASSWORD" ping` when the variable is non-empty, so setting a password
  is supported; `infrastructure/scripts/healthcheck.sh` authenticates the same way. Before
  relying on it, verify with `docker compose ps` that `redis` reports `healthy` — a mismatch
  between the two scripts leaves the stack waiting forever.
- Postgres and Redis publish host ports for operator convenience. Bind them to `127.0.0.1` or
  drop the `ports:` entries.
- Nginx already sets `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` and
  `Referrer-Policy`; HSTS is opt-in behind the TLS step.
- `git` is on the host. Restrict `/opt/aidataplatform/.env` to the `aidata` user.
- Weekly `apt upgrade`; confirm a backup has been restored at least once
  (`backup-restore.md`).

## 7. Rollback

Images are built locally, so rolling back is a checkout plus a rebuild:

```bash
cd /opt/aidataplatform
git checkout <previous-tag>
docker compose up -d --build
make migrate
bash infrastructure/scripts/healthcheck.sh
```

That only reverts code. If the rollback also needs the schema, restore from a dump:

```bash
bash infrastructure/scripts/restore.sh backups/aidata_<ts>.sql.gz
docker compose exec laravel php artisan migrate --force
```

See `backup-restore.md` for the full procedure and `troubleshooting.md` for symptoms.
