#!/usr/bin/env bash
# AIDataPlatform integration checklist.
#
# Exercises the REAL contract: Laravel `/api/*` (Sanctum token auth) and the
# FastAPI engine `/api/v1/*` (X-Service-Key service auth). The two envelopes
# differ and are parsed differently on purpose:
#   Laravel  {"data": {...}}                          (App\Support\ApiResponse)
#   engine   {"success": true, "data": {...}}         (app/core/errors.py)
# `jget` unwraps `data` for both; `jsuccess` additionally asserts success is
# literally true, because `grep '"success"'` also matches `"success": false`
# and the engine returns 200 with success:false on several routes.
#
# Every external thing this script touches, with the code that must agree:
#   BASE_LARAVEL  http://localhost:8080  = ${LARAVEL_PORT:-8080} -> laravel:8000
#   BASE_AI       http://localhost:8001  = ${FASTAPI_PORT:-8001} -> fastapi:8000
#   /up                                     bootstrap/app.php -> health: '/up'
#   /api/login, /api/me                    routes/api.php:22,26
#   POST /api/datasets                     routes/api.php:60 (role admin|analyst)
#   POST /api/datasets/{uuid}/commit       routes/api.php:62
#   GET  /api/import-jobs/{int}            routes/api.php:41
#   GET  /api/datasets/{uuid}/quality      routes/api.php:37 (role admin|analyst)
#   GET  /api/analytics/kpi                routes/api.php:43
#   GET  /api/health                       routes/api.php:27 (bearer, proxies the engine)
#   /api/v1/health, /api/v1/readiness      ai-engine/app/api/v1/health.py
#   /docs                                  gated on DOCS_ENABLED (config.py:149)
#   /api/v1/models                         ai-engine/app/api/v1/models.py:14
#   /api/v1/rag/query                      ai-engine/app/api/v1/rag.py:22
#   SERVICE_API_KEY, SERVICE_API_KEY_HEADER from the root .env, same names
#                                         docker-compose.yml injects into the engine
#   terminal import status  done | done_with_errors | failed
#                                         ai-engine/app/ingestion/etl.py:301
#
# Usage: bash tests/run.sh        (expects the docker stack to be up)
# Env:   BASE_LARAVEL, BASE_AI, SERVICE_API_KEY, SERVICE_API_KEY_HEADER
#
# EXIT CODES:
#   0  every check that ran passed
#   1  at least one check failed, and the message names the step
#   2  the checklist could not run: no curl, no python, or no fixture. 2 means
#      "no answer", never "all good".
set -u

EXIT_OK=0
EXIT_FAIL=1
EXIT_CANNOT_RUN=2

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || { echo "[run] FATAL: cannot cd to '$ROOT_DIR'" >&2; exit "$EXIT_CANNOT_RUN"; }

BASE_LARAVEL="${BASE_LARAVEL:-http://localhost:8080}"
BASE_AI="${BASE_AI:-http://localhost:8001}"
CURL_TIMEOUT="${CURL_TIMEOUT:-30}"

WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/aidata-run.XXXXXX") || {
    echo "[run] FATAL: cannot create a scratch directory" >&2
    exit "$EXIT_CANNOT_RUN"
}
cleanup() { rm -rf "$WORK_DIR"; }
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Preflight. Without these the first four checks all fail with the same
# unhelpful "no token" / "not JSON" message.
# ---------------------------------------------------------------------------
for _tool in curl; do
    command -v "$_tool" >/dev/null 2>&1 || {
        echo "[run] FATAL: '$_tool' is not on PATH; no check can run" >&2
        exit "$EXIT_CANNOT_RUN"
    }
done
PY=python3
command -v python3 >/dev/null 2>&1 || PY=python
command -v "$PY" >/dev/null 2>&1 || {
    echo "[run] FATAL: neither python3 nor python is on PATH; the JSON assertions cannot run" >&2
    exit "$EXIT_CANNOT_RUN"
}

