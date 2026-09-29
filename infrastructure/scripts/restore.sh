#!/bin/sh
# AIDataPlatform restore: replay a backup dump into the Postgres service.
#
# Usage: bash infrastructure/scripts/restore.sh [--yes] <backup.sql.gz>
#
# What actually happens: the dump was taken with `pg_dump --clean --if-exists`,
# so it contains DROP statements for the objects it knows about. Those objects
# are dropped and recreated; objects that exist in the database but not in the
# dump are left untouched. It is a merge, not a clean overwrite.
#
# Because Laravel and the AI engine write to the same tables while running, stop
# the writers first for a consistent restore:
#   docker compose stop laravel laravel-queue laravel-schedule fastapi celery-worker celery-beat
# and bring them back afterwards. Restoring underneath running services will
# reintroduce the data that was just overwritten. This script reports which of
# those containers are actually up so the answer is not guesswork.
#
# Safety properties, in the order they matter:
#  1. The archive is verified BEFORE anything is dropped. A file that is empty,
#     corrupt, not gzip, or not a pg_dump output is refused outright - a
#     restore is the one operation where refusing beats attempting.
#  2. A safety dump of the CURRENT database is taken first. If that dump
#     cannot be written, the restore is refused: there is no point applying a
#     dump you cannot undo.
#  3. The replay runs under `psql --single-transaction -v ON_ERROR_STOP=1`.
#     Everything lands or nothing does. A statement that fails aborts psql,
#     the connection drops, and Postgres rolls the whole replay back, so a
#     half-migrated database is not a reachable state.
#  4. The result is verified after the replay, so "psql exited 0 but the
#     database is wrong" cannot be reported as a success.
#
# EXIT CODES:
#   0  the dump was replayed and the post-restore checks passed
#   1  refused before touching the database (bad file, bad arguments)
#   2  could not run: no docker, no Compose v2, or postgres is unreachable
#   3  the replay FAILED and was rolled back - the database is unchanged
#   4  the replay exited 0 but verification failed - the state is unknown;
#      roll back from the safety dump this script printed
#
# Env: POSTGRES_USER, POSTGRES_DB, FILE (alternative to the positional argument)
set -eu

EXIT_OK=0
EXIT_REFUSED=1
EXIT_CANNOT_RUN=2
EXIT_ROLLED_BACK=3
EXIT_UNVERIFIED=4

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd) || exit "$EXIT_CANNOT_RUN"
cd "$ROOT_DIR" || {
    echo "[restore] FATAL: cannot cd to '$ROOT_DIR'" >&2
    exit "$EXIT_CANNOT_RUN"
}

die()        { echo "[restore] FATAL: $*" >&2; exit "$EXIT_REFUSED"; }
cannot_run() { echo "[restore] FATAL: $*" >&2; exit "$EXIT_CANNOT_RUN"; }

ASSUME_YES=0
FILE=""
for _arg in "$@"
do
    case "$_arg" in
        -y | --yes) ASSUME_YES=1 ;;
        -h | --help)
            echo "Usage: bash infrastructure/scripts/restore.sh [--yes] <backup.sql.gz>" >&2
            exit "$EXIT_REFUSED"
            ;;
        -*) die "unknown option '$_arg'; only --yes is accepted" ;;
        *)  FILE="$_arg" ;;
    esac
