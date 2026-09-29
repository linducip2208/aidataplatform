#!/bin/sh
# AIDataPlatform healthcheck (POSIX sh; runs under dash and bash alike).
#
# Probes only endpoints and services that exist in this tree. Every external
# thing this script touches, with the code that has to agree with it:
#
#   compose services  mysql redis laravel laravel-queue laravel-schedule
#                     fastapi celery-worker celery-beat nginx prometheus grafana
#                     (the eleven `services:` blocks in docker-compose.yml)
#   laravel  GET  /up                    bootstrap/app.php -> health: '/up'
#   laravel  POST /api/login             application/routes/api.php:22
#   laravel  GET  /api/me                application/routes/api.php:26
#   engine   GET  /api/v1/health         ai-engine/app/api/v1/health.py:12
#   engine   GET  /api/v1/liveness       ai-engine/app/api/v1/health.py:41
#                                        (trivial {"alive": true}, no DB/Redis;
#                                        the fast control for the readiness probe)
#   engine   GET  /api/v1/readiness      ai-engine/app/api/v1/health.py:17
#                                        -> 200 even when NOT ready, so the
#                                           body has to be read, not the code
#   nginx    GET  /health                infrastructure/nginx/default.conf:62
#   nginx    GET  /ai-api/api/v1/health  default.conf:74 (the /ai-api prefix strip)
#   mysql         mysqladmin ping -h 127.0.0.1 -u $MYSQL_USER (MYSQL_PWD from env)
#   redis         redis-cli ping, with -a when REDIS_PASSWORD is set (compose
#                 requires the password the moment it is non-empty)
#   in-container CLIs: curl in `laravel` (laravel.Dockerfile:75), python in
#                 `fastapi` (python:3.13-slim), wget in `nginx` (busybox),
#                 mysqladmin in `mysql` and redis-cli in `redis`
#
#   NOT probed, on purpose: the engine's /metrics (nginx 404s it) and any
#   host-published port - every probe here goes through `docker compose exec`,
#   so it works the same whether or not MYSQL_PORT/LARAVEL_PORT are published.
#
# EXIT CODES - the three outcomes that have to be tellable apart at 03:00:
#   0  every probe passed (warnings may still have been printed)
#   1  the stack answered but at least one probe FAILED - something is broken
#   2  the check could not be performed at all: no docker, no Compose v2, no
#      project here, or no scratch directory. 2 means "no answer", never
#      "all good", and it is deliberately different from 1.
#
# Usage: bash infrastructure/scripts/healthcheck.sh
set -u

EXIT_OK=0
EXIT_FAIL=1
EXIT_ERROR=2

PROBE_TIMEOUT="${PROBE_TIMEOUT:-15}"

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd) || {
    echo "[healthcheck] FATAL: cannot resolve the repository root from '$0'" >&2
    exit "$EXIT_ERROR"
}
cd "$ROOT_DIR" || {
    echo "[healthcheck] FATAL: cannot cd to '$ROOT_DIR'" >&2
    exit "$EXIT_ERROR"
}

TMP_ROOT=${TMPDIR:-/tmp}
WORK_DIR=$(mktemp -d "$TMP_ROOT/aidata-healthcheck.XXXXXX") || {
    echo "[healthcheck] FATAL: cannot create a scratch directory under '$TMP_ROOT'" >&2
    exit "$EXIT_ERROR"
}
ERR_FILE="$WORK_DIR/stderr"
cleanup() {
    case "$WORK_DIR" in
        "$TMP_ROOT"/aidata-healthcheck.*) rm -rf "$WORK_DIR" ;;
        *) echo "[healthcheck] WARN: refusing to remove '$WORK_DIR'" >&2 ;;
    esac
}
trap cleanup EXIT INT TERM

# Credentials come from the root .env, sourced BEFORE the defaults are applied,
# so a renamed role or database is the one that gets probed.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi
DBUSER="${MYSQL_USER:-aidata}"
DBNAME="${MYSQL_DATABASE:-aidata}"
DBPASS="${MYSQL_PASSWORD:-changeme}"

PASS=0
FAIL=0
WARN=0
ok()   { echo "  [OK]   $1"; PASS=$((PASS + 1)); }
bad()  { echo "  [FAIL] $1${2:+ - $2}"; FAIL=$((FAIL + 1)); }
warn() { echo "  [WARN] $1${2:+ - $2}"; WARN=$((WARN + 1)); }

