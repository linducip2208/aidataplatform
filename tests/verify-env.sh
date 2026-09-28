#!/usr/bin/env sh
# AIDataPlatform environment-variable traceability check.
#
# Re-derives the declare -> inject -> read matrix by PARSING the tree, the way
# tests/verify-routing.sh parses nginx and compose: nothing is started, no
# network, no database, no jq. The only non-shell dependency is php, and only
# for `php -l` on the config files whose env() names are about to be trusted.
#
# Every name in the matrix is one that some file in this repository really
# reads, really injects or really declares; nothing here is invented.
#
# Usage: sh tests/verify-env.sh
# Deps:  sh + grep + sed + awk  (php optional: a syntax error in
#        application/config/*.php would make the env() extraction incomplete)
#
# The three bug classes this exists to stop, and the check that catches each:
#   compose forwards MAX_UPLOAD_MB, the engine reads only UPLOAD_MAX_MB
#       -> [2] "injected but read by nothing"
#   APP_ENV=production is outside the engine's allow-list
#       -> [6] "APP_ENV value outside the engine's allow-list"
#   SERVICE_API_KEY_HEADER is configurable on one side and hardcoded on the
#   other
#       -> [2] (the engine side is never injected) and [7] "the two sides of a
#          shared contract carry different values"
#
# Scope, and why. The engine's read set and the Laravel<->engine contract file
# are the settings that decide whether the platform works, so a name read there
# and declared nowhere is an ERROR. The rest of application/config/*.php is
# stock Laravel (memcached, sqs, ses, papertrail, slack, ...): every name there
# has a framework default and only matters if that driver is enabled, so those
# are reported as WARNINGS. Making them errors would bury the real defects.
#
# Warnings never affect the exit code. An ssh/CI-only variable that is
# legitimately absent from a local checkout is not a defect.
set -u

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT_DIR" || exit 1

ROOT_ENV=".env.example"
APP_ENV_EXAMPLE="application/.env.example"
ENGINE_ENV_EXAMPLE="ai-engine/.env.example"
COMPOSE="docker-compose.yml"
ENGINE_CONFIG="ai-engine/app/core/config.py"
ENGINE_DIR="ai-engine"
LARAVEL_DIR="application"
APP_CONFIG_DIR="application/config"

PASS=0
FAIL=0
WARN=0

ok()    { echo "  [OK]   $1"; PASS=$((PASS + 1)); }
bad()   { echo "  [FAIL] $1${2:+ -- $2}"; FAIL=$((FAIL + 1)); }
warn()  { echo "  [WARN] $1${2:+ -- $2}"; WARN=$((WARN + 1)); }
info()  { echo "  [info] $1"; }
head_() { echo "-- $1 --"; }

TMP_ROOT=${TMPDIR:-/tmp}
TMP_DIR=$(mktemp -d "$TMP_ROOT/verify-env.XXXXXX") || {
    echo "  [FAIL] cannot create a scratch directory under $TMP_ROOT" >&2
    exit 1
}
cleanup() {
    case "$TMP_DIR" in
        "$TMP_ROOT"/verify-env.*) rm -rf "$TMP_DIR" ;;
        *) echo "WARN: refusing to remove '$TMP_DIR'" >&2 ;;
    esac
}
trap cleanup EXIT INT TERM

for f in "$ROOT_ENV" "$APP_ENV_EXAMPLE" "$ENGINE_ENV_EXAMPLE" "$COMPOSE" "$ENGINE_CONFIG"; do
    if [ ! -f "$f" ]; then
        echo "  [FAIL] missing required file: $f"
        echo "== result: 0 passed, 1 failed, 0 warnings =="
        exit 1
    fi
done
if ! ls "$APP_CONFIG_DIR"/*.php >/dev/null 2>&1; then
    echo "  [FAIL] no config files in $APP_CONFIG_DIR"
    exit 1
fi

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

# Active KEY=VALUE assignments in a dotenv file. Comments, blanks, commented-out
# lines and a CRLF carriage return are excluded, which is what makes a
# documented deprecated alias inert for the "is it declared?" question while
# still being visible to a reader.
dotenv_keys() {
    sed -e 's/\r$//' -e 's/^[[:space:]]*export[[:space:]]*//' -e 's/[[:space:]]*$//' "$1" |
        grep -E '^[A-Za-z_][A-Za-z0-9_]*=' |
        cut -d= -f1 |
        sort -u
}

# Line number of a KEY assignment, tolerating a leading `export `.
dotenv_line() {
    grep -nE "^[[:space:]]*(export[[:space:]]+)?$2[[:space:]]*=" "$1" 2>/dev/null |
        head -n 1 |
        cut -d: -f1
}