done
FILE="${FILE:-${FILE:-}}"
if [ -z "$FILE" ]; then
    echo "Usage: bash infrastructure/scripts/restore.sh [--yes] <backup.sql.gz>" >&2
    echo "Available:" >&2
    ls -lh ./backups/*.sql.gz 2>/dev/null >&2 || echo "  (no backups found)" >&2
    exit "$EXIT_REFUSED"
fi
[ -f "$FILE" ] || die "file not found: $FILE"

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi
PGUSER="${POSTGRES_USER:-aidata}"
PGDB="${POSTGRES_DB:-aidata}"

# ---------------------------------------------------------------------------
# Preflight. "I cannot reach the database" and "the import failed" have to be
# different answers; the first is exit 2 and never touches a transaction.
# ---------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 \
    || cannot_run "the 'docker' CLI is not on PATH; nothing was replayed"
docker compose version >/dev/null 2>&1 \
    || cannot_run "'docker compose' is unavailable (Compose v2 plugin missing or daemon down)"

pg_status=$(docker compose ps -a --format '{{.Service}} {{.Status}}' 2>/dev/null \
    | awk '$1 == "postgres" { sub(/^[^ ]+ /, ""); print; exit }')
case "$pg_status" in
    Up* | running*) ;;
    "")  cannot_run "no 'postgres' service in the compose project at $ROOT_DIR" ;;
    *)   cannot_run "the postgres service is '$pg_status'; start it with: docker compose up -d postgres" ;;
esac

# Can psql actually talk to the target database? Proved before the archive is
# even opened, so a wrong POSTGRES_DB is reported as a configuration problem
# rather than surfacing as a failed import half way through.
_ping=$(docker compose exec -T postgres psql -U "$PGUSER" -d "$PGDB" -tAc 'SELECT 1' 2>&1) \
    || cannot_run "psql cannot connect to database '$PGDB' as '$PGUSER': $(printf '%s' "$_ping" | tr '\n' ' ' | cut -c1-300)"
if [ "$(printf '%s' "$_ping" | tr -d '[:space:]')" != "1" ]; then
    cannot_run "psql answered '$_ping' instead of 1 for 'SELECT 1' on '$PGDB'"
fi

psql_q() {
    # psql_q <sql> -> single value on stdout, psql's own error text on stderr
    docker compose exec -T postgres psql -U "$PGUSER" -d "$PGDB" -tAc "$1"
}

TABLES_BEFORE=$(psql_q "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" \
    | tr -d '[:space:]')
case "$TABLES_BEFORE" in
    '' | *[!0-9]*) cannot_run "cannot read the table count of '$PGDB' (got '$(printf '%s' "$TABLES_BEFORE" | cut -c1-80)'); refusing to start" ;;
esac
echo "[restore] target: database='$PGDB' user='$PGUSER' ($TABLES_BEFORE tables in public)"

# ---------------------------------------------------------------------------
# Which writers are up. Restoring underneath them re-appends what was just
# overwritten, so this is printed whether or not --yes was passed.
# ---------------------------------------------------------------------------
WRITERS="laravel laravel-queue laravel-schedule fastapi celery-worker celery-beat"
RUNNING_WRITERS=""
for _w in $WRITERS
do
    _ws=$(docker compose ps -a --format '{{.Service}} {{.Status}}' 2>/dev/null \
        | awk -v s="$_w" '$1 == s { sub(/^[^ ]+ /, ""); print; exit }')
    case "$_ws" in
        Up* | running*) RUNNING_WRITERS="$RUNNING_WRITERS $_w" ;;
    esac
done
RUNNING_WRITERS="${RUNNING_WRITERS# }"

if [ -n "$RUNNING_WRITERS" ]; then
    echo "[restore] WARNING: these writers are still up:$RUNNING_WRITERS" >&2
    echo "[restore]          restore under them and they will write the new data back over this one." >&2
    echo "[restore]          stop them: docker compose stop $RUNNING_WRITERS" >&2
fi

if [ "$ASSUME_YES" -ne 1 ]; then
    printf "[restore] This will DROP and recreate the objects in '%s' inside database '%s'.\n" "$FILE" "$PGDB" >&2
    printf "[restore] Have you stopped%s laravel, fastapi, celery-worker and celery-beat? [y/N] " \
        "$(test -n "$RUNNING_WRITERS" && echo " $RUNNING_WRITERS" || echo "")" >&2
    if ! IFS= read -r ans; then
        echo >&2
        die "no answer on stdin; re-run with --yes for a non-interactive restore"
    fi
    case "$ans" in
        [yY] | [yY][eE][sS]) ;;
        *) die "aborted; nothing was replayed" ;;
    esac
fi

# ---------------------------------------------------------------------------
# 1) Verify the archive. Every one of these is checked BEFORE the first DROP,
#    because a restore that fails here has changed nothing at all.
# ---------------------------------------------------------------------------
TMP_ROOT=${TMPDIR:-/tmp}
WORK_DIR=$(mktemp -d "$TMP_ROOT/aidata-restore.XXXXXX") || cannot_run "cannot create a scratch directory under $TMP_ROOT"
cleanup() {
    case "$WORK_DIR" in
        "$TMP_ROOT"/aidata-restore.*) rm -rf "$WORK_DIR" ;;
        *) echo "[restore] WARN: refusing to remove '$WORK_DIR'" >&2 ;;
    esac
}
trap cleanup EXIT INT TERM

if [ ! -s "$FILE" ]; then
    die "$FILE is empty; there is nothing to restore"
fi
if ! gzip -t "$FILE" 2>/dev/null; then
    die "$FILE failed 'gzip -t' (truncated or not a gzip file); nothing was replayed"
fi

SQL_FILE="$WORK_DIR/restore.sql"
_gunzip_rc=0
gunzip -c "$FILE" > "$SQL_FILE" 2>"$WORK_DIR/gunzip.err" || _gunzip_rc=$?
if [ "$_gunzip_rc" -ne 0 ]; then
    die "cannot decompress $FILE (gunzip exit $_gunzip_rc): $(tr '\n' ' ' < "$WORK_DIR/gunzip.err" | cut -c1-200)"
fi
if [ ! -s "$SQL_FILE" ]; then
    die "$FILE decompressed to an empty file; refusing to replay it (this would drop the schema and create nothing)"
fi
if ! grep -q 'PostgreSQL database dump' "$SQL_FILE"; then
    die "$FILE decompressed to $(wc -c < "$SQL_FILE" | tr -d ' ') bytes with no 'PostgreSQL database dump' header; this is not a pg_dump output and was NOT replayed"
fi
if ! grep -qE '^(SET|CREATE|ALTER|COPY|DROP|LOCK|SELECT) ' "$SQL_FILE"; then
    die "$FILE has the pg_dump header but no SQL statements; it looks truncated and was NOT replayed"
fi

SQL_BYTES=$(wc -c < "$SQL_FILE" | tr -d ' ')
TABLES_IN_DUMP=$(grep -c '^CREATE TABLE ' "$SQL_FILE" || true)
COPY_BLOCKS_IN_DUMP=$(grep -c '^COPY ' "$SQL_FILE" || true)
echo "[restore] archive verified: $SQL_BYTES bytes uncompressed, $TABLES_IN_DUMP CREATE TABLE, $COPY_BLOCKS_IN_DUMP COPY blocks"
if [ "$TABLES_IN_DUMP" -eq 0 ]; then
    # Legitimate for a dump of an empty database, but it is also what a
    # half-finished migration chain produces, so it must never be silent.
    echo "[restore] WARNING: this dump creates no tables. Replaying it is a no-op." >&2
fi

# ---------------------------------------------------------------------------
# 2) Safety dump. A restore you cannot undo is not a restore; if this cannot be
#    written, the replay is refused rather than attempted.
# ---------------------------------------------------------------------------
SAFETY_DIR=${BACKUP_DIR:-$ROOT_DIR/backups}
mkdir -p "$SAFETY_DIR" || die "cannot create the safety-dump directory $SAFETY_DIR"
SAFETY="restore-safety_$(date +%Y%m%d_%H%M%S).sql.gz"
SAFETY_PART="$SAFETY_DIR/$SAFETY.part"
_safety_rc=0
echo "[restore] taking a safety dump of the current '$PGDB' to $SAFETY_DIR/$SAFETY ..."
docker compose exec -T postgres pg_dump -U "$PGUSER" -d "$PGDB" --clean --if-exists \
    > "$SAFETY_PART" 2>"$WORK_DIR/safety.err" || _safety_rc=$?
if [ "$_safety_rc" -ne 0 ]; then
    _err=$(tr '\n' ' ' < "$WORK_DIR/safety.err" 2>/dev/null | cut -c1-300)
    rm -f "$SAFETY_PART"
    die "could not take the safety dump (pg_dump exit $_safety_rc${_err:+: $_err}); refusing to replay without a rollback point"
fi
if [ ! -s "$SAFETY_PART" ]; then
    rm -f "$SAFETY_PART"
    die "the safety dump came out empty; refusing to replay without a rollback point"
fi
gzip -9 -c "$SAFETY_PART" > "$SAFETY_DIR/$SAFETY" || {
    rm -f "$SAFETY_PART" "$SAFETY_DIR/$SAFETY"
    die "gzip failed on the safety dump; refusing to replay without a rollback point"
}
gzip -t "$SAFETY_DIR/$SAFETY" 2>/dev/null || {
    rm -f "$SAFETY_PART" "$SAFETY_DIR/$SAFETY"
    die "the safety dump failed 'gzip -t'; refusing to replay without a rollback point"
}
rm -f "$SAFETY_PART"
echo "[restore] safety dump: $SAFETY_DIR/$SAFETY (roll back with: bash infrastructure/scripts/restore.sh --yes $SAFETY_DIR/$SAFETY)"

# ---------------------------------------------------------------------------
# 3) Replay, atomically. -i is explicit so the redirect into psql is never
#    left to an implementation default; --single-transaction + ON_ERROR_STOP
#    is what makes "half migrated" unreachable.
# ---------------------------------------------------------------------------
echo "[restore] replaying $FILE into '$PGDB' as user '$PGUSER' (single transaction) ..."
_replay_rc=0
docker compose exec -T -i postgres \
    psql -U "$PGUSER" -d "$PGDB" -v ON_ERROR_STOP=1 --single-transaction -q \
    < "$SQL_FILE" 2>"$WORK_DIR/psql.err" || _replay_rc=$?

if [ "$_replay_rc" -ne 0 ]; then
    _first=$(grep -m1 'ERROR:' "$WORK_DIR/psql.err" 2>/dev/null | cut -c1-300)
    echo "[restore] RESULT=failed exit=$_replay_rc rolled_back=yes (nothing was committed)" >&2
    echo "[restore] first error: ${_first:-<see below>}" >&2
    sed -n '1,20p' "$WORK_DIR/psql.err" >&2 || true
    echo "[restore] the database is still at its pre-restore state." >&2
    echo "[restore] if it turns out the dump itself is wrong, roll back anyway with:" >&2
    echo "[restore]   bash infrastructure/scripts/restore.sh --yes $SAFETY_DIR/$SAFETY" >&2
    exit "$EXIT_ROLLED_BACK"
fi

# ---------------------------------------------------------------------------
# 4) Verify. psql exiting 0 means the transaction committed, not that the
#    result is the data the operator expected.
# ---------------------------------------------------------------------------
_verify_rc=0
TABLES_AFTER=$(psql_q "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" \
    | tr -d '[:space:]') || _verify_rc=$?
if [ "$_verify_rc" -ne 0 ]; then
    echo "[restore] RESULT=unverified - the replay committed but the database could not be read back." >&2
    echo "[restore] roll back from: $SAFETY_DIR/$SAFETY" >&2
    exit "$EXIT_UNVERIFIED"
fi
case "$TABLES_AFTER" in
    '' | *[!0-9]*)
        echo "[restore] RESULT=unverified - the replay committed but '$TABLES_AFTER' is not a table count." >&2
        echo "[restore] roll back from: $SAFETY_DIR/$SAFETY" >&2
        exit "$EXIT_UNVERIFIED"
        ;;
esac

MIGRATIONS=$(psql_q "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations'" \
    | tr -d '[:space:]') || MIGRATIONS="unknown"

echo "[restore] post-restore: $TABLES_AFTER tables in public (was $TABLES_BEFORE), migrations table: $MIGRATIONS"
if [ "$TABLES_AFTER" -eq 0 ] && [ "$TABLES_BEFORE" -gt 0 ]; then
    echo "[restore] RESULT=unverified - public is now empty where it held $TABLES_BEFORE tables." >&2
    echo "[restore] this is what a dump of an empty database looks like after a replay." >&2
    echo "[restore] roll back from: $SAFETY_DIR/$SAFETY" >&2
    exit "$EXIT_UNVERIFIED"
fi
if [ "$TABLES_IN_DUMP" -gt 0 ] && [ "$TABLES_AFTER" -lt "$TABLES_IN_DUMP" ]; then
    echo "[restore] RESULT=unverified - the dump creates $TABLES_IN_DUMP tables but public holds $TABLES_AFTER." >&2
    echo "[restore] roll back from: $SAFETY_DIR/$SAFETY" >&2
    exit "$EXIT_UNVERIFIED"
fi

echo "[restore] RESULT=ok replayed=$FILE safety=$SAFETY_DIR/$SAFETY"
echo "[restore] next: docker compose start ${RUNNING_WRITERS:-$WRITERS}"
echo "[restore] then:  bash infrastructure/scripts/healthcheck.sh"
echo "[restore] and:   docker compose exec laravel php artisan migrate --force"
echo "[restore]        docker compose exec fastapi alembic upgrade head"
exit "$EXIT_OK"