# One line, so a multi-line HTML error page from a proxy cannot bury the
# summary in the middle of the output.
shorten() { printf '%s' "$1" | tr '\n\r' '  ' | cut -c1-220; }

# probe <service> <curl args...>
#   Runs curl inside a container and classifies the result into three states an
#   operator has to be able to tell apart:
#     PC_STATUS=ok           HTTP 200
#     PC_STATUS=unreachable  curl itself failed - the container is stopped, the
#                           port is not listening, or curl is not installed
#     PC_STATUS=bad-http     the server answered with a non-200 status
#   PC_ERR carries curl's own message for the unreachable case, which is the
#   only thing that separates "connection refused" from "curl: not found".
probe() {
    _p_svc=$1
    shift
    PC_BODY=$(docker compose exec -T "$_p_svc" curl -sS --max-time "$PROBE_TIMEOUT" \
        -w '\n%{http_code}' "$@" 2>"$ERR_FILE")
    _p_rc=$?
    PC_ERR=$(cat "$ERR_FILE" 2>/dev/null)
    PC_CODE=$(printf '%s\n' "$PC_BODY" | tail -n 1)
    PC_BODY=$(printf '%s\n' "$PC_BODY" | sed '$d')

    case "$PC_CODE" in
        [0-9][0-9][0-9]) ;;
        *)
            # A status line that is not three digits means the -w sentinel never
            # arrived: the compose CLI wrote to stdout, or curl was never run.
            PC_STATUS=unreachable
            PC_ERR="$PC_ERR (no HTTP status line in the response)"
            return 0
            ;;
    esac

    if [ "$_p_rc" -ne 0 ]; then
        PC_STATUS=unreachable
    elif [ "$PC_CODE" = "200" ]; then
        PC_STATUS=ok
    else
        PC_STATUS=bad-http
    fi
}

echo "== AIDataPlatform healthcheck =="
echo "   repo: $ROOT_DIR"

# ---------------------------------------------------------------------------
# 0) Preflight. If this fails nothing below can produce a meaningful answer,
#    and reporting it as nine FAILs would send the operator hunting for a
#    broken service instead of a missing CLI.
# ---------------------------------------------------------------------------
echo "-- [0] preflight --"
_preflight_ok=1

if ! command -v docker >/dev/null 2>&1; then
    echo "  [FAIL] the 'docker' CLI is not on PATH - no probe below can run" >&2
    _preflight_ok=0
elif ! docker compose version >/dev/null 2>&1; then
    echo "  [FAIL] 'docker compose' is unavailable (Compose v2 plugin missing or daemon down)" >&2
    _preflight_ok=0
elif [ ! -f docker-compose.yml ] && [ ! -f compose.yml ]; then
    echo "  [FAIL] no docker-compose.yml in $ROOT_DIR" >&2
    _preflight_ok=0
elif ! docker compose ps -a --format '{{.Service}}' >"$WORK_DIR/services" 2>"$ERR_FILE"; then
    echo "  [FAIL] 'docker compose ps' failed: $(shorten "$(cat "$ERR_FILE")")" >&2
    _preflight_ok=0
elif [ ! -s "$WORK_DIR/services" ]; then
    echo "  [FAIL] no compose services found for $ROOT_DIR - is the stack created here?" >&2
    echo "         run: cd $ROOT_DIR && docker compose ps" >&2
    _preflight_ok=0
fi

if [ "$_preflight_ok" -ne 1 ]; then
    echo "== result: preflight failed, 0 probes run (this is NOT an all-clear) ==" >&2
    exit "$EXIT_ERROR"
fi
ok "docker + compose reachable, $(wc -l < "$WORK_DIR/services" | tr -d ' ') services known"

# ---------------------------------------------------------------------------
# 1) Container roll-call. Reported before the HTTP probes so a container that
#    is simply not running is named as such, instead of showing up as six
#    identical "unreachable" lines further down.
# ---------------------------------------------------------------------------
echo "-- [1] container states --"
for svc in mysql redis laravel laravel-queue laravel-schedule \
            fastapi celery-worker celery-beat nginx prometheus grafana
