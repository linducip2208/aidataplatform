#!/usr/bin/env bash
# AIDataPlatform backup: postgres dump (gz) + manifest of datasets/models volumes.
# Usage: bash infrastructure/scripts/backup.sh
# Output: ./backups/aidata_YYYYmmdd_HHMMSS.sql.gz (+ .manifest.txt)
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
TS="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Load .env for credentials (safe: only POSTGRES_* used)
if [ -f .env ]; then set -a; source .env; set +a; fi
PGUSER="${POSTGRES_USER:-aidata}"
PGDB="${POSTGRES_DB:-aidata}"

echo "[backup] dumping postgres ($PGDB) ..."
docker compose exec -T postgres pg_dump -U "$PGUSER" -d "$PGDB" --clean --if-exists \
  | gzip > "$BACKUP_DIR/aidata_${TS}.sql.gz"

echo "[backup] volume manifest ..."
{
  echo "timestamp=$TS"
  docker compose exec -T laravel du -sh /var/www/html/storage/app/datasets 2>/dev/null || echo "datasets=unknown"
  docker compose exec -T fastapi du -sh /app/data/models 2>/dev/null || echo "models=unknown"
  docker compose ps --format '{{.Name}} {{.Status}}'
} > "$BACKUP_DIR/aidata_${TS}.manifest.txt" || true

# Optional S3 sync
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
  echo "[backup] syncing to s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}"
  aws s3 cp "$BACKUP_DIR/aidata_${TS}.sql.gz" "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}" || echo "[backup] S3 upload failed (continuing)"
fi

# Retention prune (local)
find "$BACKUP_DIR" -name 'aidata_*.sql.gz' -mtime +"$RETENTION" -delete || true
find "$BACKUP_DIR" -name 'aidata_*.manifest.txt' -mtime +"$RETENTION" -delete || true

echo "[backup] done: $BACKUP_DIR/aidata_${TS}.sql.gz"
ls -lh "$BACKUP_DIR" | tail -5
