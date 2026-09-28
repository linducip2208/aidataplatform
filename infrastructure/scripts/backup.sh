#!/bin/sh
# AIDataPlatform backup: Postgres dump (gzipped) plus a manifest of what is running.
#
# Usage: bash infrastructure/scripts/backup.sh
# Output: <BACKUP_DIR>/aidata_YYYYmmdd_HHMMSS.sql.gz (+ .manifest.txt)
#
# Env: BACKUP_DIR, BACKUP_RETENTION_DAYS, BACKUP_S3_BUCKET, BACKUP_S3_PREFIX
#
# The dump is written to a file before being compressed instead of being piped
# into gzip: POSIX sh has no `set -o pipefail`, so `pg_dump | gzip` would report
# success (gzip's exit code) even when pg_dump had already failed and left an
# empty .gz behind. `pg_dump --clean --if-exists` also embeds DROP statements, so
# a dump is meant to be replayed over an existing database by restore.sh.
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR"

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
TS=$(date +%Y%m%d_%H%M%S)
DUMP_NAME="aidata_${TS}.sql"
DUMP_GZ="${DUMP_NAME}.gz"
MANIFEST="aidata_${TS}.manifest.txt"

die() { echo "[backup] FATAL: $*" >&2; exit 1; }

# Only ever remove files this script created in BACKUP_DIR.
remove_dump() {
    case "$1" in
        */"$DUMP_NAME") rm -f "$1" ;;
        *) echo "[backup] refusing to remove '$1'" >&2 ;;
    esac
}

mkdir -p "$BACKUP_DIR" || die "cannot create $BACKUP_DIR"

# Credentials come from the root .env. Sourced once, up front, so every command
# below agrees on the database name.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi
PGUSER="${POSTGRES_USER:-aidata}"
PGDB="${POSTGRES_DB:-aidata}"

echo "[backup] dumping postgres database '$PGDB' as user '$PGUSER' ..."
if ! docker compose exec -T postgres pg_dump -U "$PGUSER" -d "$PGDB" --clean --if-exists > "$BACKUP_DIR/$DUMP_NAME"; then
    remove_dump "$BACKUP_DIR/$DUMP_NAME"
    die "pg_dump failed; no archive was written (nothing to restore from)"
fi
if [ ! -s "$BACKUP_DIR/$DUMP_NAME" ]; then
    remove_dump "$BACKUP_DIR/$DUMP_NAME"
    die "pg_dump produced an empty file; refusing to archive it"
fi
gzip -9 "$BACKUP_DIR/$DUMP_NAME" || die "gzip failed on $DUMP_NAME"
echo "[backup] wrote $BACKUP_DIR/$DUMP_GZ"

echo "[backup] volume manifest ..."
{
    echo "timestamp=$TS"
    echo "database=$PGDB"
    echo "database_user=$PGUSER"
    # Volume names come from the pgdata/datasets/models entries in docker-compose.yml.
    echo "postgres_volume=${VOLUME_PREFIX:-aidata}-pgdata"
    echo "datasets_volume=${VOLUME_PREFIX:-aidata}-datasets"
    echo "models_volume=${VOLUME_PREFIX:-aidata}-models"
    docker compose exec -T laravel du -sh /var/www/html/storage/app/datasets 2>/dev/null || echo "datasets_size=unknown"
    docker compose exec -T fastapi du -sh /app/data/models 2>/dev/null || echo "models_size=unknown"
    docker compose ps --format '{{.Name}} {{.Status}}'
} > "$BACKUP_DIR/$MANIFEST" || echo "[backup] WARN: could not write $MANIFEST"

if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    echo "[backup] syncing to s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}/"
    if ! aws s3 cp "$BACKUP_DIR/$DUMP_GZ" "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}/$DUMP_GZ"; then
        echo "[backup] WARN: S3 upload failed; the local archive is still valid"
    fi
fi

find "$BACKUP_DIR" -name 'aidata_*.sql.gz' -mtime "+$RETENTION" -print -delete || true
find "$BACKUP_DIR" -name 'aidata_*.manifest.txt' -mtime "+$RETENTION" -print -delete || true

echo "[backup] done: $BACKUP_DIR/$DUMP_GZ"
ls -lh "$BACKUP_DIR" | tail -5

# A pg_dump only covers the database. Anything on the datasets/models volumes
# (raw uploads, model artefacts) is listed in the manifest but NOT archived;
# add a volume backup here if those must be recoverable.