do
    status=$(docker compose ps -a --format '{{.Service}} {{.Status}}' 2>/dev/null \
        | awk -v s="$svc" '$1 == s { sub(/^[^ ]+ /, ""); print; exit }')
    case "$status" in
        *"(unhealthy)"*)
            bad "$svc" "unhealthy: $status - docker compose logs $svc | tail -50"
            ;;
        Up* | running*)
            ok "$svc ($status)"
            ;;
        "")
            bad "$svc" "absent from 'docker compose ps -a' - either the service is not in this compose project, or compose printed no status for it"
            ;;
        *)
            bad "$svc" "status='$status' - docker compose logs $svc | tail -50"
            ;;
    esac
done

# ---------------------------------------------------------------------------
# 2) Laravel framework probe
# ---------------------------------------------------------------------------
echo "-- [2] laravel (GET /up) --"
probe laravel -o /dev/null http://localhost:8000/up
case "$PC_STATUS" in
    ok)          ok "laravel /up" ;;
    unreachable) bad "laravel /up" "probe could not run: $(shorten "$PC_ERR")" ;;
    *)           bad "laravel /up" "http=$PC_CODE body=$(shorten "$PC_BODY")" ;;
esac

# ---------------------------------------------------------------------------
# 3) Laravel token API. The body is read from the CONTAINER's stdout: the
#    earlier version wrote it to /tmp inside the container and then re-read
#    /tmp from the HOST, so the token was never found and /api/me was silently
#    skipped as a warning on a perfectly healthy stack.
# ---------------------------------------------------------------------------
echo "-- [3] laravel token API (POST /api/login, GET /api/me) --"
AUTH=""
probe laravel -X POST http://localhost:8000/api/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"Admin123!"}'
case "$PC_STATUS" in
    unreachable)
        bad "POST /api/login" "probe could not run: $(shorten "$PC_ERR")"
        ;;
    bad-http)
        case "$PC_CODE" in
            401 | 422)
                # Auth is answering; the seeded demo user is missing or its
                # password changed. A data problem, not an outage.
                warn "POST /api/login" "http=$PC_CODE body=$(shorten "$PC_BODY") | seed it: docker compose exec laravel php artisan db:seed --force"
                ;;
            429)
                bad "POST /api/login" "http=429 - throttled (throttle:login, 5/min per email+IP). Wait a minute; the seeded-account warning above may be a throttle artefact."
                ;;
            *)
                bad "POST /api/login" "http=$PC_CODE body=$(shorten "$PC_BODY")"
                ;;
        esac
        ;;
    ok)
        token=$(printf '%s' "$PC_BODY" | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)
        if [ -z "$token" ]; then
            # 200 without a token: either the envelope moved or a proxy answered
            # something that is not the documented {"data":{"token":...}}.
            warn "POST /api/login" "http=200 but no \"token\" in the body: $(shorten "$PC_BODY")"
        else
            AUTH="Authorization: Bearer $token"
            probe laravel -o /dev/null -H "$AUTH" http://localhost:8000/api/me
            case "$PC_STATUS" in
                ok)          ok "laravel token API (login + /api/me)" ;;
                unreachable) bad "GET /api/me" "probe could not run: $(shorten "$PC_ERR")" ;;
                *)           bad "GET /api/me" "http=$PC_CODE - the token was issued but rejected" ;;
            esac
        fi
        ;;
esac

# ---------------------------------------------------------------------------
# 4) Engine liveness. Status AND body: the engine's HealthResponse always
#    carries "status":"ok", so a 200 from a proxy that is not the engine is
#    caught here rather than passing silently.
# ---------------------------------------------------------------------------
echo "-- [4] engine (GET /api/v1/health) --"
probe fastapi http://localhost:8000/api/v1/health
case "$PC_STATUS" in
    ok)
        case "$PC_BODY" in
            *'"status"'*) ok "engine /api/v1/health 200, body carries \"status\"" ;;
            *) warn "engine /api/v1/health" "http=200 but the body is not the engine's HealthResponse: $(shorten "$PC_BODY")" ;;
        esac
        ;;
    unreachable) bad "engine /api/v1/health" "probe could not run: $(shorten "$PC_ERR")" ;;
    *)           bad "engine /api/v1/health" "http=$PC_CODE body=$(shorten "$PC_BODY")" ;;
esac