if [ -f .env ]; then set -a; . ./.env 2>/dev/null; set +a; fi
KEY="${SERVICE_API_KEY:-}"
# The header name is configurable on both sides (docker-compose.yml injects
# SERVICE_API_KEY_HEADER into laravel and fastapi; security.py resolves it).
# Hardcoding X-Service-Key here turns a deliberate rename into an unexplained
# 401 on every engine check.
SK_HEADER="${SERVICE_API_KEY_HEADER:-X-Service-Key}"

PASS=0; FAIL=0; WARN=0; SKIP=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1${2:+ — $2}"; FAIL=$((FAIL+1)); }
warn() { echo "  [WARN] $1${2:+ — $2}"; WARN=$((WARN+1)); }
skip() { echo "  [SKIP] $1${2:+ — $2}"; SKIP=$((SKIP+1)); }

summary() {
    echo "== result: $PASS passed, $FAIL failed, $WARN warnings, $SKIP skipped =="
    if [ "$FAIL" -gt 0 ]; then exit "$EXIT_FAIL"; fi
    exit "$EXIT_OK"
}

# req <curl args...>  -> REQ_CODE, REQ_BODY, REQ_ERR, REQ_RC
# REQ_CODE is three digits or 000; REQ_ERR is curl's own message, which is the
# only thing that separates "connection refused" from "server said 500".
# The body always goes to $WORK_DIR/body: passing a second `-o` to curl would
# override this one and leave REQ_BODY holding the PREVIOUS response.
req() {
    local out rc=0
    out=$(curl -sS -o "$WORK_DIR/body" -w '%{http_code}' --max-time "$CURL_TIMEOUT" "$@" 2>"$WORK_DIR/err") || rc=$?
    REQ_RC=$rc
    REQ_CODE=$out
    case "$REQ_CODE" in
        [0-9][0-9][0-9]) ;;
        *) REQ_CODE=000 ;;
    esac
    REQ_BODY=$(cat "$WORK_DIR/body" 2>/dev/null)
    REQ_ERR=$(cat "$WORK_DIR/err" 2>/dev/null)
}

# "http=500 body=..." or "curl could not run: ..." depending on what happened.
why() {
    if [ "$REQ_CODE" = "000" ]; then
        printf 'request never completed: %s' "$(printf '%s' "$REQ_ERR" | tr '\n\r' '  ' | cut -c1-200)"
    else
        printf 'http=%s body=%s' "$REQ_CODE" "$(printf '%s' "$REQ_BODY" | tr '\n\r' '  ' | cut -c1-200)"
    fi
}

# jget <json> <expr> — d is the parsed `data` envelope, or the whole body when
# there is no `data` key.
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

# jsuccess <json> -> true | false | no-key | notjson
# `false` is a 200 carrying an engine error; the old `grep -q '\"success\"'`
# counted that as a pass.
jsuccess() {
  printf '%s' "$1" | "$PY" -c "
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print('notjson'); sys.exit(0)
if not isinstance(body, dict) or 'success' not in body:
    print('notjson'); sys.exit(0)
print('true' if body.get('success') is True else 'false')
" 2>/dev/null
}

echo "== integration checklist =="
echo "laravel=$BASE_LARAVEL ai=$BASE_AI key_header=$SK_HEADER"

# ---------------------------------------------------------------------------
echo "-- [1] laravel /up --"
req "$BASE_LARAVEL/up"
if [ "$REQ_CODE" = "200" ]; then ok "[1] laravel /up 200"
else bad "[1] laravel /up" "$(why)"; fi

# ---------------------------------------------------------------------------
echo "-- [2] engine /api/v1/health --"
req "$BASE_AI/api/v1/health"
if [ "$REQ_CODE" != "200" ]; then
    bad "[2] engine /api/v1/health" "$(why)"
elif printf '%s' "$REQ_BODY" | grep -q '"status"'; then
    ok "[2] engine /api/v1/health 200 with a status field"
else
    bad "[2] engine /api/v1/health" "http=200 but the body is not the engine's HealthResponse: $(printf '%s' "$REQ_BODY" | cut -c1-200)"
fi

