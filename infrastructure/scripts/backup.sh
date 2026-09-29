#!/bin/sh
# AIDataPlatform backup: MySQL dump (gzipped) plus a manifest of what is running.
#
# Usage: bash infrastructure/scripts/backup.sh
#        bash infrastructure/scripts/backup.sh --verify-only <archive.sql.gz>
# Output: <BACKUP_DIR>/aidata_YYYYmmdd_HHMMSS.sql.gz (+ .manifest.txt)
#
# --verify-only validates an EXISTING archive (gzip integrity, mysqldump header,
# SQL presence) and exits without touching Docker or the database. Exit 0 means
# "this file would be accepted by restore.sh"; exit 1 means "refused, with the
# reason on stderr". It exists so a cron job or a drill can prove a stored
# backup is still replayable without replaying it.
#
# Env: BACKUP_DIR, BACKUP_RETENTION_DAYS, BACKUP_S3_BUCKET, BACKUP_S3_PREFIX,
#      VOLUME_PREFIX, MYSQL_USER, MYSQL_DATABASE, MYSQL_PASSWORD
#
# Why this is shaped the way it is:
#
#  * The dump is written to a .part file, verified, compressed, verified again
#    and only then renamed into place. An archive that exists under its final
#    name is, by construction, one that passed every check below. The dump is
#    NOT piped into gzip: POSIX sh has no `set -o pipefail`, so
#    `mysqldump | gzip` reports gzip's exit status and leaves an empty .gz
#    behind when mysqldump died - the exact failure a backup must never have.
#  * An empty file is not a valid dump either, so the .part is checked for the
#    `-- MySQL dump` header mysqldump always emits and for at least
#    one SQL statement. A zero-row database is legitimate and still passes
#    (its header + SET statements are real SQL); a truncated or error-page file
#    does not.
#  * `mysqldump --add-drop-table` embeds DROP statements, so a dump is meant
#    to be replayed over an existing database by restore.sh.
#
# EXIT CODES:
#   0  an archive was written and verified (an S3 upload failure is a warning;
#      --verify-only: the named archive passed every check)
#   1  the backup failed - nothing valid was produced, and any partial file has
#      been removed, so there is no archive to restore from (--verify-only: the
#      named archive failed validation, with the reason on stderr)
#   2  the backup could not be started: no docker, no Compose v2, or the
#      mysql service is not running
set -eu

EXIT_OK=0
EXIT_FAIL=1
EXIT_ERROR=2

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd) || exit "$EXIT_ERROR"
cd "$ROOT_DIR" || {
    echo "[backup] FATAL: cannot cd to '$ROOT_DIR'" >&2
    exit "$EXIT_ERROR"
}

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
TS=$(date +%Y%m%d_%H%M%S)
DUMP_NAME="aidata_${TS}.sql"
DUMP_GZ="${DUMP_NAME}.gz"
DUMP_PART="${DUMP_NAME}.part"
DUMP_ERR="${DUMP_NAME}.err"
MANIFEST="aidata_${TS}.manifest.txt"

die() { echo "[backup] FATAL: $*" >&2; exit "$EXIT_FAIL"; }
cannot_run() { echo "[backup] FATAL: $*" >&2; exit "$EXIT_ERROR"; }

# Only ever remove files this run created, matched on the exact name. A stray
# argument must not turn a failed dump into a `rm -f` of something else.
discard() {
    case "$1" in
        *"/$DUMP_PART" | *"/$DUMP_NAME" | *"/$DUMP_GZ" | *"/$DUMP_ERR") rm -f "$1" ;;
        *) echo "[backup] WARN: refusing to remove '$1'" >&2 ;;
    esac
}

# ---------------------------------------------------------------------------
# --verify-only: validate an existing archive and stop. No docker, no database.
# The four checks are the same ones a fresh dump passes in steps 2-3 below
# (gzip integrity, decompress, mysqldump header, SQL present), so "verified" here
# means restore.sh section 1 would accept this file.
# ---------------------------------------------------------------------------
VERIFY_ONLY=0
VERIFY_FILE=""
for _arg in "$@"
do
    case "$_arg" in
        --verify-only) VERIFY_ONLY=1 ;;
        -h | --help)
            echo "Usage: bash infrastructure/scripts/backup.sh" >&2
            echo "       bash infrastructure/scripts/backup.sh --verify-only <archive.sql.gz>" >&2
            exit "$EXIT_OK"
            ;;
        -*) die "unknown option '$_arg'; only --verify-only is accepted" ;;
        *)  VERIFY_FILE="$_arg" ;;
    esac
