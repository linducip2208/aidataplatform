#!/usr/bin/env bash
# AIDataPlatform integration checklist.
#
# Exercises the REAL contract: Laravel `/api/*` (token auth) and the FastAPI
# engine `/api/v1/*` (service-key auth). Every engine response is wrapped in
# `{"success": true, "data": ...}` — the parsing below unwraps `data`.
#
# Usage: bash tests/run.sh        (expects the docker stack to be up)
# Env:   BASE_LARAVEL, BASE_AI, SERVICE_API_KEY (read from .env when present)
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

BASE_LARAVEL="${BASE_LARAVEL:-http://localhost:8080}"
BASE_AI="${BASE_AI:-http://localhost:8001}"
if [ -f .env ]; then set -a; . ./.env 2>/dev/null; set +a; fi
KEY="${SERVICE_API_KEY:-}"

PY=python3
command -v python3 >/dev/null 2>&1 || PY=python

PASS=0; FAIL=0; WARN=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1${2:+ — $2}"; FAIL=$((FAIL+1)); }
warn() { echo "  [WARN] $1${2:+ — $2}"; WARN=$((WARN+1)); }

# jget <json> <expr>  — expr is evaluated with `d` bound to the parsed `data`
# envelope (or the whole body when there is no `success` key).
jget() {
  printf '%s' "$1" | "$PY" -c "
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print(''); sys.exit(0)
d = body.get('data', body) if isinstance(body, dict) else body
try:
    v = eval('''$2''')
except Exception:
    v = ''
print('' if v is None else v)
" 2>/dev/null
}

echo "== integration checklist =="
echo "laravel=$BASE_LARAVEL ai=$BASE_AI"

echo "-- [1] laravel /up --"
CODE=$(curl -s -o /tmp/aidata_up.json -w '%{http_code}' --max-time 10 "$BASE_LARAVEL/up" || echo 000)
[ "$CODE" = "200" ] && ok "laravel /up 200" || bad "laravel /up" "http=$CODE body=$(cat /tmp/aidata_up.json 2>/dev/null)"

echo "-- [2] fastapi /api/v1/health + /docs --"
CODE=$(curl -s -o /tmp/aidata_health.json -w '%{http_code}' --max-time 15 "$BASE_AI/api/v1/health" || echo 000)
[ "$CODE" = "200" ] && ok "fastapi /api/v1/health 200" || bad "fastapi /api/v1/health" "http=$CODE $(cat /tmp/aidata_health.json 2>/dev/null)"

CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_AI/docs" || echo 000)
[ "$CODE" = "200" ] && ok "fastapi /docs 200" || bad "fastapi /docs" "http=$CODE"

echo "-- [3] laravel token login --"
LOGIN=$(curl -s --max-time 15 -X POST "$BASE_LARAVEL/api/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"Admin123!"}' || echo '')
TOKEN=$(jget "$LOGIN" "d.get('token','')")
if [ -n "$TOKEN" ]; then
  ok "token issued for admin@example.com"
  AUTH="Authorization: Bearer $TOKEN"
else
  bad "laravel /api/login" "no token: $LOGIN"
  AUTH=""
fi

ME=$(curl -s --max-time 15 "$BASE_LARAVEL/api/me" -H "$AUTH" || echo '')
ROLE=$(jget "$ME" "d.get('role','')")
[ -n "$AUTH" ] && { [ "$ROLE" = "admin" ] && ok "GET /api/me role=admin" || bad "GET /api/me" "role=$ROLE body=$ME"; }

BADLOGIN=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -X POST "$BASE_LARAVEL/api/login" \
  -H 'Content-Type: application/json' -d '{"email":"admin@example.com","password":"wrong"}' || echo 000)
[ "$BADLOGIN" = "422" ] && ok "wrong password rejected 422" || bad "wrong password" "http=$BADLOGIN"