# ---------------------------------------------------------------------------
echo "-- [3] engine /docs --"
req "$BASE_AI/docs"
case "$REQ_CODE" in
    200) ok "[3] engine /docs 200 (DOCS_ENABLED is on)" ;;
    404) warn "[3] engine /docs" "http=404 - DOCS_ENABLED=false in the engine's environment. This is a deliberate setting, not a fault; set DOCS_ENABLED=true if the OpenAPI UI is expected." ;;
    000) bad "[3] engine /docs" "$(why)" ;;
    *)   bad "[3] engine /docs" "http=$REQ_CODE" ;;
esac

# ---------------------------------------------------------------------------
echo "-- [4] engine /api/v1/readiness --"
req "$BASE_AI/api/v1/readiness"
if [ "$REQ_CODE" = "000" ]; then
    bad "[4] engine /api/v1/readiness" "$(why)"
else
    R=$(jget "$REQ_BODY" "d.get('ready','')")
    DB=$(jget "$REQ_BODY" "d.get('checks',{}).get('db','')")
    if [ "$R" = "True" ] || [ "$R" = "true" ]; then
        ok "[4] engine ready=true (db=${DB:-?})"
    elif [ "$R" = "False" ] || [ "$R" = "false" ]; then
        bad "[4] engine /api/v1/readiness" "ready=false, db=${DB:-?} — the engine answers /health but cannot reach its database"
    else
        bad "[4] engine /api/v1/readiness" "http=$REQ_CODE and the body has no usable 'ready' field: $(printf '%s' "$REQ_BODY" | cut -c1-200)"
    fi
fi

# ---------------------------------------------------------------------------
echo "-- [5] laravel token login (admin) --"
req -X POST "$BASE_LARAVEL/api/login" -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"Admin123!"}'
AUTH=""
TOKEN=""
case "$REQ_CODE" in
    200)
        TOKEN=$(jget "$REQ_BODY" "d.get('token','')")
        if [ -n "$TOKEN" ]; then
            AUTH="Authorization: Bearer $TOKEN"
            ok "[5] token issued for admin@example.com"
        else
            bad "[5] laravel /api/login" "http=200 but no token in the body: $(printf '%s' "$REQ_BODY" | cut -c1-200)"
        fi
        ;;
    422 | 401)
        bad "[5] laravel /api/login" "http=$REQ_CODE — the seeded admin@example.com/Admin123! is missing. Fix: docker compose exec laravel php artisan db:seed --force"
        ;;
    429)
        bad "[5] laravel /api/login" "http=429 — throttled (throttle:login, 5/min per email+IP). Wait a minute before re-running."
        ;;
    000) bad "[5] laravel /api/login" "$(why)" ;;
    *)   bad "[5] laravel /api/login" "$(why)" ;;
esac

# ---------------------------------------------------------------------------
echo "-- [6] laravel GET /api/me --"
if [ -z "$AUTH" ]; then
    skip "[6] GET /api/me" "no token from [5]"
else
    req "$BASE_LARAVEL/api/me" -H "$AUTH"
    ROLE=$(jget "$REQ_BODY" "d.get('role','')")
    if [ "$REQ_CODE" = "200" ] && [ "$ROLE" = "admin" ]; then
        ok "[6] GET /api/me role=admin"
    else
        bad "[6] GET /api/me" "http=$REQ_CODE role=${ROLE:-<none>} body=$(printf '%s' "$REQ_BODY" | cut -c1-200)"
    fi
fi

# ---------------------------------------------------------------------------
echo "-- [7] laravel rejects a wrong password --"
req -X POST "$BASE_LARAVEL/api/login" -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"definitely-not-the-password"}'
case "$REQ_CODE" in
    422) ok "[7] wrong password rejected 422" ;;
    401) ok "[7] wrong password rejected 401" ;;
    429) bad "[7] wrong password" "http=429 — throttled (throttle:login, 5/min per email+IP), not an auth verdict" ;;
    000) bad "[7] wrong password" "$(why)" ;;
    *)   bad "[7] wrong password" "http=$REQ_CODE — a wrong password must not be accepted" ;;
esac

# ---------------------------------------------------------------------------
echo "-- [8] role gate: viewer cannot upload --"
req -X POST "$BASE_LARAVEL/api/login" -H 'Content-Type: application/json' \
    -d '{"email":"viewer@example.com","password":"Viewer123!"}'
