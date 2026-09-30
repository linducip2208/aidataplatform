#!/bin/sh
# AIDataPlatform — idempotent aaPanel/native-Linux production deploy.
#
# Run as root on the production host. Safe to re-run: every step is a
# no-op when its result already exists. Docker is NOT used here; see
# infrastructure/scripts/deploy-ubuntu24.sh for the container path.
#
# Usage:
#   sudo APP_DIR=/www/wwwroot/aidata DOMAIN=data.example.com \
#        APP_USER=www PHP_BIN=/www/server/php/83/bin/php \
#        PYTHON_BIN=/usr/bin/python3.13 \
#        bash infrastructure/aapanel/deploy-aapanel.sh
#
# Required secrets come from the environment (never from CLI history alone):
#   MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD, SERVICE_API_KEY, APP_KEY (generated
#   with `php artisan key:generate --show` when empty), GRAFANA_ADMIN_PASSWORD
#   (monitoring only). Missing secrets abort before anything is changed.
#
# EXIT CODES: 0 done, 2 precondition missing, 3 step failed, 10 .env created
# and needs values (re-run after filling them).
set -eu

EXIT_OK=0
EXIT_PRECONDITION=2
EXIT_STEP_FAILED=3
EXIT_NEEDS_ENV=10

APP_DIR="${APP_DIR:-/www/wwwroot/aidata}"
APP_USER="${APP_USER:-www}"
DOMAIN="${DOMAIN:-}"
PHP_BIN="${PHP_BIN:-/www/server/php/83/bin/php}"
PYTHON_BIN="${PYTHON_BIN:-/usr/bin/python3}"
REPO_URL="${REPO_URL:-}"

step() { echo; echo "=== [$1/9] $2 ==="; }
fatal() { echo; echo "FATAL: $*" >&2; exit "$EXIT_STEP_FAILED"; }
need() { echo; echo "FATAL: $*" >&2; exit "$EXIT_PRECONDITION"; }