done
if [ "$VERIFY_ONLY" -eq 1 ]; then
    [ -n "$VERIFY_FILE" ] || die "usage: bash infrastructure/scripts/backup.sh --verify-only <archive.sql.gz>"
    [ -f "$VERIFY_FILE" ] || die "file not found: $VERIFY_FILE"
    [ -s "$VERIFY_FILE" ] || die "$VERIFY_FILE is empty; there is nothing to verify"
    gzip -t "$VERIFY_FILE" 2>/dev/null || die "$VERIFY_FILE failed 'gzip -t' (truncated or not a gzip file)"
    _vdir=${TMPDIR:-/tmp}
    _vwork=$(mktemp -d "$_vdir/aidata-backup-verify.XXXXXX") || die "cannot create a scratch directory under '$_vdir'"
    case "$_vwork" in
        "$_vdir"/aidata-backup-verify.*) ;;
        *) echo "[backup] FATAL: unexpected scratch path '$_vwork'" >&2; exit "$EXIT_FAIL" ;;
    esac
    _vsql="$_vwork/check.sql"
    _vrc=0
    gunzip -c "$VERIFY_FILE" > "$_vsql" 2>"$_vwork/gunzip.err" || _vrc=$?
    if [ "$_vrc" -ne 0 ]; then
        _verr=$(tr '\n' ' ' < "$_vwork/gunzip.err" 2>/dev/null | cut -c1-200)
        rm -rf "$_vwork"
        die "cannot decompress $VERIFY_FILE (gunzip exit $_vrc): $_verr"
    fi
    if ! grep -q 'MySQL dump' "$_vsql"; then
        _vbytes=$(wc -c < "$_vsql" | tr -d ' ')
        rm -rf "$_vwork"
        die "$VERIFY_FILE decompressed to $_vbytes bytes with no 'MySQL dump' header; this is not a mysqldump output"
    fi
    if ! grep -qE '^(SET|CREATE|ALTER|DROP|LOCK|INSERT|SELECT|USE) ' "$_vsql"; then
        rm -rf "$_vwork"
        die "$VERIFY_FILE has the mysqldump header but no SQL statements; it looks truncated"
    fi
    _vbytes=$(wc -c < "$_vsql" | tr -d ' ')
    _vtables=$(grep -c '^CREATE TABLE ' "$_vsql" || true)
    _vinserts=$(grep -c '^INSERT ' "$_vsql" || true)
    rm -rf "$_vwork"
    echo "[backup] RESULT=verified file=$VERIFY_FILE bytes_uncompressed=$_vbytes create_table=$_vtables inserts=$_vinserts"
    echo "[backup] note: the dump covers the database only; datasets/models files are NOT in it (volume snapshot, docs/backup-restore.md section 5)."
    exit "$EXIT_OK"
fi

# ---------------------------------------------------------------------------
# Preflight. "docker is missing" and "the database is not running" have to be
# different answers, and neither is a successful exit.
# ---------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 \
    || cannot_run "the 'docker' CLI is not on PATH; no dump was attempted"
docker compose version >/dev/null 2>&1 \
    || cannot_run "'docker compose' is unavailable (Compose v2 plugin missing or daemon down)"

db_status=$(docker compose ps -a --format '{{.Service}} {{.Status}}' 2>/dev/null \
    | awk '$1 == "mysql" { sub(/^[^ ]+ /, ""); print; exit }')
case "$db_status" in
    Up* | running*) ;;
    "")  cannot_run "no 'mysql' service in the compose project at $ROOT_DIR" ;;
    *)   cannot_run "the mysql service is '$db_status'; start it with: docker compose up -d mysql" ;;
esac

mkdir -p "$BACKUP_DIR" || die "cannot create $BACKUP_DIR"
if [ ! -w "$BACKUP_DIR" ]; then
    die "$BACKUP_DIR is not writable by $(id -un 2>/dev/null || echo "uid $(id -u)")"
fi

# Credentials come from the root .env. Sourced once, up front, so every command
# below agrees on the database name.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi
DBUSER="${MYSQL_USER:-aidata}"
DBNAME="${MYSQL_DATABASE:-aidata}"
# Passed as MYSQL_PWD (not -p on the command line) so the password never shows
# in `ps` output and mysqldump stays quiet on stderr.
DBPASS="${MYSQL_PASSWORD:-changeme}"

WARN=0
warn() { echo "[backup] WARN: $*" >&2; WARN=$((WARN + 1)); }

# ---------------------------------------------------------------------------
# 1) Dump to .part. A non-zero exit here means mysqldump failed or the container
#    went away mid-dump; either way the .part is discarded, never kept.
# ---------------------------------------------------------------------------
echo "[backup] dumping mysql database '$DBNAME' as user '$DBUSER' ..."
_dump_rc=0
# `if ! cmd` would report the status of the `!`, not of cmd, so the status is
# captured the other way round: with `|| _dump_rc=$?` a non-zero dump cannot
# escape this branch.
docker compose exec -T -e MYSQL_PWD="$DBPASS" mysql mysqldump -h 127.0.0.1 -u "$DBUSER" \
    --single-transaction --routines --triggers --add-drop-table "$DBNAME" \
    > "$BACKUP_DIR/$DUMP_PART" 2> "$BACKUP_DIR/$DUMP_ERR" || _dump_rc=$?