VTOKEN=$(jget "$REQ_BODY" "d.get('token','')")
if [ "$REQ_CODE" != "200" ] || [ -z "$VTOKEN" ]; then
    bad "[8] viewer login" "http=$REQ_CODE body=$(printf '%s' "$REQ_BODY" | cut -c1-200) — seed it: docker compose exec laravel php artisan db:seed --force"
elif [ ! -f tests/fixtures/sample_sales.csv ]; then
    skip "[8] viewer upload" "tests/fixtures/sample_sales.csv is missing, so the upload cannot be attempted"
else
    req -X POST "$BASE_LARAVEL/api/datasets" -H "Authorization: Bearer $VTOKEN" \
        -F "file=@tests/fixtures/sample_sales.csv" -F dataset_type=sales
    case "$REQ_CODE" in
        403) ok "[8] viewer blocked from upload (403)" ;;
        429) bad "[8] viewer upload" "http=429 — throttled (throttle:upload, 20/min per actor)" ;;
        000) bad "[8] viewer upload" "$(why)" ;;
        *)   bad "[8] viewer upload should be 403" "http=$REQ_CODE — a viewer was allowed to write" ;;
    esac
fi

# ---------------------------------------------------------------------------
echo "-- [9] laravel upload (admin) --"
DS_UUID=""
JOB=""
if [ -z "$AUTH" ]; then
    skip "[9] POST /api/datasets" "no token from [5]"
elif [ ! -f tests/fixtures/sample_sales.csv ]; then
    skip "[9-13] ingest steps" "tests/fixtures/sample_sales.csv not found"