case "$APP_DIR" in /*) ;; *) need "APP_DIR must be absolute" ;; esac
case "$APP_DIR" in *[!A-Za-z0-9_./-]*) need "APP_DIR has unsafe characters" ;; esac
case "$APP_USER" in *[!A-Za-z0-9_-]*|root|"") need "APP_USER must be a non-root account name" ;; esac
if [ -n "$DOMAIN" ]; then
    case "$DOMAIN" in *.*) ;; *) need "DOMAIN must be a hostname" ;; esac
    case "$DOMAIN" in *[!A-Za-z0-9.-]*) need "DOMAIN has unsafe characters" ;; esac
fi

step 1 "preconditions"
[ "$(id -u)" = "0" ] || need "run as root"
command -v "$PHP_BIN" >/dev/null 2>&1 || need "PHP not found at $PHP_BIN (aaPanel: install PHP 8.3)"
PHP_MAJOR_MINOR=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
case "$PHP_MAJOR_MINOR" in 8.3|8.4|8.5|8.6) ;; *) need "PHP 8.3+ required, found $PHP_MAJOR_MINOR" ;; esac
for ext in mbstring pdo_mysql bcmath intl zip gd redis pcntl; do
    "$PHP_BIN" -m | grep -qi "^$ext$" || need "PHP extension missing: $ext (aaPanel: install it for PHP $PHP_MAJOR_MINOR)"
done
command -v composer >/dev/null 2>&1 || need "composer not found"
command -v "$PYTHON_BIN" >/dev/null 2>&1 || need "python3 not found at $PYTHON_BIN"
command -v mysql >/dev/null 2>&1 || need "mysql client not found"
command -v redis-cli >/dev/null 2>&1 || need "redis-cli not found (aaPanel: install Redis)"
command -v supervisorctl >/dev/null 2>&1 || need "supervisorctl not found (aaPanel: Supervisor Manager plugin)"
command -v node >/dev/null 2>&1 || need "node 20+ not found (aaPanel: Node version manager)"
[ -d "$APP_DIR/.git" ] || {
    [ -n "$REPO_URL" ] || need "$APP_DIR is not a repo checkout; set REPO_URL to clone it"
    git clone "$REPO_URL" "$APP_DIR" || fatal "git clone failed"
}

step 2 "laravel dependencies and frontend"
cd "$APP_DIR/application"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || fatal "composer install failed"
npm ci || fatal "npm ci failed (package-lock.json must be committed)"
npm run build || fatal "vite build failed"

step 3 "laravel environment"
if [ ! -f "$APP_DIR/application/.env" ]; then
    cp "$APP_DIR/application/.env.example" "$APP_DIR/application/.env"
    echo "Created application/.env from the example."
fi
if [ ! -f "$APP_DIR/ai-engine/.env" ]; then
    cp "$APP_DIR/ai-engine/.env.example" "$APP_DIR/ai-engine/.env"
    echo "Created ai-engine/.env from the example."
fi
for var in MYSQL_PASSWORD MYSQL_ROOT_PASSWORD SERVICE_API_KEY; do
    eval "val=\${$var:-}"
    case "$val" in ""|*change-me*|*changeme*) echo "Set $var before deploying." >&2; MISSING=1 ;; esac
done
if [ "${MISSING:-}" = "1" ]; then
    echo "Fill the secrets above, then re-run this script." >&2
    exit "$EXIT_NEEDS_ENV"
fi

step 4 "laravel key, storage, migrations, seed"
if ! grep -q "^APP_KEY=base64:[^[:space:]]" "$APP_DIR/application/.env"; then
    KEY=$("$PHP_BIN" artisan key:generate --show) || fatal "key generation failed"
    sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" "$APP_DIR/application/.env"
fi
"$PHP_BIN" artisan storage:link || true
"$PHP_BIN" artisan migrate --force || fatal "laravel migrate failed"
"$PHP_BIN" artisan db:seed --force || fatal "laravel seed failed"
"$PHP_BIN" artisan config:cache || true
"$PHP_BIN" artisan route:cache || true
"$PHP_BIN" artisan view:cache || true

step 5 "python engine"
cd "$APP_DIR/ai-engine"
if [ ! -x "$APP_DIR/ai-engine/.venv/bin/python" ]; then
    "$PYTHON_BIN" -m venv "$APP_DIR/ai-engine/.venv" || fatal "venv creation failed"
fi
"$APP_DIR/ai-engine/.venv/bin/pip" install -r requirements.txt || fatal "pip install failed"
SYNC_DATABASE_URL="${SYNC_DATABASE_URL:-}" "$APP_DIR/ai-engine/.venv/bin/python" -m alembic upgrade head \
    || fatal "alembic upgrade failed"

step 6 "supervisor processes"
for svc in aidata-laravel-queue aidata-fastapi aidata-celery-worker aidata-celery-beat; do
    src="$APP_DIR/infrastructure/aapanel/supervisor/$svc.conf"
    [ -f "$src" ] || fatal "missing supervisor template $src"
    dest="/www/server/panel/plugin/supervisor/conf/$svc.conf"
    if [ ! -f "$dest" ]; then
        sed -e "s|{{APP_USER}}|$APP_USER|g" -e "s|{{APP_DIR}}|$APP_DIR|g" \
            -e "s|{{PHP_BIN}}|$PHP_BIN|g" "$src" > "$dest" \
            || fatal "cannot install $dest"
        echo "Installed $dest — review it once, then re-run."
    fi
done
supervisorctl reread >/dev/null || fatal "supervisor reread failed"
supervisorctl update || fatal "supervisor update failed"

step 7 "ownership and permissions"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/application/storage" "$APP_DIR/application/bootstrap/cache" || fatal "chown failed"
chmod -R 775 "$APP_DIR/application/storage" "$APP_DIR/application/bootstrap/cache"

step 8 "cron (printed for aaPanel > Cron; --with-cron installs to root crontab)"
echo "Add these lines in aaPanel > Cron (type: Shell script):"
sed -e "s|{{APP_USER}}|$APP_USER|g" -e "s|{{APP_DIR}}|$APP_DIR|g" -e "s|{{PHP_BIN}}|$PHP_BIN|g" \
    "$APP_DIR/infrastructure/aapanel/cron.txt"

step 9 "healthcheck"
sleep 5
"$PHP_BIN" "$APP_DIR/application/artisan" up 2>/dev/null || true
curl -fsS -o /dev/null "http://127.0.0.1:8001/api/v1/health" \
    || echo "WARN: fastapi not answering yet — check supervisor status"
supervisorctl status aidata-laravel-queue aidata-fastapi aidata-celery-worker aidata-celery-beat || true

echo; echo "Deploy finished. Point the aaPanel site root at $APP_DIR/application/public,"
echo "install infrastructure/aapanel/nginx/aidata.conf, issue SSL, then re-run to verify."
exit "$EXIT_OK"
