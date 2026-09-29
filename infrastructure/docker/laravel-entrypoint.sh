#!/bin/sh
# Container entrypoint for the Laravel service (infrastructure/docker/laravel.Dockerfile).
#
# POSIX sh on purpose: the php-fpm-alpine image has no bash by default and this
# file must keep working if bash is ever dropped from the base image.
#
# Boot order, each step able to stop the container:
#   1. verify the environment docker compose injected
#   2. wait for MySQL (compose healthchecks only gate `up`, not later restarts)
#   3. install the Vite build compiled in the image
#   4. recreate the storage skeleton hidden by the laravel-storage volume
#   5. link public/storage
#   6. migrate
#   7. exec `php artisan serve` so the PHP server is PID 1
#
# Every failure is logged with the remedy. Nothing is silenced with `2>/dev/null`
# or `|| true` where a failure means the app is broken.
set -e

APP_DIR="${APP_DIR:-/var/www/html}"
APP_HOST="${APP_HOST:-0.0.0.0}"
APP_PORT="${APP_PORT:-8000}"

# The assets stage of the Dockerfile writes here. It is deliberately NOT under
# $APP_DIR: docker-compose bind-mounts ./application over /var/www/html, so
# anything baked into the app directory itself is shadowed at runtime.
VITE_BUILD_DIR="${VITE_BUILD_DIR:-/opt/aidata/vite}"

DB_WAIT_HOST="${DB_WAIT_HOST:-${DB_HOST:-mysql}}"
DB_WAIT_PORT="${DB_WAIT_PORT:-${DB_PORT:-3306}}"
DB_WAIT_ATTEMPTS="${DB_WAIT_ATTEMPTS:-30}"
DB_WAIT_INTERVAL="${DB_WAIT_INTERVAL:-2}"
export DB_WAIT_HOST DB_WAIT_PORT

# Written when `artisan migrate --force` fails; the container healthcheck reads
# it so a broken schema shows up as `unhealthy` instead of a silent 500.
MIGRATE_FAILURE_MARKER="${MIGRATE_FAILURE_MARKER:-storage/framework/migrate_failed}"

log()   { printf '[laravel] %s\n' "$*"; }
warn()  { printf '[laravel] WARN: %s\n' "$*" >&2; }
error() { printf '[laravel] ERROR: %s\n' "$*" >&2; }
die()   { error "$*"; exit 1; }