# ---------------------------------------------------------------------------
# 4b) Engine liveness. Trivial by design (no DB, no Redis): if health fails but
#     liveness passes, the process is up and a dependency is down -- read the
#     readiness section, not the container log. Kept to one tiny python probe so
#     the whole script stays fast.
# ---------------------------------------------------------------------------
echo "-- [4b] engine (GET /api/v1/liveness) --"
_live=$(docker compose exec -T fastapi python -c '
import sys, urllib.request
try:
    raw = urllib.request.urlopen("http://localhost:8000/api/v1/liveness", timeout=10).read().decode("utf-8", "replace")
except Exception as exc:
    print("UNREACHABLE:" + type(exc).__name__)
    sys.exit(0)
print("BODY:" + raw[:200])
' 2>"$ERR_FILE")
if [ "$?" -ne 0 ]; then
    bad "engine /api/v1/liveness" "probe could not run: $(shorten "$(cat "$ERR_FILE" 2>/dev/null)")"
else
    case "$_live" in
        *UNREACHABLE:*)
            bad "engine /api/v1/liveness" "the engine did not answer (${_live#UNREACHABLE:})"
            ;;
        *'"alive"'*)
            ok "engine /api/v1/liveness alive"
            ;;
        *)
            bad "engine /api/v1/liveness" "unexpected body: $(shorten "$_live")"
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# 5) Engine dependencies. /api/v1/readiness answers 200 even when the database
#    is unreachable (health.py returns a plain dict), so the code proves
#    nothing and the body is the only signal. "not JSON" is its own outcome:
#    it means something between here and the app answered instead of the app.
# ---------------------------------------------------------------------------
echo "-- [5] engine (GET /api/v1/readiness) --"
readiness=$(docker compose exec -T fastapi python -c '
import json, sys, urllib.request
try:
    raw = urllib.request.urlopen("http://localhost:8000/api/v1/readiness", timeout=15).read().decode("utf-8", "replace")
except Exception as exc:
    print("UNREACHABLE:" + type(exc).__name__)
    sys.exit(0)
try:
    d = json.loads(raw)
except Exception:
    print("NOTJSON")
    sys.exit(0)
if not isinstance(d, dict) or "ready" not in d:
    print("NOTJSON")
    sys.exit(0)
checks = d.get("checks") if isinstance(d.get("checks"), dict) else {}
print("READY:" + str(d.get("ready")))
print("DB:" + str(checks.get("db")))
print("REDIS:" + str(checks.get("redis")))
' 2>"$ERR_FILE")
_readiness_rc=$?
if [ "$_readiness_rc" -ne 0 ]; then
    bad "engine /api/v1/readiness" "probe could not run: $(shorten "$(cat "$ERR_FILE" 2>/dev/null)")"
else
    _r_state=$(printf '%s\n' "$readiness" | sed -n 's/^READY://p' | head -n 1)
    _r_db=$(printf '%s\n' "$readiness" | sed -n 's/^DB://p' | head -n 1)
    _r_redis=$(printf '%s\n' "$readiness" | sed -n 's/^REDIS://p' | head -n 1)
    case "$readiness" in
        UNREACHABLE:*)
            # Checked first: the probe printed no 'ready' field at all, so it
            # must not also be reported as "unparsable" - one probe, one verdict.
            bad "engine /api/v1/readiness" "the engine did not answer (${readiness#UNREACHABLE:}): docker compose logs fastapi | tail -50"
            ;;
        NOTJSON)
            bad "engine /api/v1/readiness" "the response was not JSON - something other than the engine answered"
            ;;
        *)
            case "$_r_state" in
                True | true)
                    ok "engine /api/v1/readiness ready=true (db=${_r_db:-?} redis=${_r_redis:-?})"
                    case "$_r_redis" in
                        up) ;;
                        "") warn "engine /api/v1/readiness" "ready=true but the body reported no redis check" ;;
                        *)  warn "engine /api/v1/readiness" "ready=true but redis is ${_r_redis} - cache/session/queue calls will fail" ;;
                    esac
                    ;;
                False | false)
                    bad "engine /api/v1/readiness" "ready=false (db=${_r_db:-?} redis=${_r_redis:-?}) - docker compose logs fastapi | tail -50"
                    ;;
                "")
                    bad "engine /api/v1/readiness" "no 'ready' field in the body: $(shorten "$readiness")"
                    ;;
                *)
                    bad "engine /api/v1/readiness" "unexpected ready value '$_r_state': $(shorten "$readiness")"
                    ;;
            esac
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# 6) Nginx liveness. /health is answered locally and never touches an upstream,
#    so it is the control for the next probe: if it fails, nginx itself is not
#    answering; if it works, a failing /ai-api/ means the rewrite is broken.
# ---------------------------------------------------------------------------
echo "-- [6] nginx (GET /health) --"
NGINX_UP=0
docker compose exec -T nginx wget -qO- --timeout="$PROBE_TIMEOUT" http://localhost/health >"$WORK_DIR/nginx_health" 2>"$ERR_FILE"
_nginx_rc=$?
if [ "$_nginx_rc" -eq 0 ]; then
    NGINX_UP=1
    ok "nginx /health"
