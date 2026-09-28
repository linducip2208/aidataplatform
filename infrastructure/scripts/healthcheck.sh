#!/usr/bin/env bash
# AIDataPlatform healthcheck: laravel /up, fastapi /api/v1/health, postgres pg_isready, redis ping.
# Usage: bash infrastructure/scripts/healthcheck.sh
# Exit 0 = all healthy. Non-zero = at least one failure (details printed).
set -u

PASS=0; FAIL=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1${2:+ — $2}"; FAIL=$((FAIL+1)); }

echo "== AIDataPlatform healthcheck =="

# 1) Laravel /up (via compose exec so it works regardless of published ports)
echo "-- laravel (GET /up) --"
if docker compose exec -T laravel curl -fsS --max-time 10 http://localhost:8000/up >/dev/null 2>&1; then
  ok "laravel /up"
elif docker compose exec -T laravel curl -fsS --max-time 10 http://localhost:8000/health >/dev/null 2>&1; then
  ok "laravel /health (fallback)"
else
  bad "laravel /up" "try: docker compose logs laravel | tail -50"
fi

# 2) FastAPI /api/v1/health
echo "-- fastapi (GET /api/v1/health) --"
if docker compose exec -T fastapi python -c "import urllib.request; assert urllib.request.urlopen('http://localhost:8000/api/v1/health', timeout=10).status==200" >/dev/null 2>&1; then
  ok "fastapi /api/v1/health"
else
  bad "fastapi /api/v1/health" "try: docker compose logs fastapi | tail -50"
fi

# 3) Nginx front (optional, only if nginx running)
echo "-- nginx (GET /health) --"
if docker compose exec -T nginx wget -qO- http://localhost/health >/dev/null 2>&1; then
  ok "nginx /health"
else
  bad "nginx /health" "try: docker compose logs nginx | tail -20"
fi

# 4) Postgres pg_isready
echo "-- postgres (pg_isready) --"
if docker compose exec -T postgres pg_isready -U "${POSTGRES_USER:-aidata}" -d "${POSTGRES_DB:-aidata}" >/dev/null 2>&1; then
  ok "postgres pg_isready"
else
  # fallback without env (container defaults)
  if docker compose exec -T postgres pg_isready >/dev/null 2>&1; then
    ok "postgres pg_isready (defaults)"
  else
    bad "postgres pg_isready" "try: docker compose logs postgres | tail -30"
  fi
fi

# 5) Redis ping
echo "-- redis (PING) --"
if docker compose exec -T redis redis-cli ping 2>/dev/null | grep -qi pong; then
  ok "redis PING"
else
  # try with password from .env
  if [ -f .env ]; then set -a; source .env 2>/dev/null; set +a; fi
  if [ -n "${REDIS_PASSWORD:-}" ] && docker compose exec -T redis redis-cli -a "$REDIS_PASSWORD" ping 2>/dev/null | grep -qi pong; then
    ok "redis PING (authenticated)"
  else
    bad "redis PING" "try: docker compose logs redis | tail -20"
  fi
fi

# 6) Celery workers (presence check)
echo "-- celery --"
if docker compose ps celery-worker --format '{{.Status}}' 2>/dev/null | grep -qi 'up'; then
  ok "celery-worker running"
else
  bad "celery-worker" "try: docker compose logs celery-worker | tail -20"
fi
if docker compose ps celery-beat --format '{{.Status}}' 2>/dev/null | grep -qi 'up'; then
  ok "celery-beat running"
else
  bad "celery-beat" "try: docker compose logs celery-beat | tail -20"
fi

echo "== result: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
