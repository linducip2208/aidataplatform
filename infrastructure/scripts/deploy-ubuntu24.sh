#!/usr/bin/env bash
# AIDataPlatform — idempotent Ubuntu 24.04 production deploy.
# Run as root (or sudo) on a fresh Ubuntu 24.04 VM. Safe to re-run.
# Usage: sudo bash infrastructure/scripts/deploy-ubuntu24.sh
# Assumes repo already cloned to /opt/aidataplatform (or set APP_DIR).
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/aidataplatform}"
APP_USER="${APP_USER:-aidata}"
DOMAIN="${DOMAIN:-}"   # e.g. data.example.com — if set, certbot hint runs

echo "=== [1/9] base packages ==="
apt-get update -y
apt-get install -y ca-certificates curl gnupg git make ufw logrotate cron certbot python3-certbot-nginx 2>/dev/null || \
apt-get install -y ca-certificates curl gnupg git make ufw logrotate cron

echo "=== [2/9] docker engine (official repo) ==="
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
else
  echo "docker already installed: $(docker --version)"
fi

echo "=== [3/9] app user + dir ==="
id "$APP_USER" >/dev/null 2>&1 || useradd -r -m -s /bin/bash "$APP_USER"
mkdir -p "$APP_DIR" ./backups
chown -R "$APP_USER":"$APP_USER" "$APP_DIR" 2>/dev/null || true
cd "$APP_DIR"

echo "=== [4/9] firewall (ufw) ==="
ufw allow OpenSSH || true
ufw allow 80/tcp || true
ufw allow 443/tcp || true
ufw --force enable || true
ufw status || true

echo "=== [5/9] env file ==="
if [ ! -f .env ]; then
  cp .env.example .env
  echo "!! EDIT .env now (POSTGRES_PASSWORD, SERVICE_API_KEY, LLM keys, APP_KEY, GRAFANA pw) then re-run script."
  echo "   Generate service key: python3 -c 'import secrets; print(secrets.token_urlsafe(48))'"
  exit 0
fi
grep -q '^APP_DEBUG=false' .env || echo "WARN: set APP_DEBUG=false in .env for production"

echo "=== [6/9] build + up ==="
docker compose up -d --build
sleep 10
docker compose ps

echo "=== [7/9] migrations + seed (first boot only seeds if empty) ==="
docker compose exec -T laravel php artisan migrate --force || echo "WARN: laravel migrate failed — check logs"
docker compose exec -T laravel php artisan db:seed --force || echo "WARN: seed skipped/failed (may already be seeded)"
bash infrastructure/scripts/healthcheck.sh || echo "WARN: healthcheck failed — inspect docker compose logs"

echo "=== [8/9] ssl (certbot hint) ==="
if [ -n "$DOMAIN" ]; then
  echo "To issue TLS (requires DNS $DOMAIN -> this server + nginx on 80):"
  echo "  certbot --nginx -d $DOMAIN"
  echo "Then uncomment HSTS header in infrastructure/nginx/default.conf and 'docker compose restart nginx'."
else
  echo "Set DOMAIN=... and re-run for TLS hint, or run: certbot --nginx -d <your-domain>"
fi

echo "=== [9/9] backup cron + logrotate ==="
CRON_LINE="0 2 * * * $APP_USER cd $APP_DIR && BACKUP_DIR=$APP_DIR/backups bash infrastructure/scripts/backup.sh >> $APP_DIR/backups/cron.log 2>&1"
( crontab -u "$APP_USER" -l 2>/dev/null | grep -v 'backup.sh' ; echo "$CRON_LINE" ) | crontab -u "$APP_USER" - || echo "WARN: cron install failed"
cat > /etc/logrotate.d/aidata-nginx <<'EOF'
/opt/aidataplatform/backups/cron.log {
  weekly
  rotate 8
  compress
  missingok
  notifempty
}
EOF
echo "=== deploy complete. Check: docker compose ps && bash infrastructure/scripts/healthcheck.sh ==="
