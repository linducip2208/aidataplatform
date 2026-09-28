#!/bin/sh
# AIDataPlatform healthcheck.
#
# Probes only endpoints that exist in this tree:
#   laravel  GET  /up                     (bootstrap/app.php health: '/up')
#   laravel  POST /api/login + GET /api/me  (token API, tests/run.sh contract)
#   engine   GET  /api/v1/health          (ai-engine/app/api/v1/health.py)
#   engine   GET  /api/v1/readiness       (same router; 200 even when not ready)
#   engine   GET  /ai-api/api/v1/health   through nginx, proving the prefix strip
#   nginx    GET  /health
#   postgres      pg_isready
#   redis         PING
#   celery   worker + beat processes present
#
# Usage: bash infrastructure/scripts/healthcheck.sh   (from anywhere)
# Exit 0 when nothing failed. Warnings do not affect the exit code.
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR" || exit 1

# Load credentials once, up front. The previous version read POSTGRES_USER before
# sourcing .env, so it always probed the default role and failed on any install
# that renamed it.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi

PGUSER="${POSTGRES_USER:-aidata}"
PGDB="${POSTGRES_DB:-aidata}"

PASS=0
FAIL=0
WARN=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS + 1)); }
bad()  { echo "  [FAIL] $1${2:+ - $2}"; FAIL=$((FAIL + 1)); }
warn() { echo "  [WARN] $1${2:+ - $2}"; WARN=$((WARN + 1)); }

echo "== AIDataPlatform healthcheck =="

# 1) Laravel framework probe -----------------------------------------------------
echo "-- laravel (GET /up) --"
if docker compose exec -T laravel curl -fsS -o /dev/null --max-time 10 http://localhost:8000/up; then
    ok "laravel /up"
else
    bad "laravel /up" "docker compose logs laravel | tail -50"
fi

# 2) Laravel token API -----------------------------------------------------------
# A 401/422 means auth is answering correctly but the seeded demo user is absent
# or the password changed; that is a data problem, not an outage, so it warns.
# Any other outcome (connection refused, 500) is a real failure.
echo "-- laravel token API (POST /api/login, GET /api/me) --"
login_code=$(docker compose exec -T laravel curl -sS -o /tmp/aidata-login.json -w '%{http_code}' \
    --max-time 15 -X POST http://localhost:8000/api/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"Admin123!"}' 2>/dev/null || echo 000)
if [ "$login_code" = "200" ]; then
    token=$(tr ',' '\n' < /tmp/aidata-login.json 2>/dev/null | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' | head -n 1)
    if [ -z "$token" ]; then
        warn "POST /api/login" "200 but no token in the response body"
    else
        me_code=$(docker compose exec -T laravel curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
            http://localhost:8000/api/me -H "Authorization: Bearer $token" 2>/dev/null || echo 000)
        if [ "$me_code" = "200" ]; then
            ok "laravel token API (login + /api/me)"
        else
            bad "GET /api/me" "http=$me_code"
        fi
    fi
elif [ "$login_code" = "422" ] || [ "$login_code" = "401" ]; then
    warn "POST /api/login" "http=$login_code (no seeded admin@example.com? run: docker compose exec laravel php artisan db:seed --force)"
elif [ "$login_code" = "000" ]; then
    bad "POST /api/login" "laravel unreachable"
else
    bad "POST /api/login" "http=$login_code"
fi

# 3) Engine liveness -------------------------------------------------------------
echo "-- engine (GET /api/v1/health) --"
if docker compose exec -T fastapi python -c "import urllib.request; assert urllib.request.urlopen('http://localhost:8000/api/v1/health', timeout=10).status==200"; then
    ok "engine /api/v1/health"
else
    bad "engine /api/v1/health" "docker compose logs fastapi | tail -50"
fi

# 4) Engine dependencies ---------------------------------------------------------
# /api/v1/readiness answers 200 even when the database is unreachable, so the
# body has to be read: {"ready": true|false, "checks": {"db": ..., "redis": ...}}
echo "-- engine (GET /api/v1/readiness) --"
if readiness=$(docker compose exec -T fastapi python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8000/api/v1/readiness', timeout=15).read().decode())" 2>/dev/null); then
    ready_flag=$(printf '%s' "$readiness" | tr ',' '\n' | sed -n 's/.*"ready"://p' | head -n 1 | tr -d ' {}"')
    case "$ready_flag" in
        true)  ok "engine /api/v1/readiness ready=true" ;;
        false) bad "engine /api/v1/readiness" "ready=false; checks: $readiness" ;;
        *)     warn "engine /api/v1/readiness" "unparsable body: $readiness" ;;
    esac
else
    bad "engine /api/v1/readiness" "request failed"
fi

# 5) Nginx prefix strip ----------------------------------------------------------
# The engine is not published on a host port, so this is the only way to prove
# the /ai-api -> /api/v1 rewrite actually works from outside.
echo "-- nginx (GET /ai-api/api/v1/health via the /ai-api prefix) --"
if docker compose exec -T nginx wget -qO- --timeout=10 http://localhost/ai-api/api/v1/health | grep -q '"status"'; then
    ok "nginx /ai-api/ prefix is stripped to /api/v1/"
else
    bad "nginx /ai-api/api/v1/health" "docker compose logs nginx | tail -20"
fi

# 6) Nginx liveness --------------------------------------------------------------
echo "-- nginx (GET /health) --"
if docker compose exec -T nginx wget -qO- --timeout=10 http://localhost/health >/dev/null; then
    ok "nginx /health"
else
    bad "nginx /health" "docker compose logs nginx | tail -20"
fi

# 7) Postgres --------------------------------------------------------------------
echo "-- postgres (pg_isready) --"
if docker compose exec -T postgres pg_isready -U "$PGUSER" -d "$PGDB" >/dev/null 2>&1; then
    ok "postgres pg_isready ($PGUSER/$PGDB)"
else
    bad "postgres pg_isready ($PGUSER/$PGDB)" "docker compose logs postgres | tail -30"
fi

# 8) Redis -----------------------------------------------------------------------
# redis-cli needs the password whenever REDIS_PASSWORD is set; the base compose
# healthcheck probes without one, so an authenticated install looks unhealthy.
echo "-- redis (PING) --"
if [ -n "${REDIS_PASSWORD:-}" ]; then
    redis_ping=$(docker compose exec -T redis redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping 2>/dev/null || true)
else
    redis_ping=$(docker compose exec -T redis redis-cli ping 2>/dev/null || true)
fi
case "$redis_ping" in
    *PONG*) ok "redis PING" ;;
    *)      bad "redis PING" "reply='$redis_ping'; docker compose logs redis | tail -20" ;;
esac

# 9) Celery ----------------------------------------------------------------------
echo "-- celery --"
for svc in celery-worker celery-beat; do
    status=$(docker compose ps "$svc" --format '{{.Status}}' 2>/dev/null | head -n 1)
    case "$status" in
        Up*|running*) ok "$svc $status" ;;
        *)            bad "$svc" "status='$status'; docker compose logs $svc | tail -20" ;;
    esac
done

echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
[ "$FAIL" -eq 0 ]