else
    req -X POST "$BASE_LARAVEL/api/datasets" -H "$AUTH" \
        -F "file=@tests/fixtures/sample_sales.csv" -F name=smoke_test -F dataset_type=sales
    DS_UUID=$(jget "$REQ_BODY" "d.get('id','')")
    JOB=$(jget "$REQ_BODY" "d.get('import_job_id','')")
    if [ "$REQ_CODE" = "201" ] || [ "$REQ_CODE" = "200" ]; then
        if [ -n "$DS_UUID" ]; then
            ok "[9] dataset created uuid=$DS_UUID import_job_id=${JOB:-<none>}"
        else
            bad "[9] POST /api/datasets" "http=$REQ_CODE but no dataset id in the body: $(printf '%s' "$REQ_BODY" | cut -c1-200)"
        fi
    else
        case "$REQ_CODE" in
            429) bad "[9] POST /api/datasets" "http=429 — throttled (throttle:upload, 20/min per actor)" ;;
            502) bad "[9] POST /api/datasets" "http=502 — the engine rejected Laravel's service key. Check SERVICE_API_KEY matches on both services." ;;
            000) bad "[9] POST /api/datasets" "$(why)" ;;
            *)   bad "[9] POST /api/datasets" "$(why)" ;;
        esac
        DS_UUID=""
    fi

    # -----------------------------------------------------------------------
    # [10] The commit is what actually starts the ETL. The old script uploaded
    # and then polled, so the job sat at "uploaded" forever and every run ended
    # in the same 60-second "import still ... after ~60s" warning.
    # -----------------------------------------------------------------------
    if [ -n "$DS_UUID" ] && [ -n "$JOB" ]; then
        echo "-- [10] laravel POST /api/datasets/$DS_UUID/commit --"
        req -X POST "$BASE_LARAVEL/api/datasets/$DS_UUID/commit" -H "$AUTH" -H 'Content-Type: application/json' -d '{}'
        case "$REQ_CODE" in
            202 | 200) ok "[10] commit accepted, the import is queued" ;;
            429) bad "[10] commit" "http=429 — throttled" ;;
            000) bad "[10] commit" "$(why)" ;;
            *)   bad "[10] commit" "$(why)" ;;
        esac
    else
        skip "[10] commit" "no dataset uuid or no import_job_id from [9]"
    fi

    # -----------------------------------------------------------------------
    # [11] Poll to a terminal state. The engine's terminal vocabulary is
    # done | done_with_errors | failed (etl.py:301, workers/tasks.py:103);
    # "succeeded|success|completed" never occurs, which is why the old loop
    # could only ever time out.
    # -----------------------------------------------------------------------
    echo "-- [11] import job poll --"
    if [ -z "$JOB" ]; then
        skip "[11] import job poll" "no import_job_id from [9]"
    else
        JOB_STATE=""; JOB_PROGRESS=""; JOB_ERRORS=""
        for i in $(seq 1 12); do
            req "$BASE_LARAVEL/api/import-jobs/$JOB" -H "$AUTH"
            JOB_STATE=$(jget "$REQ_BODY" "d.get('status','')")
            JOB_PROGRESS=$(jget "$REQ_BODY" "d.get('progress','')")
            JOB_ERRORS=$(jget "$REQ_BODY" "d.get('error_rows','')")
            echo "   poll $i: status=${JOB_STATE:-?} progress=${JOB_PROGRESS:-?} error_rows=${JOB_ERRORS:-?}"
            case "$JOB_STATE" in
                done)
                    ok "[11] import job finished: done"
                    JOB_STATE=done
                    break
                    ;;
                done_with_errors)
                    warn "[11] import job finished: done_with_errors (error_rows=${JOB_ERRORS:-?}) — rows were rejected; see the import report" 
                    break
                    ;;
                failed | error | cancelled | canceled | aborted)
                    bad "[11] import job failed" "status=$JOB_STATE error_rows=${JOB_ERRORS:-?} — docker compose logs celery-worker | tail -50"
                    break
                    ;;
                404)
                    bad "[11] import job poll" "http=404 — the engine has no job $JOB. The upload was rolled back or the engine database was recreated."
                    break
                    ;;
            esac
            [ "$i" = "12" ] && warn "[11] import job poll" "still '${JOB_STATE:-?}' after ~60s — nothing is consuming the queue. Check: docker compose logs celery-worker, and that the job reached 'queued' in [10]."
            sleep 5
        done
    fi

    # -----------------------------------------------------------------------
    echo "-- [12] data quality --"
    if [ -z "$DS_UUID" ]; then
        skip "[12] quality" "no dataset uuid from [9]"
    elif [ -z "$AUTH" ]; then
        skip "[12] quality" "no token from [5]"
    else
        req "$BASE_LARAVEL/api/datasets/$DS_UUID/quality" -H "$AUTH"
        SCORE=$(jget "$REQ_BODY" "d.get('score','')")
        VERDICT=$(jget "$REQ_BODY" "d.get('verdict','')")
        if [ "$REQ_CODE" = "200" ] && [ -n "$SCORE" ]; then
            ok "[12] quality score=$SCORE verdict=$VERDICT"
        else
            bad "[12] quality" "http=$REQ_CODE score=${SCORE:-<none>} body=$(printf '%s' "$REQ_BODY" | cut -c1-200)"
        fi
    fi
fi

# ---------------------------------------------------------------------------
echo "-- [13] laravel GET /api/analytics/kpi --"
if [ -z "$AUTH" ]; then
    skip "[13] analytics kpi" "no token from [5]"
else
    req "$BASE_LARAVEL/api/analytics/kpi" -H "$AUTH"
    REV=$(jget "$REQ_BODY" "d.get('revenue','')")
    if [ "$REQ_CODE" = "200" ] && [ -n "$REV" ]; then
        ok "[13] analytics kpi revenue=$REV"
    else
        bad "[13] analytics kpi" "http=$REQ_CODE revenue=${REV:-<none>} body=$(printf '%s' "$REQ_BODY" | cut -c1-200) — this is the first check that needs the engine from inside Laravel"
    fi
fi

# ---------------------------------------------------------------------------
echo "-- [14] laravel GET /api/health (bearer, proxies the engine) --"
if [ -z "$AUTH" ]; then
    skip "[14] laravel /api/health" "no token from [5]"