# One value, quotes and a trailing comment stripped, empty string if absent.
dotenv_value() {
    _line=$(dotenv_line "$1" "$2")
    [ -n "$_line" ] || return 0
    sed -n "${_line}p" "$1" |
        sed -e 's/\r$//' -e 's/^[^=]*=//' -e 's/[[:space:]]*#.*$//' \
            -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/" -e 's/[[:space:]]*$//'
}

# Does the 8 lines above this assignment carry the [REQUIRED] marker?
dotenv_is_required() {
    _file=$1
    _key=$2
    _line=$(dotenv_line "$_file" "$_key")
    [ -n "$_line" ] || return 1
    [ "$_line" -le 8 ] && return 0
    sed -n "$((_line - 8)),$((_line - 1))p" "$_file" | grep -q '\[REQUIRED\]'
}

in_list() {
    # in_list NAME FILE_OF_NEWLINE_SEPARATED_NAMES
    grep -qx -- "$1" "$2"
}

# Names a shell script expands as ${NAME...}. "$${NAME}" is compose's escape
# for a container-side expansion and is normalised first, so it is not counted
# as a host variable.
#
# A name the same file also ASSIGNS on a line that does not read ${NAME} back is
# a script local, not an environment input: ai-engine/docker-entrypoint.sh has
# `PG_HOST="${PGHOST_FOR_MIGRATION:-postgres}"` and then logs ${PG_HOST}, and
# PG_HOST is not something the operator can set. A name assigned from its own
# default, `BACKUP_DIR="${BACKUP_DIR:-./backups}"`, is a real input and is kept.
script_vars() {
    sed 's/\$\${//g' "$@" 2>/dev/null |
        grep -o '\${[A-Za-z_][A-Za-z0-9_]*' |
        sed 's/^\${//' |
        sort -u > "$TMP_DIR/sv_read"
    : > "$TMP_DIR/sv_locals"
    sed -n 's/^[[:space:]]*\([A-Za-z_][A-Za-z0-9_]*\)=\(.*\)/\1|\2/p' "$@" 2>/dev/null > "$TMP_DIR/sv_assign_src"
    while IFS='|' read -r _n _src; do
        [ -n "$_n" ] || continue
        case "$_src" in
            *"\${$_n"* | *"\${$_n:-"* | *"\${$_n:+"*) : ;;   # reads its own default: a real input
            *) printf '%s\n' "$_n" >> "$TMP_DIR/sv_locals" ;;
        esac
    done < "$TMP_DIR/sv_assign_src"
    sort -u "$TMP_DIR/sv_locals" > "$TMP_DIR/sv_locals.u"
    grep -vxF -f "$TMP_DIR/sv_locals.u" "$TMP_DIR/sv_read"
}

# env('NAME' and env('NAME', 'default') in the Laravel config files.
php_env_names() {
    grep -ho "env(['\"][A-Za-z_][A-Za-z0-9_]*['\"]" "$APP_CONFIG_DIR"/*.php |
        sed "s/^env(['\"]//; s/['\"]\$//" |
        sort -u
}

# The cross-service contract only: the variables Laravel and the engine both
# have to agree on. This file is the platform's, not the framework's.
php_contract_names() {
    grep -ho "env(['\"][A-Za-z_][A-Za-z0-9_]*['\"]" "$APP_CONFIG_DIR"/ai_engine.php |
        sed "s/^env(['\"]//; s/['\"]\$//" |
        sort -u
}

php_env_default() {
    sed -n "s/.*env('$1',[[:space:]]*'\([^']*\)').*/\1/p" "$APP_CONFIG_DIR"/ai_engine.php | head -n 1
}