else
    bad "nginx /health" "wget rc=$_nginx_rc $(shorten "$(cat "$ERR_FILE" 2>/dev/null)") - docker compose logs nginx | tail -20"
fi

# ---------------------------------------------------------------------------
# 7) The /ai-api prefix strip. The engine is not published on a host port in
#    production, so this is the only end-to-end proof that /ai-api/... is
#    rewritten to /api/v1/... and reaches the engine.
# ---------------------------------------------------------------------------
echo "-- [7] nginx (GET /ai-api/api/v1/health, proves the prefix strip) --"
if [ "$NGINX_UP" -eq 0 ]; then
    warn "nginx /ai-api/api/v1/health" "skipped - nginx did not answer /health, so this probe cannot say anything"
else
    docker compose exec -T nginx wget -qO- --timeout="$PROBE_TIMEOUT" \
        http://localhost/ai-api/api/v1/health >"$WORK_DIR/nginx_aiapi" 2>"$ERR_FILE"
    _aiapi_rc=$?
    _aiapi_body=$(cat "$WORK_DIR/nginx_aiapi" 2>/dev/null)
    if [ "$_aiapi_rc" -ne 0 ] && [ -z "$_aiapi_body" ]; then
        bad "nginx /ai-api/api/v1/health" "wget rc=$_aiapi_rc and no body - nginx answered /health, so the rewrite or the fastapi upstream is broken: docker compose logs nginx | tail -20"
    elif [ "$_aiapi_body" = "ok" ]; then
        bad "nginx /ai-api/api/v1/health" "got nginx's own /health body ('ok') - the /ai-api/ location is not rewriting, the request fell through to laravel: docker compose logs nginx | tail -20"
    else
        case "$_aiapi_body" in
            *'"status"'*) ok "nginx /ai-api/ prefix is stripped to /api/v1/" ;;
            *)            bad "nginx /ai-api/api/v1/health" "rc=$_aiapi_rc body=$(shorten "$_aiapi_body") - docker compose logs nginx | tail -20" ;;
        esac
    fi
fi

# ---------------------------------------------------------------------------
# 8) MySQL
# ---------------------------------------------------------------------------
echo "-- [8] mysql (mysqladmin ping) --"
if docker compose exec -T -e MYSQL_PWD="$DBPASS" mysql mysqladmin -h 127.0.0.1 -u "$DBUSER" ping >/dev/null 2>"$ERR_FILE"; then
    ok "mysql ping ($DBUSER@$DBNAME)"
else
    bad "mysql ping ($DBUSER@$DBNAME)" "$(shorten "$(cat "$ERR_FILE" 2>/dev/null)") - docker compose logs mysql | tail -30"
fi

# ---------------------------------------------------------------------------
# 9) Redis. redis-cli needs the password whenever REDIS_PASSWORD is set, and
#    an empty REDIS_PASSWORD in compose means "no password at all", so the two
#    cases have to be probed differently.
# ---------------------------------------------------------------------------
echo "-- [9] redis (PING) --"
if [ -n "${REDIS_PASSWORD:-}" ]; then
    _redis_ping=$(docker compose exec -T redis redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping 2>"$ERR_FILE")
    _redis_mode="with REDIS_PASSWORD"
else
    _redis_ping=$(docker compose exec -T redis redis-cli ping 2>"$ERR_FILE")
    _redis_mode="without password"
fi
case "$_redis_ping" in
    *PONG*) ok "redis PING ($_redis_mode)" ;;
    *NOAUTH* | *WRONGPASS* | *ERR*)
        bad "redis PING" "$_redis_mode: $(shorten "$_redis_ping") - REDIS_PASSWORD does not match what the server was started with"
        ;;
    *)
        bad "redis PING" "$_redis_mode: reply='$(shorten "$_redis_ping")' - docker compose logs redis | tail -20"
        ;;
esac

echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
[ "$FAIL" -eq 0 ] && exit "$EXIT_OK"
exit "$EXIT_FAIL"
