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
#   docker compose stop laravel fastapi celery-worker celery-beat
# and bring them back afterwards. Restoring underneath running services will
# reintroduce the data that was just overwritten.
#
# Env: POSTGRES_USER, POSTGRES_DB
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR"

ASSUME_YES=0
case "${1:-}" in
    -y|--yes) ASSUME_YES=1; shift ;;
esac

die() { echo "[restore] FATAL: $*" >&2; exit 1; }

FILE="${1:-${FILE:-}}"
if [ -z "$FILE" ]; then
    echo "Usage: bash infrastructure/scripts/restore.sh [--yes] <backup.sql.gz>" >&2
    echo "Available:" >&2
    ls -lh ./backups/*.sql.gz 2>/dev/null >&2 || echo "  (no backups found)" >&2
    exit 1
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

if [ "$ASSUME_YES" -ne 1 ]; then
    printf "[restore] This will DROP and recreate the objects in '%s' inside database '%s'.\n" "$FILE" "$PGDB"
    printf "[restore] Have you stopped laravel, fastapi, celery-worker and celery-beat? [y/N] " >&2
    if ! IFS= read -r ans; then
        echo >&2
        die "no answer on stdin; re-run with --yes for a non-interactive restore"
    fi
    case "$ans" in
        [yY] | [yY][eE][sS]) ;;
        *) die "aborted" ;;
    esac
fi

# Decompress to a scratch file first: without `set -o pipefail` a
# `gunzip | psql` pipeline would report psql's status and could hide a
# truncation error, and ON_ERROR_STOP already aborts on the first bad statement.
TMP_ROOT=${TMPDIR:-/tmp}
WORK_DIR=$(mktemp -d "$TMP_ROOT/aidata-restore.XXXXXX") || die "cannot create a scratch directory under $TMP_ROOT"
cleanup() {
    case "$WORK_DIR" in
        "$TMP_ROOT"/aidata-restore.*) rm -rf "$WORK_DIR" ;;
        *) echo "[restore] WARN: refusing to remove '$WORK_DIR'" >&2 ;;
    esac
}
trap cleanup EXIT INT TERM

SQL_FILE="$WORK_DIR/restore.sql"
gunzip -c "$FILE" > "$SQL_FILE" || die "cannot decompress $FILE"
[ -s "$SQL_FILE" ] || die "$FILE decompressed to an empty file"

echo "[restore] replaying $FILE into '$PGDB' as user '$PGUSER' ..."
docker compose exec -T postgres psql -U "$PGUSER" -d "$PGDB" -v ON_ERROR_STOP=1 -q < "$SQL_FILE" \
    || die "psql failed; the database is left in whatever state the dump reached. Fix the reported error, then re-run the restore."

echo "[restore] done."
echo "[restore] next: docker compose start laravel fastapi celery-worker celery-beat"
echo "[restore] then:  bash infrastructure/scripts/healthcheck.sh"
echo "[restore] and:   docker compose exec laravel php artisan migrate --force"