# Settings fields, AliasChoices members, the DEPRECATED_ALIASES table and the
# raw os.getenv / os.environ reads. Together this is every name the engine can
# obtain from the environment.
py_settings_fields() {
    sed -n 's/^    \([a-z_][a-z0-9_]*\):.*Field(.*/\1/p' "$ENGINE_CONFIG" | tr 'a-z' 'A-Z' | sort -u
}
py_alias_names() {
    sed -n 's/.*AliasChoices(\(.*\)).*/\1/p' "$ENGINE_CONFIG" |
        tr -d '"()' | tr ',' '\n' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' |
        grep -E '^[A-Z][A-Z0-9_]*$' | sort -u
}
# "field|CANONICAL,DEPRECATED,..." one line per table row, so the canonical name
# can be told from the fallback.
py_alias_rows() {
    sed -n 's/^    "\([a-z_][a-z0-9_]*\)": (\(.*\)),$/\1|\2/p' "$ENGINE_CONFIG"
}
py_raw_getenv() {
    grep -rhoE 'os\.getenv\("[A-Za-z_][A-Za-z0-9_]*"' \
        "$ENGINE_DIR/app" "$ENGINE_DIR/alembic" 2>/dev/null |
        sed 's/.*("//; s/"$//'
    grep -rhoE 'os\.environ\.get\("[A-Za-z_][A-Za-z0-9_]*"' \
        "$ENGINE_DIR/app" "$ENGINE_DIR/alembic" 2>/dev/null |
        sed 's/.*("//; s/"$//'
    # os.environ["X"] = url is a WRITE (alembic/env.py), not a read.
    grep -rhoE 'os\.environ\["[A-Za-z_][A-Za-z0-9_]*"\]' \
        "$ENGINE_DIR/app" "$ENGINE_DIR/alembic" 2>/dev/null |
        sed 's/.*\["//; s/"\]//'
}
# A module-level constant that NAMES another variable, e.g.
# WEBHOOK_ENV = "ALERT_WEBHOOK_URL" in app/alerts/notifiers.py, which is the
# only way the alerting webhook reaches the environment. The name has to end in
# _ENV or _URL for that to be the intent, otherwise INTERNAL_ERROR_CODE =
# "INTERNAL_ERROR" (an error code, not a variable) is a false positive.
py_env_constants() {
    grep -rhoE '^[A-Z][A-Z0-9_]*_(ENV|URL)[[:space:]]*=[[:space:]]*"[A-Z][A-Z0-9_]*"[[:space:]]*$' \
        "$ENGINE_DIR/app" "$ENGINE_DIR/alembic" 2>/dev/null |
        sed 's/.*"\([A-Z][A-Z0-9_]*\)"[[:space:]]*$/\1/' |
        sort -u
}
# The APP_ENV values the engine accepts, straight out of its own allow-list.
py_allowed_app_envs() {
    sed -n '/^ALLOWED_APP_ENVS/,/^)/p' "$ENGINE_CONFIG" |
        tr ',{}"' '\n' |
        tr -d ' ' |
        grep -E '^[a-z][a-z]*$' |
        sort -u
}