if [ "$_dump_rc" -ne 0 ]; then
    _err=$(tr '\n' ' ' < "$BACKUP_DIR/$DUMP_ERR" 2>/dev/null | cut -c1-400)
    discard "$BACKUP_DIR/$DUMP_PART"
    discard "$BACKUP_DIR/$DUMP_ERR"
    die "mysqldump failed (exit $_dump_rc); no archive was written, so there is nothing to restore from${_err:+: $_err}"
fi
if [ -s "$BACKUP_DIR/$DUMP_ERR" ]; then
    warn "mysqldump wrote to stderr: $(tr '\n' ' ' < "$BACKUP_DIR/$DUMP_ERR" | cut -c1-300)"
fi
discard "$BACKUP_DIR/$DUMP_ERR"

# ---------------------------------------------------------------------------
# 2) The .part must be non-empty AND look like a mysqldump. A zero-byte file, a
#    shell error that landed in the redirect, or a truncated stream all pass
#    `-s` on nothing useful; only the header and a SQL statement prove it.
# ---------------------------------------------------------------------------
if [ ! -s "$BACKUP_DIR/$DUMP_PART" ]; then
    discard "$BACKUP_DIR/$DUMP_PART"
    die "mysqldump produced an empty file; refusing to archive it (a restore from it would silently empty the database)"
fi
DUMP_BYTES=$(wc -c < "$BACKUP_DIR/$DUMP_PART" | tr -d ' ')
if ! grep -q 'MySQL dump' "$BACKUP_DIR/$DUMP_PART"; then
    discard "$BACKUP_DIR/$DUMP_PART"
    die "the dump is $DUMP_BYTES bytes but carries no 'MySQL dump' header; this is not a mysqldump output and will not be archived"
fi
if ! grep -qE '^(SET|CREATE|ALTER|DROP|LOCK|INSERT|SELECT|USE) ' "$BACKUP_DIR/$DUMP_PART"; then
    discard "$BACKUP_DIR/$DUMP_PART"
    die "the dump has the mysqldump header but no SQL statements; it looks truncated and will not be archived"
fi

TABLES=$(grep -c '^CREATE TABLE ' "$BACKUP_DIR/$DUMP_PART" || true)
INSERTS=$(grep -c '^INSERT ' "$BACKUP_DIR/$DUMP_PART" || true)
echo "[backup] dump verified: $DUMP_BYTES bytes, $TABLES CREATE TABLE, $INSERTS INSERT statements"
if [ "$TABLES" -eq 0 ]; then
    # Legitimate for a genuinely empty database, but worth saying out loud: it
    # is also what a restore into a half-migrated stack looks like from here.
    warn "the dump contains no CREATE TABLE; '$DBNAME' is empty or holds no tables - the archive is valid but restores an empty schema"
fi

# ---------------------------------------------------------------------------
# 3) Compress, then verify the compressed form. gzip can fail on a full disk
#    halfway through and leave a truncated .gz whose name looks final.
# ---------------------------------------------------------------------------
if ! gzip -9 -c "$BACKUP_DIR/$DUMP_PART" > "$BACKUP_DIR/$DUMP_GZ"; then
    discard "$BACKUP_DIR/$DUMP_GZ"
    discard "$BACKUP_DIR/$DUMP_PART"
    die "gzip failed on $DUMP_NAME; no archive was written"
fi
if ! gzip -t "$BACKUP_DIR/$DUMP_GZ" 2>/dev/null; then
    discard "$BACKUP_DIR/$DUMP_GZ"
    discard "$BACKUP_DIR/$DUMP_PART"
    die "$DUMP_GZ failed 'gzip -t' (truncated or corrupt); the archive has been removed rather than left in place"
fi
if [ ! -s "$BACKUP_DIR/$DUMP_GZ" ]; then
    discard "$BACKUP_DIR/$DUMP_GZ"
    discard "$BACKUP_DIR/$DUMP_PART"
    die "gzip produced an empty archive for a $DUMP_BYTES byte dump"
fi
# The uncompressed .part is only removed once the .gz is proven good.
rm -f "$BACKUP_DIR/$DUMP_PART"
GZ_BYTES=$(wc -c < "$BACKUP_DIR/$DUMP_GZ" | tr -d ' ')
echo "[backup] wrote $BACKUP_DIR/$DUMP_GZ ($GZ_BYTES bytes gz, from $DUMP_BYTES bytes)"

