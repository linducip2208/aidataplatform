#!/bin/bash
# AIDataPlatform — native backup (mysqldump + files, no Docker).
#
# Reads DB credentials from application/.env (DB_* keys). Keeps 14 days of
# dumps under $BACKUP_DIR (default APP_DIR/backups). Exit 0 ok, 1 failed.
set -eu

APP_DIR="${APP_DIR:-/www/wwwroot/aidata}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

ENV_FILE="$APP_DIR/application/.env"
[ -f "$ENV_FILE" ] || { echo "FATAL: $ENV_FILE missing" >&2; exit 1; }

DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d= -f2 | tr -d ' "')
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d= -f2 | tr -d ' "')
DB_DATABASE=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2 | tr -d ' "')
DB_USERNAME=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2 | tr -d ' "')
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2 | tr -d ' "')

mkdir -p "$BACKUP_DIR"
TS=$(date +%Y%m%d_%H%M%S)
DUMP="$BACKUP_DIR/aidata_${TS}.sql.gz"

export MYSQL_PWD="$DB_PASSWORD"
mysqldump -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" \
    --single-transaction --routines --triggers --add-drop-table \
    "$DB_DATABASE" 2>/dev/null | gzip -9 > "${DUMP}.part" \
    || { echo "FATAL: mysqldump failed" >&2; rm -f "${DUMP}.part"; exit 1; }
unset MYSQL_PWD
mv "${DUMP}.part" "$DUMP"

tar czf "$BACKUP_DIR/files_${TS}.tgz" \
    -C "$APP_DIR/application/storage/app" datasets 2>/dev/null || true
tar czf "$BACKUP_DIR/models_${TS}.tgz" \
    -C "$APP_DIR/ai-engine/data" models 2>/dev/null || true

find "$BACKUP_DIR" -name 'aidata_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete || true
find "$BACKUP_DIR" -name 'files_*.tgz' -mtime +"$RETENTION_DAYS" -delete || true
find "$BACKUP_DIR" -name 'models_*.tgz' -mtime +"$RETENTION_DAYS" -delete || true

echo "backup ok: $DUMP ($(du -h "$DUMP" | cut -f1))"