# rm -rf only ever touches a fixed path built from APP_DIR. Anything that is
# not a strict child of $APP_DIR, or that is $APP_DIR itself, is refused.
safe_rm_rf() {
    _target="$1"
    case "$_target" in
        "$APP_DIR"/*) ;;
        *) error "refusing to remove '$_target' (outside $APP_DIR)"; return 1 ;;
    esac
    if [ "$_target" = "$APP_DIR" ]; then
        error "refusing to remove $APP_DIR"
        return 1
    fi
    rm -rf "$_target"
}

check_env() {
    if [ -z "${APP_KEY:-}" ]; then
        die "APP_KEY is empty. docker compose passes APP_KEY through from the root .env, and an empty value overrides any key in application/.env. Generate one with 'php artisan key:generate --show' and set it in the root .env."
    fi
    if [ -z "${DB_CONNECTION:-}" ] || [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ] || [ -z "${DB_USERNAME:-}" ]; then
        die "DB_CONNECTION/DB_HOST/DB_DATABASE/DB_USERNAME are not all set; check the laravel environment block in docker-compose.yml"
    fi
    if [ ! -f "$APP_DIR/artisan" ]; then
        die "no artisan in $APP_DIR - the bind mount points at the wrong directory"
    fi
}

wait_for_db() {
    _attempt=1
    while [ "$_attempt" -le "$DB_WAIT_ATTEMPTS" ]; do
        # `php -r` does not put trailing arguments in $argv, so the target is
        # read back from the exported environment instead.
        if php -r 'exit(@fsockopen(getenv("DB_WAIT_HOST"), (int) getenv("DB_WAIT_PORT"), $e, $s, 2) ? 0 : 1);'; then
            log "mysql $DB_WAIT_HOST:$DB_WAIT_PORT is accepting connections"
            return 0
        fi
        log "waiting for mysql $DB_WAIT_HOST:$DB_WAIT_PORT (attempt $_attempt/$DB_WAIT_ATTEMPTS)"
        _attempt=$((_attempt + 1))
        sleep "$DB_WAIT_INTERVAL"
    done
    return 1
}

# The compose bind mount puts /var/www/html/public under the host checkout, and
# Vite assets are gitignored, so public/build is empty on a fresh clone. Without
# this step @vite() throws ViteManifestNotFoundException and every page 500s.
install_vite_assets() {
    _src="$VITE_BUILD_DIR"
    _dst="$APP_DIR/public/build"
    _staging="$APP_DIR/public/.build.staging"
    _old=''

    if [ ! -f "$_src/manifest.json" ]; then
        die "no Vite manifest at $_src/manifest.json - this image was built without a working assets stage (rebuild: docker compose build --no-cache laravel)"
    fi

    if [ -e "$_staging" ] || [ -L "$_staging" ]; then
        safe_rm_rf "$_staging"
    fi

    if ! mkdir -p "$_staging" || ! cp -R "$_src"/. "$_staging"/; then
        safe_rm_rf "$_staging" || true
        if [ -f "$_dst/manifest.json" ]; then
            error "cannot write $APP_DIR/public, so the Vite build baked into the image was not installed."
            error "Serving the build already present at $_dst instead; it may be stale."
            return 0
        fi
        die "cannot write $APP_DIR/public and there is no Vite build at $_dst. Every page will fail with ViteManifestNotFoundException."
    fi

    if [ ! -f "$_staging/manifest.json" ]; then
        safe_rm_rf "$_staging" || true
        die "staged copy of $_src has no manifest.json"
    fi

    if [ -L "$_dst" ]; then
        warn "$_dst is a symlink left by the host checkout; replacing it with the image build"
        rm -f "$_dst"
    elif [ -e "$_dst" ]; then
        _old="$_dst.previous"
        safe_rm_rf "$_old" || true
        mv "$_dst" "$_old" || die "cannot move the existing $_dst aside"
    fi

    mv "$_staging" "$_dst" || die "cannot install the Vite build at $_dst"

    if [ -n "$_old" ]; then
        safe_rm_rf "$_old" || true
    fi
    log "vite manifest installed: $_dst/manifest.json"
}

# laravel-storage is a named volume, so on first boot it hides the framework
# directories that ship in the repository. Laravel does not recreate them.
prepare_storage() {
    for _dir in \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/datasets \
        storage/app/models
    do
        mkdir -p "$APP_DIR/$_dir" || warn "could not create $APP_DIR/$_dir"
    done
    chmod -R 0775 storage/framework storage/logs 2>/dev/null || true
    rm -f "$MIGRATE_FAILURE_MARKER" || true
}

link_public_storage() {
    if [ -L "$APP_DIR/public/storage" ] || [ -d "$APP_DIR/public/storage" ]; then
        log "public/storage already present, skipping storage:link"
        return 0
    fi
    if php artisan storage:link; then
        log "public/storage linked"
    else
        warn "artisan storage:link failed; anything served from storage/app/public will 404"
    fi
}

run_migrations() {
    if [ "${AUTO_MIGRATE:-true}" = "false" ]; then
        log "AUTO_MIGRATE=false, skipping artisan migrate"
        return 0
    fi

    log "running artisan migrate --force against $DB_HOST:${DB_PORT:-3306}/$DB_DATABASE"
    if _output="$(php artisan migrate --force 2>&1)"; then
        if [ -n "$_output" ]; then
            printf '%s\n' "$_output"
        fi
        log "migrations applied"
        return 0
    fi

    printf '%s\n' "$_output" >&2
    {
        printf 'timestamp=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        printf '%s\n' "$_output"
    } > "$MIGRATE_FAILURE_MARKER" 2>/dev/null || true

    error "artisan migrate --force FAILED (output above)"
    error "this container stays up so you can read the logs, but the healthcheck will report it unhealthy"
    error "after fixing the cause: docker compose exec laravel php artisan migrate --force"
    if [ "${AUTO_MIGRATE_STRICT:-false}" = "true" ]; then
        die "AUTO_MIGRATE_STRICT=true, refusing to serve with an unapplied schema"
    fi
    return 0
}

main() {
    cd "$APP_DIR"

    check_env

    if wait_for_db; then
        log "database reachable"
    else
        error "no database connection to $DB_WAIT_HOST:$DB_WAIT_PORT after $DB_WAIT_ATTEMPTS attempts; migrating anyway will fail"
    fi

    install_vite_assets
    prepare_storage
    link_public_storage
    run_migrations

    # php artisan serve shells out to `php -S`, which only forks PHP_CLI_SERVER_WORKERS
    # when the variable is in the process environment. A value sitting in .env is
    # never seen by the CLI server, so export it here (default 4, matching
    # application/.env.example).
    PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
    export PHP_CLI_SERVER_WORKERS
    log "starting php artisan serve on $APP_HOST:$APP_PORT with PHP_CLI_SERVER_WORKERS=$PHP_CLI_SERVER_WORKERS"

    # --no-reload keeps this process as PID 1 instead of letting the dev server
    # restart itself when it sees an .env change.
    exec php artisan serve --host="$APP_HOST" --port="$APP_PORT" --no-reload
}

main "$@"
