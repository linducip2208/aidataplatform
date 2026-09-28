#!/usr/bin/env bash
# AIDataPlatform restore: gunzip a backup dump into postgres.
# Usage: bash infrastructure/scripts/restore.sh backups/aidata_YYYYmmdd_HHMMSS.sql.gz
# WARNING: overwrites data in POSTGRES_DB. Takes services down briefly? No — restores live.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

FILE="${1:-${FILE:-}}"
if [ -z "$FILE" ]; then
  echo "Usage: bash infrastructure/scripts/restore.sh <backup.sql.gz>" >&2
  echo "Available:"; ls -lh ./backups/*.sql.gz 2>/dev/null || echo "  (no backups found)"
  exit 1
fi
if [ ! -f "$FILE" ]; then echo "[restore] file not found: $FILE" >&2; exit 1; fi

if [ -f .env ]; then set -a; source .env; set +a; fi
PGUSER="${POSTGRES_USER:-aidata}"
PGDB="${POSTGRES_DB:-aidata}"

read -r -p "[restore] This will OVERWRITE database '$PGDB'. Continue? [y/N] " ans
case "$ans" in [yY][eE][sS]|[yY]) ;; *) echo "aborted."; exit 1;; esac

echo "[restore] restoring $FILE -> $PGDB ..."
gunzip -c "$FILE" | docker compose exec -T postgres psql -U "$PGUSER" -d "$PGDB" -v ON_ERROR_STOP=1

echo "[restore] done. Run healthcheck + migrations:"
echo "  bash infrastructure/scripts/healthcheck.sh"
echo "  docker compose exec laravel php artisan migrate --force"
