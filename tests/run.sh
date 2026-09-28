#!/usr/bin/env bash
# AIDataPlatform integration checklist (curl-only contract smoke).
# Usage: bash tests/run.sh  (expects docker stack up; reads SERVICE_API_KEY from .env)
set -u
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

BASE_LARAVEL="${BASE_LARAVEL:-http://localhost:8080}"
BASE_AI="${BASE_AI:-http://localhost:8001}"
if [ -f .env ]; then set -a; source .env 2>/dev/null; set +a; fi
KEY="${SERVICE_API_KEY:-}"

PASS=0; FAIL=0; WARN=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1${2:+ — $2}"; FAIL=$((FAIL+1)); }
warn() { echo "  [WARN] $1${2:+ — $2}"; WARN=$((WARN+1)); }

echo "== integration checklist =="
echo "laravel=$BASE_LARAVEL ai=$BASE_AI"

echo "-- [1] laravel /up --"
CODE=$(curl -s -o /tmp/aidata_up.json -w '%{http_code}' --max-time 10 "$BASE_LARAVEL/up" || echo 000)
[ "$CODE" = "200" ] && ok "laravel /up 200" || bad "laravel /up" "http=$CODE body=$(cat /tmp/aidata_up.json 2>/dev/null)"

echo "-- [2] fastapi /api/v1/health --"
CODE=$(curl -s -o /tmp/aidata_health.json -w '%{http_code}' --max-time 15 "$BASE_AI/api/v1/health" || echo 000)
[ "$CODE" = "200" ] && ok "fastapi /health 200 $(cat /tmp/aidata_health.json)" || bad "fastapi /health" "http=$CODE"

echo "-- [3] fastapi /docs --"
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_AI/docs" || echo 000)
[ "$CODE" = "200" ] && ok "fastapi /docs 200" || bad "fastapi /docs" "http=$CODE"

if [ -z "$KEY" ]; then
  warn "SERVICE_API_KEY empty — skipping authenticated checks [4-7]"
  echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
  [ "$FAIL" -eq 0 ]; exit $?
fi

echo "-- [4] ingest smoke --"
if [ ! -f tests/fixtures/sample_sales.csv ]; then
  warn "fixture missing" "tests/fixtures/sample_sales.csv not found — skipping [4-7]"
else
  RESP=$(curl -s --max-time 60 -X POST "$BASE_AI/api/v1/ingest" -H "X-Service-Key: $KEY" \
    -F file=@tests/fixtures/sample_sales.csv -F dataset_name=smoke_test || echo '')
  echo "   ingest resp: $RESP"
  JOB=$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('job_id',''))" 2>/dev/null || echo '')
  DS=$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('dataset_id',''))" 2>/dev/null || echo '')
  [ -n "$JOB" ] && ok "ingest queued job=$JOB" || bad "ingest" "no job_id in: $RESP"
  if [ -n "$JOB" ]; then
    for i in $(seq 1 12); do
      ST=$(curl -s --max-time 10 "$BASE_AI/api/v1/jobs/$JOB" -H "X-Service-Key: $KEY" || echo '')
      S=$(echo "$ST" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status','?'))" 2>/dev/null || echo '?')
      echo "   poll $i: status=$S"
      case "$S" in succeeded) ok "import job succeeded"; break;; failed) bad "import job failed" "$ST"; break;; esac
      [ "$i" = "12" ] && warn "import still $S after ~60s" "worker may be slow — check celery-worker logs"
      sleep 5
    done
  fi

  echo "-- [5] quality --"
  if [ -n "${DS:-}" ]; then
    curl -s --max-time 30 -X POST "$BASE_AI/api/v1/quality/run" -H "X-Service-Key: $KEY" \
      -H 'Content-Type: application/json' -d "{\"dataset_id\":\"$DS\"}" || true; echo
    Q=$(curl -s --max-time 30 "$BASE_AI/api/v1/quality/$DS" -H "X-Service-Key: $KEY" || echo '')
    echo "   quality: $Q"
    echo "$Q" | grep -q '"score"' && ok "quality report has score" || warn "quality report pending" "$Q"
  else
    warn "no dataset_id from ingest — skipping quality detail"
  fi

  echo "-- [6] ml train dry-run --"
  if [ -n "${DS:-}" ]; then
    ML=$(curl -s --max-time 30 -X POST "$BASE_AI/api/v1/ml/train" -H "X-Service-Key: $KEY" \
      -H 'Content-Type: application/json' \
      -d "{\"dataset_id\":\"$DS\",\"target\":\"amount\",\"task\":\"regression\",\"model\":\"auto\"}" || echo '')
    echo "   ml resp: $ML"
    echo "$ML" | grep -q 'job_id' && ok "ml train queued" || warn "ml train not queued" "$ML"
  else
    warn "no dataset_id — skipping ml"
  fi

  echo "-- [7] rag query skeleton --"
  if [ -n "${DS:-}" ]; then
    RAG=$(curl -s --max-time 60 -X POST "$BASE_AI/api/v1/rag/query" -H "X-Service-Key: $KEY" \
      -H 'Content-Type: application/json' \
      -d "{\"dataset_id\":\"$DS\",\"question\":\"Total sales?\",\"top_k\":4}" || echo '')
    echo "   rag resp: $RAG"
    echo "$RAG" | grep -q 'answer' && ok "rag answered" || warn "rag empty/unindexed" "$RAG"
  fi
fi

echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
[ "$FAIL" -eq 0 ]
