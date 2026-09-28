# Deployment (Ubuntu 24.04)

Production path: single Docker host (scale later). Fully idempotent via
`infrastructure/scripts/deploy-ubuntu24.sh`.

## 1. Server prep

- Fresh Ubuntu 24.04 LTS, 4 vCPU / 16 GB RAM / 100 GB SSD minimum; DNS `A` record
  pointing at it (e.g. `data.example.com`).
- SSH in as root/sudo user; clone repo to `/opt/aidataplatform`:

```bash
sudo apt update && sudo apt install -y git
sudo git clone <repo-url> /opt/aidataplatform
cd /opt/aidataplatform
```

## 2. One-command deploy

```bash
sudo APP_DIR=/opt/aidataplatform DOMAIN=data.example.com bash infrastructure/scripts/deploy-ubuntu24.sh
```

First run installs Docker Engine + Compose plugin, creates `aidata` user, enables UFW
(22/80/443), copies `.env.example → .env` and **stops** for you to edit secrets. Fill:

```
APP_ENV=production  APP_DEBUG=false  APP_URL=https://data.example.com
POSTGRES_PASSWORD=<strong>  SERVICE_API_KEY=<token_urlsafe 48>  APP_KEY=<artisan key>
LLM_API_KEY / OPENROUTER_API_KEY=<real>  GRAFANA_ADMIN_PASSWORD=<strong>
```

Re-run the same script: builds, `up -d`, migrates, seeds, healthchecks, installs backup
cron (02:00 daily) + logrotate.

## 3. TLS (Certbot)

DNS must resolve, port 80 open, Nginx up. Then:

```bash
sudo certbot --nginx -d data.example.com
# renew: certbot renew --dry-run  (systemd timer preinstalled with certbot package)
```

After TLS, uncomment the HSTS `add_header Strict-Transport-Security` line in
`infrastructure/nginx/default.conf` and `docker compose restart nginx`.

## 4. Migrations / seeds / workers in prod

```bash
docker compose exec -T laravel php artisan migrate --force
docker compose exec -T laravel php artisan db:seed --force   # first boot only
docker compose ps   # celery-worker + celery-beat must be Up
docker compose up -d --scale celery-worker=3                 # scale training throughput
```

Zero-downtime-ish updates: `git pull && docker compose up -d --build && <migrate> &&
bash infrastructure/scripts/healthcheck.sh`.

## 5. Hardening checklist (with security.md)

UFW enabled; `APP_DEBUG=false`; strong unique secrets; Grafana signup off
(`GF_USERS_ALLOW_SIGN_UP=false` already); Postgres not published publicly
(remove `ports:` or bind `127.0.0.1:5432`); weekly `apt upgrade`; backup restores tested.

## 6. Rollback

Images are local builds — keep previous: `docker compose up -d` after `git checkout <tag>`.
Data rollback: `bash infrastructure/scripts/restore.sh backups/aidata_<ts>.sql.gz`
(see `backup-restore.md`). Verify with `healthcheck.sh` + `tests/run.sh`.