VIEWER=$(curl -s --max-time 15 -X POST "$BASE_LARAVEL/api/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"viewer@example.com","password":"Viewer123!"}' || echo '')
VTOKEN=$(jget "$VIEWER" "d.get('token','')")
if [ -n "$VTOKEN" ]; then
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$BASE_LARAVEL/api/datasets" \
    -H "Authorization: Bearer $VTOKEN" -F file=@tests/fixtures/sample_sales.csv -F dataset_type=sales || echo 000)
  [ "$CODE" = "403" ] && ok "viewer blocked from upload (403)" || bad "viewer upload should be 403" "http=$CODE"
else
  warn "viewer login failed — role check [4] skipped" "$VIEWER"
fi

if [ -z "$KEY" ]; then
  warn "SERVICE_API_KEY empty — skipping engine checks [5-8]"
  echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
  [ "$FAIL" -eq 0 ]; exit $?
fi

SK="X-Service-Key: $KEY"
if [ ! -f tests/fixtures/sample_sales.csv ]; then
  warn "fixture missing" "tests/fixtures/sample_sales.csv not found — skipping [5-8]"
  echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
  [ "$FAIL" -eq 0 ]; exit $?
fi

echo "-- [5] laravel upload -> engine import job --"
UP=$(curl -s --max-time 120 -X POST "$BASE_LARAVEL/api/datasets" -H "$AUTH" \
  -F file=@tests/fixtures/sample_sales.csv -F name=smoke_test -F dataset_type=sales || echo '')
DS_UUID=$(jget "$UP" "d.get('id','')")
JOB=$(jget "$UP" "d.get('import_job_id','')")
if [ -n "$DS_UUID" ]; then
  ok "dataset created uuid=$DS_UUID import_job_id=$JOB"
else
  bad "POST /api/datasets" "no dataset id: $UP"
fi

echo "-- [6] import job poll --"
if [ -n "$JOB" ]; then
  for i in $(seq 1 12); do
    ST=$(curl -s --max-time 10 "$BASE_LARAVEL/api/import-jobs/$JOB" -H "$AUTH" || echo '')
    S=$(jget "$ST" "d.get('status','')")
    P=$(jget "$ST" "d.get('progress','')")
    echo "   poll $i: status=$S progress=$P"
    case "$S" in
      succeeded|success|completed) ok "import job succeeded"; break;;
      failed) bad "import job failed" "$ST"; break;;
    esac
    [ "$i" = "12" ] && warn "import still ${S:-?} after ~60s" "worker may be slow — check celery-worker logs"
    sleep 5
  done
else
  warn "no import_job_id — skipping job poll"
fi

echo "-- [7] data quality --"
if [ -n "$DS_UUID" ]; then
  Q=$(curl -s --max-time 60 "$BASE_LARAVEL/api/datasets/$DS_UUID/quality" -H "$AUTH" || echo '')
  SCORE=$(jget "$Q" "d.get('score','')")
  VERDICT=$(jget "$Q" "d.get('verdict','')")
  echo "   quality: score=$SCORE verdict=$VERDICT"
  [ -n "$SCORE" ] && ok "quality report returned score=$SCORE verdict=$VERDICT" \
    || bad "quality report" "$Q"
else
  warn "no dataset uuid — skipping quality"
fi

echo "-- [8] analytics + engine direct --"
KPI=$(curl -s --max-time 30 "$BASE_LARAVEL/api/analytics/kpi" -H "$AUTH" || echo '')
echo "$KPI" | grep -q 'revenue' && ok "GET /api/analytics/kpi" || warn "kpi empty (no committed rows yet)" "$KPI"

MODELS=$(curl -s --max-time 20 "$BASE_AI/api/v1/models" -H "$SK" || echo '')
echo "$MODELS" | grep -q '"success"' && ok "GET /api/v1/models" || bad "GET /api/v1/models" "$MODELS"

NOKEY=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE_AI/api/v1/models" || echo 000)
[ "$NOKEY" = "403" ] && ok "engine rejects missing service key (403)" \
  || warn "engine accepted a request without X-Service-Key" "http=$NOKEY (service auth may be disabled)"

RAG=$(curl -s --max-time 60 -X POST "$BASE_AI/api/v1/rag/query" -H "$SK" \
  -H 'Content-Type: application/json' -d '{"query":"penjualan terbaru","top_k":4}' || echo '')
echo "$RAG" | grep -q 'answer' && ok "engine rag/query answered" || warn "rag empty (nothing indexed yet)" "$RAG"

echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
[ "$FAIL" -eq 0 ]
