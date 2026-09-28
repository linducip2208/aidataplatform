#!/bin/sh
# Container entrypoint: wait for Postgres, apply Alembic migrations, hand off to the service.
set -e

APP_DIR="${APP_DIR:-/code}"
PG_HOST="${PGHOST_FOR_MIGRATION:-postgres}"
PG_PORT="${PGPORT_FOR_MIGRATION:-5432}"
PG_WAIT_ATTEMPTS="${PG_WAIT_ATTEMPTS:-60}"
PG_WAIT_INTERVAL="${PG_WAIT_INTERVAL:-2}"

log() { printf '[entrypoint] %s\n' "$*"; }
warn() { printf '[entrypoint] WARNING: %s\n' "$*" >&2; }
fatal() { printf '[entrypoint] FATAL: %s\n' "$*" >&2; }

if ! cd "$APP_DIR"; then
    warn "cannot enter APP_DIR=${APP_DIR}, staying in $(pwd)"
fi

tcp_open() {
    python -c 'import socket,sys; socket.create_connection((sys.argv[1], int(sys.argv[2])), 2).close()' \
        "$PG_HOST" "$PG_PORT" >/dev/null 2>&1
}

wait_for_postgres() {
    attempt=1
    while [ "$attempt" -le "$PG_WAIT_ATTEMPTS" ]; do
        if tcp_open; then
            log "postgres ${PG_HOST}:${PG_PORT} is accepting connections"
            return 0
        fi
        log "waiting for postgres ${PG_HOST}:${PG_PORT} (attempt ${attempt}/${PG_WAIT_ATTEMPTS})"
        attempt=$((attempt + 1))
        sleep "$PG_WAIT_INTERVAL"
    done
    return 1
}

run_migrations() {
    if migration_output=$(alembic upgrade head 2>&1); then
        [ -n "$migration_output" ] && printf '%s\n' "$migration_output"
        log "alembic upgrade head applied"
        return 0
    fi
    printf '%s\n' "$migration_output" >&2
    if [ "${AUTO_MIGRATE_STRICT:-false}" = "true" ]; then
        fatal "alembic upgrade head failed and AUTO_MIGRATE_STRICT=true"
        exit 1
    fi
    warn "alembic upgrade head failed; starting anyway so health probes stay reachable"
}

main() {
    if [ "$#" -eq 0 ]; then
        fatal "no command given; nothing to exec"
        exit 1
    fi

    if [ "${AUTO_MIGRATE:-true}" = "false" ]; then
        log "AUTO_MIGRATE=false, skipping migrations"
    elif wait_for_postgres; then
        run_migrations
    elif [ "${AUTO_MIGRATE_STRICT:-false}" = "true" ]; then
        fatal "postgres not reachable at ${PG_HOST}:${PG_PORT} after ${PG_WAIT_ATTEMPTS} attempts"
        exit 1
    else
        warn "postgres not reachable at ${PG_HOST}:${PG_PORT} after ${PG_WAIT_ATTEMPTS} attempts, skipping migrations"
    fi

    log "exec $*"
    exec "$@"
}

main "$@"