# ---------------------------------------------------------------------------
# 4) Manifest. Informational only - a manifest failure never invalidates a
#    verified dump, but it is reported so the gap is visible at 03:00.
# ---------------------------------------------------------------------------
echo "[backup] volume manifest ..."
{
    echo "timestamp=$TS"
    echo "database=$DBNAME"
    echo "database_user=$DBUSER"
    echo "dump_bytes=$DUMP_BYTES"
    echo "dump_gz_bytes=$GZ_BYTES"
    echo "create_table_statements=$TABLES"
    echo "insert_statements=$INSERTS"
    # Volume names come from the mysql-data/datasets/models entries in docker-compose.yml.
    echo "mysql_volume=${VOLUME_PREFIX:-aidata}-mysql-data"
    echo "datasets_volume=${VOLUME_PREFIX:-aidata}-datasets"
    echo "models_volume=${VOLUME_PREFIX:-aidata}-models"
    # STORAGE_PATH / MODEL_PATH are the paths compose mounts the named volumes
    # at (docker-compose.yml fastapi.environment). The previous /app/data/models
    # path belongs to a layout the image does not use, so this always reported
    # "unknown" and looked like a missing volume.
    echo "datasets_path_in_laravel=/var/www/html/storage/app/datasets"
    echo "datasets_path_in_engine=/code/data/datasets"
    echo "models_path_in_engine=/code/data/models"
    # These are sizes of a mounted volume, not of the backup: the dump covers
    # the database only. Kept because the volume is what an operator has to
    # chase when a restore says the row is there and the file is not.
    _ds=$(docker compose exec -T laravel du -sh /var/www/html/storage/app/datasets 2>/dev/null | awk 'NR==1{print $1}') || _ds=""
    _mo=$(docker compose exec -T fastapi du -sh /code/data/models 2>/dev/null | awk 'NR==1{print $1}') || _mo=""
    echo "datasets_size=${_ds:-unknown}"
    echo "models_size=${_mo:-unknown}"
    # The dump covers the database ONLY. This line is the machine-readable
    # version of that contract: anyone parsing the manifest must not mistake a
    # valid dump for a complete backup. Files need `make snapshot` (a tar of the
    # two volumes below) on the same schedule -- docs/backup-restore.md section 5.
    echo "files_in_dump=no - datasets/models files are NOT in the dump; snapshot the volumes"
    echo "--- compose ps ---"
    docker compose ps -a --format '{{.Name}} {{.Status}}' 2>/dev/null || echo "(docker compose ps failed)"
} > "$BACKUP_DIR/$MANIFEST" || warn "could not write $MANIFEST (the dump itself is valid)"

# ---------------------------------------------------------------------------
# 5) Optional off-host copy. A failure here leaves a valid local archive, so it
#    is a warning, but it is counted so the cron log is not silently green.
# ---------------------------------------------------------------------------
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    echo "[backup] syncing to s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}/"
    if command -v aws >/dev/null 2>&1; then
        if ! aws s3 cp "$BACKUP_DIR/$DUMP_GZ" "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX:-aidata/}/$DUMP_GZ"; then
            warn "S3 upload failed; the local archive at $BACKUP_DIR/$DUMP_GZ is still valid"
        fi
    else
        warn "BACKUP_S3_BUCKET is set but the 'aws' CLI is not installed; the local archive is still valid"
    fi
fi

# ---------------------------------------------------------------------------
# 6) Retention. Only names this script produces, and .part files only once they
#    are a day old so a concurrent run's scratch file is never deleted.
# ---------------------------------------------------------------------------
find "$BACKUP_DIR" -name 'aidata_*.sql.gz' -mtime "+$RETENTION" -print -delete 2>/dev/null || true
find "$BACKUP_DIR" -name 'aidata_*.manifest.txt' -mtime "+$RETENTION" -print -delete 2>/dev/null || true
find "$BACKUP_DIR" -name 'aidata_*.sql.part' -mtime +1 -print -delete 2>/dev/null || true

# One grep-able line for the cron log.
if command -v sha256sum >/dev/null 2>&1; then
    CHECKSUM=$(sha256sum "$BACKUP_DIR/$DUMP_GZ" 2>/dev/null | awk '{print $1}')
elif command -v shasum >/dev/null 2>&1; then
    CHECKSUM=$(shasum -a 256 "$BACKUP_DIR/$DUMP_GZ" 2>/dev/null | awk '{print $1}')
else
    CHECKSUM=""
fi
echo "[backup] RESULT=ok file=$DUMP_GZ bytes=$GZ_BYTES sha256=${CHECKSUM:-unavailable} warnings=$WARN"
ls -lh "$BACKUP_DIR" 2>/dev/null | tail -5 || true

# A mysqldump only covers the database. Anything on the datasets/models volumes
# (raw uploads, model artefacts) is listed in the manifest but NOT archived;
# add a volume backup here if those must be recoverable.
exit "$EXIT_OK"