# One awk pass over compose. Anchors are declared before the services that
# merge them (a merge key cannot reference an anchor defined later), so a single
# pass can build the anchor table first and expand <<: *name in the second.
# Prints the environment keys injected into $1.
compose_env_keys() {
    awk -v want="$1" '
    function ind(s,   t) { t = s; sub(/[^ ].*$/, "", t); return length(t) }

    # phase 1: x-<name>: &<anchor> followed by a more deeply indented block
    /^[A-Za-z_][A-Za-z0-9_.-]*:[[:space:]]*&[A-Za-z0-9_-]+[[:space:]]*$/ {
        a = $0
        sub(/^[^:]*:[[:space:]]*&/, "", a)
        sub(/[[:space:]]*$/, "", a)
        cur_anchor = a
        next
    }
    cur_anchor != "" {
        if ($0 ~ /^[[:space:]]*$/ || $0 ~ /^[[:space:]]*#/) next
        if (ind($0) > 0) {
            l = $0
            sub(/^[[:space:]]+/, "", l)
            if (l ~ /^-/) next
            if (l ~ /^[A-Za-z_][A-Za-z0-9_]*:/) {
                k = l
                sub(/:.*/, "", k)
                ANCH[cur_anchor] = ANCH[cur_anchor] " " k
            }
            next
        }
        cur_anchor = ""
    }

    # phase 2: services, and the requested one'"'"'s environment: block
    /^services:[[:space:]]*$/ { inserv = 1; next }
    inserv && /^[^ \t#]/ { inserv = 0 }
    inserv && /^  [A-Za-z0-9_.-]+:[[:space:]]*$/ {
        s = $0
        sub(/^  /, "", s)
        sub(/:.*/, "", s)
        cur_svc = s
        inenv = 0
        next
    }
    inserv && cur_svc == want && /^    environment:[[:space:]]*$/ { inenv = 1; next }
    inenv && cur_svc == want {
        if ($0 ~ /^      <<:[[:space:]]*\*/) {
            a = $0
            sub(/^.*\*/, "", a)
            sub(/[[:space:]]*$/, "", a)
            m = split(ANCH[a], arr, " ")
            for (i = 1; i <= m; i++) if (arr[i] != "") print arr[i]
            print "#merged:" a
            next
        }
        if ($0 ~ /^      [A-Za-z_][A-Za-z0-9_]*:/) {
            l = $0
            sub(/^      /, "", l)
            sub(/:.*/, "", l)
            print l
            next
        }
        if ($0 ~ /^    [A-Za-z_]/) inenv = 0
    }
    ' "$COMPOSE" | sort -u
}

compose_services() {
    awk '
    /^services:[[:space:]]*$/ { inserv = 1; next }
    inserv && /^[^ \t#]/ { inserv = 0 }
    inserv && /^  [A-Za-z0-9_.-]+:[[:space:]]*$/ {
        s = $0
        sub(/^  /, "", s)
        sub(/:.*/, "", s)
        print s
    }
    ' "$COMPOSE"
}

compose_anchors() {
    sed -n 's/^[A-Za-z_][A-Za-z0-9_.-]*:[[:space:]]*&\([A-Za-z0-9_-]*\)[[:space:]]*$/\1/p' "$COMPOSE" | sort -u
}

# Every &anchor and every *anchor in the file, so a definition can be matched
# against its uses.
compose_anchor_defs() {
    sed -n 's/^[A-Za-z_][A-Za-z0-9_.-]*:[[:space:]]*&\([A-Za-z0-9_-]*\)[[:space:]]*$/\1/p' "$COMPOSE" | sort -u
}
compose_anchor_uses() {
    grep -o '[&*][A-Za-z0-9_-][A-Za-z0-9_-]*' "$COMPOSE" | sed 's/^[&*]//' | sort -u
}

# Names compose interpolates from the host .env. "$${NAME}" is the escape for a
# container-side expansion and must not be counted as a host variable.
compose_interp_vars() {
    sed 's/\$\${//g' "$COMPOSE" |
        grep -o '\${[A-Za-z_][A-Za-z0-9_]*' |
        sed 's/^\${//' |
        sort -u
}

# Variables that are read by the container images, by the host OS or by the CI
# runner itself, not by code in this repository. Without this list every one of
# them is reported as an orphan. One name per line: a space-separated line
# would be matched whole by grep -x and silently exempt nothing.
cat > "$TMP_DIR/host" <<'HOST_EOF'
PATH
HOME
USER
USERNAME
LOGNAME
PWD
OLDPWD
IFS
HOSTNAME
HOSTTYPE
LANG
LC_ALL
LC_CTYPE
DEBIAN_FRONTEND
TMPDIR
TMP
TEMP
TMPPREFIX
TMPBASE
RUNDIR
CI
GITHUB_ACTIONS
GITHUB_WORKFLOW
GITHUB_REF
GITHUB_SHA
GITHUB_REPOSITORY
GITHUB_ACTOR
GITHUB_EVENT_NAME
GITHUB_EVENT_PATH
GITHUB_WORKSPACE
GITHUB_RUN_ID
GITHUB_TOKEN
GITHUB_OUTPUT
GITHUB_ENV
GITHUB_STEP_SUMMARY
RUNNER_OS
RUNNER_ARCH
RUNNER_TEMP
RUNNER_TOOL_CACHE
SSH_HOST
SSH_USER
SSH_KEY
SSH_PORT
FILE
REF
DOCKER_HOST
DOCKER_BUILDKIT
COMPOSE_PROJECT_NAME
PGDATA
POSTGRES_PASSWORD
GF_SECURITY_ADMIN_USER
GF_SECURITY_ADMIN_PASSWORD
GF_USERS_ALLOW_SIGN_UP
HOST_EOF
sort -u "$TMP_DIR/host" > "$TMP_DIR/host.sorted"

# The variables the stack cannot run without. Each must be declared in an
# .env.example AND carry a [REQUIRED] marker, so the operator sees the
# checklist instead of discovering an empty APP_KEY from a 500.
cat > "$TMP_DIR/required" <<'REQ_EOF'
APP_KEY|Laravel has no encryption key: every request fails and laravel-entrypoint.sh refuses to start the container
SERVICE_API_KEY|engine configured_service_key() rejects a placeholder, so every engine-backed page returns 502
JWT_SECRET|engine _configured_jwt_secret() rejects a placeholder, so token minting and verification fail
POSTGRES_PASSWORD|baked into the pgdata volume on first boot; a placeholder is a public database
GRAFANA_ADMIN_PASSWORD|grafana is published on GRAFANA_PORT with no other access control
REQ_EOF

echo "== AIDataPlatform env traceability (static parse, nothing executed) =="
echo "root=$ROOT_DIR"

# ---------------------------------------------------------------------------
# build the sets
# ---------------------------------------------------------------------------
dotenv_keys "$ROOT_ENV"         > "$TMP_DIR/decl_root"
dotenv_keys "$APP_ENV_EXAMPLE"  > "$TMP_DIR/decl_app"
dotenv_keys "$ENGINE_ENV_EXAMPLE" > "$TMP_DIR/decl_engine"
cat "$TMP_DIR/decl_root" "$TMP_DIR/decl_app" "$TMP_DIR/decl_engine" |
    grep -v '^$' | sort -u > "$TMP_DIR/decl_all"

php_env_names           > "$TMP_DIR/read_php"
php_contract_names      > "$TMP_DIR/read_php_contract"
py_settings_fields      > "$TMP_DIR/read_py_field"
py_alias_names          > "$TMP_DIR/read_py_alias"
py_raw_getenv           > "$TMP_DIR/read_py_raw"
py_env_constants        > "$TMP_DIR/read_py_const"
py_alias_rows           > "$TMP_DIR/alias_rows"
py_allowed_app_envs     > "$TMP_DIR/app_envs"

cat "$TMP_DIR/read_py_field" "$TMP_DIR/read_py_alias" "$TMP_DIR/read_py_raw" \
    "$TMP_DIR/read_py_const" | sort -u > "$TMP_DIR/read_py"

# The deprecated fallbacks in the alias table are names the engine still accepts
# but nothing is supposed to supply. They are excluded from the "read but
# undeclared" error so that retiring UPLOAD_MAX_MB does not become a new
# failure; [9] reports them instead.
: > "$TMP_DIR/deprecated"
while IFS='|' read -r _field _rhs; do
    [ -n "$_rhs" ] || continue
    printf '%s' "$_rhs" | sed 's/^[^,]*,//' | tr ',' '\n' | tr -d '" ' |
        grep -E '^[A-Z][A-Z0-9_]*$' >> "$TMP_DIR/deprecated"
done < "$TMP_DIR/alias_rows"
sort -u "$TMP_DIR/deprecated" > "$TMP_DIR/deprecated.u"
mv "$TMP_DIR/deprecated.u" "$TMP_DIR/deprecated"

script_vars "$ENGINE_DIR/docker-entrypoint.sh" 2>/dev/null | sort -u > "$TMP_DIR/read_engine_sh"
script_vars "infrastructure/docker/laravel-entrypoint.sh" 2>/dev/null | sort -u > "$TMP_DIR/read_laravel_sh"
script_vars infrastructure/scripts/*.sh 2>/dev/null |
    grep -vxF -f "$TMP_DIR/host.sorted" | sort -u > "$TMP_DIR/read_scripts"
script_vars infrastructure/monitoring/prometheus.yml 2>/dev/null | sort -u > "$TMP_DIR/read_prom"

compose_interp_vars > "$TMP_DIR/compose_interp"
compose_anchor_defs > "$TMP_DIR/anchor_defs"
compose_anchor_uses > "$TMP_DIR/anchor_uses"
compose_services   > "$TMP_DIR/services"

# Every key compose injects anywhere, with anchor expansion applied per service.
: > "$TMP_DIR/compose_injected"
while read -r svc; do
    [ -n "$svc" ] || continue
    compose_env_keys "$svc" > "$TMP_DIR/svc_$svc"
    grep -v '^#merged:' "$TMP_DIR/svc_$svc" >> "$TMP_DIR/compose_injected"
done < "$TMP_DIR/services"
sort -u "$TMP_DIR/compose_injected" > "$TMP_DIR/compose_injected.u"
mv "$TMP_DIR/compose_injected.u" "$TMP_DIR/compose_injected"

cat "$TMP_DIR/read_php" "$TMP_DIR/read_py" "$TMP_DIR/read_engine_sh" \
    "$TMP_DIR/read_laravel_sh" "$TMP_DIR/read_scripts" "$TMP_DIR/read_prom" \
    "$TMP_DIR/read_py_field" "$TMP_DIR/read_py_alias" "$TMP_DIR/read_py_raw" \
    "$TMP_DIR/read_py_const" |
    grep -v '^$' | grep -vxF -f "$TMP_DIR/host.sorted" | sort -u > "$TMP_DIR/read_all"
cat "$TMP_DIR/decl_all" "$TMP_DIR/compose_injected" "$TMP_DIR/compose_interp" |
    grep -v '^$' | sort -u > "$TMP_DIR/platform_all"

# ---------------------------------------------------------------------------
# [1] required-but-unset
# ---------------------------------------------------------------------------
head_ "[1] required variables are declared and marked"

while IFS='|' read -r key why; do
    [ -n "$key" ] || continue
    _marked=no
    for f in "$ROOT_ENV" "$APP_ENV_EXAMPLE" "$ENGINE_ENV_EXAMPLE"; do
        if dotenv_is_required "$f" "$key"; then _marked=yes; fi
    done
    _declared=no
    for f in "$TMP_DIR/decl_root" "$TMP_DIR/decl_app" "$TMP_DIR/decl_engine"; do
        if in_list "$key" "$f"; then _declared=yes; fi
    done
    if [ "$_declared" = "no" ]; then
        bad "$key is declared in an .env.example" "$why"
    elif [ "$_marked" = "no" ]; then
        bad "$key carries a [REQUIRED] marker" \
            "declared but unmarked, so an empty value looks like a valid default"
    else
        _val=$(dotenv_value "$ROOT_ENV" "$key")
        [ -n "$_val" ] || _val=$(dotenv_value "$ENGINE_ENV_EXAMPLE" "$key")
        if [ -z "$_val" ]; then
            ok "$key is declared and marked [REQUIRED] (empty: fill it before booting)"
        else
            ok "$key is declared and marked [REQUIRED] (ships a safe local default)"
        fi
    fi
done < "$TMP_DIR/required"

# ---------------------------------------------------------------------------
# [2] injected but read by nothing
# ---------------------------------------------------------------------------
head_ "[2] every key compose injects is read by that service"

# Which read set applies to which service. The two Laravel services that do not
# run the entrypoint still load the config, so they get the same set.
laravel_like() {
    case "$1" in
        laravel | laravel-queue | laravel-schedule) return 0 ;;
    esac
    return 1
}
engine_like() {
    case "$1" in
        fastapi | celery-worker | celery-beat) return 0 ;;
    esac
    return 1
}
ops_like() {
    case "$1" in
        postgres | redis | nginx | prometheus | grafana) return 0 ;;
    esac
    return 1
}

while read -r svc; do
    [ -n "$svc" ] || continue
    [ -s "$TMP_DIR/svc_$svc" ] || continue
    while read -r key; do
        [ -n "$key" ] || continue
        case "$key" in \#*) continue ;; esac
        if grep -qxF -- "$key" "$TMP_DIR/host.sorted"; then
            ok "$svc: $key is an image/runner contract"
            continue
        fi
        if laravel_like "$svc"; then
            if in_list "$key" "$TMP_DIR/read_php" || in_list "$key" "$TMP_DIR/read_laravel_sh"; then
                ok "$svc: $key is read"
                continue
            fi
        elif engine_like "$svc"; then
            if in_list "$key" "$TMP_DIR/read_py" || in_list "$key" "$TMP_DIR/read_engine_sh"; then
                ok "$svc: $key is read"
                continue
            fi
        elif ops_like "$svc"; then
            if in_list "$key" "$TMP_DIR/read_prom" || in_list "$key" "$TMP_DIR/read_scripts"; then
                ok "$svc: $key is read"
                continue
            fi
        fi
        bad "$svc injects $key but nothing in this repository reads it" \
            "the container receives a variable no config, script or entrypoint consumes"
    done < "$TMP_DIR/svc_$svc"
done < "$TMP_DIR/services"

# ---------------------------------------------------------------------------
# [3] read by code, declared nowhere
# ---------------------------------------------------------------------------
head_ "[3] every name the code reads is declared or injected"

# ERROR scope: the engine, and the Laravel<->engine contract file. These are the
# settings that decide whether the platform works; a name here with no
# declaration and no injection is a setting that silently never applies.
{
    cat "$TMP_DIR/read_py"
    cat "$TMP_DIR/read_engine_sh"
    cat "$TMP_DIR/read_php_contract"
} | sort -u > "$TMP_DIR/read_strict"

while read -r key; do
    [ -n "$key" ] || continue
    if in_list "$key" "$TMP_DIR/deprecated"; then
        ok "$key is a deprecated engine alias; [9] reports its use"
    elif in_list "$key" "$TMP_DIR/platform_all"; then
        ok "$key is read and declared or injected"
    else
        bad "$key is read by code but is in no .env.example and is not injected" \
            "a setting that can never take effect in Docker"
    fi
done < "$TMP_DIR/read_strict"

# WARN scope: the rest of application/config. Stock Laravel names for drivers
# this stack does not use. Reported so the list is visible, never fatal.
grep -vxF -f "$TMP_DIR/read_php_contract" "$TMP_DIR/read_php" > "$TMP_DIR/read_php_stock"
_stock_unset=0
while read -r key; do
    [ -n "$key" ] || continue
    if ! in_list "$key" "$TMP_DIR/platform_all"; then
        _stock_unset=$((_stock_unset + 1))
    fi
done < "$TMP_DIR/read_php_stock"
info "$_stock_unset further application/config names are read with no declaration (stock Laravel drivers: memcached, sqs, ses, papertrail, slack, ...). Warnings only."

# ---------------------------------------------------------------------------
# [4] orphaned declarations
# ---------------------------------------------------------------------------
head_ "[4] every declared name is read or interpolated"

while read -r key; do
    [ -n "$key" ] || continue
    if in_list "$key" "$TMP_DIR/read_all" || in_list "$key" "$TMP_DIR/compose_injected" \
       || in_list "$key" "$TMP_DIR/compose_interp"; then
        ok "$key is declared and consumed"
    else
        warn "$key is declared in an .env.example and read by nothing" \
            "dead config: it looks tunable and is not"
    fi
done < "$TMP_DIR/decl_all"

# ---------------------------------------------------------------------------
# [5] compose interpolation coverage
# ---------------------------------------------------------------------------
head_ "[5] every \${VAR} compose interpolates is declared in the root .env"

while read -r key; do
    [ -n "$key" ] || continue
    if in_list "$key" "$TMP_DIR/decl_root"; then
        ok "\${$key} is declared in $ROOT_ENV"
    else
        warn "\${$key} is interpolated by compose but not declared in $ROOT_ENV" \
            "compose falls back to its own default, so the value cannot be set"
    fi
done < "$TMP_DIR/compose_interp"

# ---------------------------------------------------------------------------
# [6] the engine's APP_ENV allow-list
# ---------------------------------------------------------------------------
head_ "[6] APP_ENV values the engine will accept"

envs=$(tr '\n' ' ' < "$TMP_DIR/app_envs")
ok "the engine accepts: $envs"

for f in "$ROOT_ENV" "$APP_ENV_EXAMPLE" "$ENGINE_ENV_EXAMPLE"; do
    [ -f "$f" ] || continue
    v=$(dotenv_value "$f" APP_ENV)
    [ -n "$v" ] || continue
    if in_list "$v" "$TMP_DIR/app_envs"; then
        ok "$f sets APP_ENV=$v, which the engine accepts"
    else
        bad "$f sets APP_ENV=$v" "outside the engine's allow-list; Settings raises at import"
    fi
done

# compose's own default is the value used when there is no .env at all, so it
# is the one that silently applies most often.
_cd=$(sed -n 's/^[[:space:]]*APP_ENV:[[:space:]]*\${APP_ENV:-\([^}]*\)}.*/\1/p' "$COMPOSE" | head -n 1)
if [ -z "$_cd" ]; then
    warn "compose has no \${APP_ENV:-default} to check"
elif in_list "$_cd" "$TMP_DIR/app_envs"; then
    ok "compose's APP_ENV default is $_cd, which the engine accepts"
else
    bad "compose's APP_ENV default is $_cd" \
        "outside the engine's allow-list; a missing .env would stop the engine"
fi

# ---------------------------------------------------------------------------
# [7] the two sides of a shared contract
# ---------------------------------------------------------------------------
head_ "[7] Laravel and the engine agree on the shared contract"

while read -r key; do
    [ -n "$key" ] || continue
    in_php=no
    in_list "$key" "$TMP_DIR/read_php" && in_php=yes
    in_py=no
    in_list "$key" "$TMP_DIR/read_py" && in_py=yes
    if [ "$in_php" = "no" ] || [ "$in_py" = "no" ]; then
        ok "$key is read on one side only; nothing to reconcile"
        continue
    fi
    # Where does each side actually take the value from at runtime?
    #   compose injects it -> the root .env, through one ${VAR} for both services
    #   compose does not   -> that side's own bind-mounted .env file
    # A side that is not injected and has an example value but no real .env in a
    # deployment falls back to the code default, which is why the code default is
    # printed as well.
    _in_lar=no
    for s in laravel laravel-queue laravel-schedule; do
        if [ -f "$TMP_DIR/svc_$s" ] && in_list "$key" "$TMP_DIR/svc_$s"; then _in_lar=yes; fi
    done
    _in_eng=no
    for s in fastapi celery-worker celery-beat; do
        if [ -f "$TMP_DIR/svc_$s" ] && in_list "$key" "$TMP_DIR/svc_$s"; then _in_eng=yes; fi
    done
    if [ "$_in_lar" = "yes" ] && [ "$_in_eng" = "yes" ]; then
        ok "$key is injected into laravel and the engine from one variable"
        continue
    fi
    if [ "$_in_lar" = "yes" ]; then
        v_php=$(dotenv_value "$ROOT_ENV" "$key")
        src_php="the root .env (compose)"
    else
        v_php=$(dotenv_value "$APP_ENV_EXAMPLE" "$key")
        src_php="application/.env.example (compose does not inject it into laravel)"
    fi
    if [ "$_in_eng" = "yes" ]; then
        v_eng=$(dotenv_value "$ROOT_ENV" "$key")
        src_eng="the root .env (compose)"
    else
        v_eng=$(dotenv_value "$ENGINE_ENV_EXAMPLE" "$key")
        src_eng="ai-engine/.env.example (compose does not inject it into the engine)"
    fi
    if [ -z "$v_php" ] || [ -z "$v_eng" ]; then
        ok "$key is left for the operator to fill on each side"
    elif [ "$v_php" = "$v_eng" ]; then
        warn "$key is read from two different files that currently agree" \
            "laravel takes it from $src_php, the engine from $src_eng; rename it on one side only and every engine call 401s"
    else
        bad "$key differs between the two sides" \
            "laravel '$v_php' from $src_php vs engine '$v_eng' from $src_eng; the two containers will disagree at runtime"
    fi
done < "$TMP_DIR/read_php_contract"

# The framework's own default is the last line of defence, and it has to agree
# with the engine's default too.
for key in $("cat" "$TMP_DIR/read_php_contract"); do
    d_php=$(php_env_default "$key")
    [ -n "$d_php" ] || continue
    info "$key falls back to '$d_php' in application/config/ai_engine.php when unset"
done

# ---------------------------------------------------------------------------
# [8] YAML anchors
# ---------------------------------------------------------------------------
head_ "[8] every x- anchor is referenced by a service"

if [ ! -s "$TMP_DIR/anchor_defs" ]; then
    ok "the file defines no x- anchors"
else
    while read -r a; do
        [ -n "$a" ] || continue
        if in_list "$a" "$TMP_DIR/anchor_uses"; then
            _all=$(grep -o "[*]$a" "$COMPOSE" 2>/dev/null | wc -l | tr -d ' ')
            _merge=$(grep -c "<<:[[:space:]]*\*$a[[:space:]]*$" "$COMPOSE" 2>/dev/null || true)
            [ -n "$_merge" ] || _merge=0
            if [ "$_merge" -gt 0 ]; then
                ok "anchor &$a is merged by $_merge service(s)"
            else
                ok "anchor &$a is aliased $_all time(s) (build/volumes, not an env merge)"
            fi
        else
            bad "anchor &$a is defined but never referenced" \
                "dead anchor: the block it carries reaches no service"
        fi
    done < "$TMP_DIR/anchor_defs"

    # A merge that names an anchor nobody defined is a hard compose error, and
    # it drops the whole environment block rather than one key.
    while read -r a; do
        [ -n "$a" ] || continue
        if ! in_list "$a" "$TMP_DIR/anchor_defs"; then
            bad "<<: *$a merges an anchor that is not defined"
        fi
    done < "$TMP_DIR/anchor_uses"

    # Every service that injects anything says which anchors it used, so a
    # service that quietly stops merging one is visible in the output.
    while read -r svc; do
        [ -n "$svc" ] || continue
        [ -s "$TMP_DIR/svc_$svc" ] || continue
        _merged=$(grep -o '^#merged:.*' "$TMP_DIR/svc_$svc" 2>/dev/null | sed 's/^#merged://' | paste -sd, - 2>/dev/null || true)
        _keys=$(grep -vc '^#merged:' "$TMP_DIR/svc_$svc" | tr -d ' ')
        if [ -n "$_merged" ]; then
            info "$svc injects $_keys keys, merged from anchor(s) $_merged"
        else
            info "$svc injects $_keys keys, no anchor"
        fi
    done < "$TMP_DIR/services"
fi

# ---------------------------------------------------------------------------
# [9] deprecated names
# ---------------------------------------------------------------------------
head_ "[9] deprecated aliases are not the name in active use"

while IFS='|' read -r field rhs; do
    [ -n "$field" ] || continue
    # The first quoted name in the tuple is the canonical one; the greedy sed
    # would have taken the last, which is the deprecated alias.
    canonical=$(printf '%s' "$rhs" | sed 's/^"\([A-Z][A-Z0-9_]*\)".*/\1/')
    old=$(printf '%s' "$rhs" | sed 's/^[^,]*,//' | tr ',' '\n' | tr -d '" ' |
        grep -E '^[A-Z][A-Z0-9_]*$' || true)
    for o in $old; do
        if in_list "$o" "$TMP_DIR/compose_injected"; then
            warn "compose injects $o" \
                "the engine's canonical name is $canonical; $o is still accepted (config.py:57) and logs a deprecation warning"
        fi
        if in_list "$o" "$TMP_DIR/decl_root" || in_list "$o" "$TMP_DIR/decl_engine"; then
            warn "$o is an active assignment in an .env.example" \
                "rename it to $canonical; the engine logs a deprecation warning while $o supplies the value"
        fi
    done
done < "$TMP_DIR/alias_rows"

# ---------------------------------------------------------------------------
# [10] the config files the extraction trusted
# ---------------------------------------------------------------------------
head_ "[10] application/config/*.php parses"

if command -v php >/dev/null 2>&1; then
    for f in "$APP_CONFIG_DIR"/*.php; do
        if php -l "$f" >/dev/null 2>&1; then
            ok "$f parses"
        else
            bad "$f does not parse" "a parse error would make the env() extraction incomplete"
        fi
    done
else
    info "php is not on PATH; skipped the php -l precondition. The env() names were read with grep, which is a regex, not a parser."
fi

# ---------------------------------------------------------------------------
echo "== result: $PASS passed, $FAIL failed, $WARN warnings =="
echo "declarations: root $(wc -l < "$TMP_DIR/decl_root" | tr -d ' ') / application $(wc -l < "$TMP_DIR/decl_app" | tr -d ' ') / ai-engine $(wc -l < "$TMP_DIR/decl_engine" | tr -d ' ')"
echo "injected by compose: $(wc -l < "$TMP_DIR/compose_injected" | tr -d ' ') distinct keys across $(wc -l < "$TMP_DIR/services" | tr -d ' ') services"
echo "read by code: $(wc -l < "$TMP_DIR/read_php" | tr -d ' ') laravel + $(wc -l < "$TMP_DIR/read_py" | tr -d ' ') engine + $(wc -l < "$TMP_DIR/read_engine_sh" | tr -d ' ') engine entrypoint + $(wc -l < "$TMP_DIR/read_laravel_sh" | tr -d ' ') laravel entrypoint + $(wc -l < "$TMP_DIR/read_scripts" | tr -d ' ') host scripts"
echo "note: static parse. No container, service or request was executed."
[ "$FAIL" -eq 0 ]