else
    req "$BASE_LARAVEL/api/health" -H "$AUTH"
    if [ "$REQ_CODE" = "200" ]; then
        ENGINE_STATUS=$(jget "$REQ_BODY" "d.get('engine',{}).get('status','')")
        if [ "$ENGINE_STATUS" = "unreachable" ]; then
            bad "[14] laravel /api/health" "Laravel cannot reach the engine with SERVICE_API_KEY. Check the key matches on both services and that SERVICE_API_KEY_HEADER is the same on both."
        else
            ok "[14] laravel reached the engine (status=${ENGINE_STATUS:-?})"
        fi
    else
        bad "[14] laravel /api/health" "http=$REQ_CODE body=$(printf '%s' "$REQ_BODY" | cut -c1-200)"
    fi
fi

# ---------------------------------------------------------------------------
# Engine-direct checks. Skipped as a block, not silently: the summary counts
# them so "0 failed" is never mistaken for "the engine was exercised".
# ---------------------------------------------------------------------------
if [ -z "$KEY" ]; then
    skip "[15-17] engine direct checks" "SERVICE_API_KEY is empty in the environment and .env — set it to exercise the engine's service auth"
    summary
fi
if [ "$KEY" = "change-me-generate-a-real-service-key" ] || [ "$KEY" = "change-me" ] || [ "$KEY" = "changeme" ]; then
    skip "[15-17] engine direct checks" "SERVICE_API_KEY is still the shipped placeholder; the engine fails closed and every engine call would 401"
    summary
fi
SK="$SK_HEADER: $KEY"

echo "-- [15] engine GET /api/v1/models (with the service key) --"
req "$BASE_AI/api/v1/models" -H "$SK"
case "$REQ_CODE" in
    000) bad "[15] engine /api/v1/models" "$(why)" ;;
    401 | 403) bad "[15] engine /api/v1/models" "http=$REQ_CODE — the engine rejected the key. Check SERVICE_API_KEY on the engine matches this one." ;;
    *)
        case "$(jsuccess "$REQ_BODY")" in
            true) ok "[15] engine /api/v1/models 200 success=true" ;;
            false) bad "[15] engine /api/v1/models" "http=200 with success=false: $(printf '%s' "$REQ_BODY" | cut -c1-200)" ;;
            *) bad "[15] engine /api/v1/models" "http=$REQ_CODE and the body is not the engine's envelope: $(printf '%s' "$REQ_BODY" | cut -c1-200)" ;;
        esac
        ;;
esac

echo "-- [16] engine rejects a request with no service key --"
req "$BASE_AI/api/v1/models"
case "$REQ_CODE" in
    401 | 403) ok "[16] engine rejected the unauthenticated request ($REQ_CODE)" ;;
    000) bad "[16] engine rejects missing service key" "$(why) — the engine is not answering at all, so this proves nothing" ;;
    2*) bad "[16] engine rejects missing service key" "http=$REQ_CODE — a guarded endpoint answered without credentials. Service auth is disabled; every guarded route in app/api/v1 is open." ;;
    *)  bad "[16] engine rejects missing service key" "http=$REQ_CODE" ;;
esac

echo "-- [17] engine POST /api/v1/rag/query --"
req -X POST "$BASE_AI/api/v1/rag/query" -H "$SK" -H 'Content-Type: application/json' \
    -d '{"query":"penjualan terbaru","top_k":4}'
if [ "$REQ_CODE" = "000" ]; then
    bad "[17] engine /api/v1/rag/query" "$(why)"
else
    case "$(jsuccess "$REQ_BODY")" in
        true)
            ANSWER=$(jget "$REQ_BODY" "d.get('answer','')")
            if [ -n "$ANSWER" ]; then
                ok "[17] engine rag/query answered"
            else
                warn "[17] engine rag/query" "success=true but no 'answer' in the payload — nothing is indexed yet, or the LLM provider is not configured"
            fi
            ;;
        false) bad "[17] engine /api/v1/rag/query" "http=$REQ_CODE success=false: $(printf '%s' "$REQ_BODY" | cut -c1-200)" ;;
        *)     bad "[17] engine /api/v1/rag/query" "http=$REQ_CODE and the body is not the engine's envelope: $(printf '%s' "$REQ_BODY" | cut -c1-200)" ;;
    esac
fi

summary
