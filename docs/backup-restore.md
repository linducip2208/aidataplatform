# Backup & Restore

Covers Postgres (all schemas) + pointers for file volumes. Test restores quarterly.

## 1. Backup

```bash
make backup
# or: bash infrastructure/scripts/backup.sh
# -> backups/aidata_YYYYmmdd_HHMMSS.sql.gz + .manifest.txt
```

What it does: `pg_dump --clean --if-exists` via `postgres` container → gzip;
manifest records `datasets-data` + `models-cache` sizes and container states.
Retention: `BACKUP_RETENTION_DAYS=14` auto-prunes. Optional S3: set `BACKUP_S3_BUCKET`
(+ `BACKUP_S3_PREFIX`, AWS keys) — script `aws s3 cp`s each dump. Cron in prod
(02:00 daily, installed by `deploy-ubuntu24.sh`).

Volumes note: dumps cover the DB; `datasets-data` (original uploads) and
`models-cache` (ML artifacts) are file data — snapshot them at host level
(`docker run --volumes-from ... tar czf`) or rely on S3 copies of uploads.

## 2. Restore

```bash
make restore FILE=backups/aidata_20260101_020000.sql.gz
# or: bash infrastructure/scripts/restore.sh <file>
```

Prompts for confirmation (overwrites `POSTGRES_DB`), pipes gunzip → `psql
-v ON_ERROR_STOP=1`, then reminds to run `healthcheck.sh` + `migrate --force`
(restore may predate latest migration).

## 3. Disaster scenarios

| Loss | Recovery |
|---|---|
| Accidental table drop | restore dump to staging DB, `pg_dump -t <table>` → replay |
| Full DB loss | `docker compose up -d postgres` → restore latest dump → migrate → healthcheck |
| Uploads volume loss | re-upload sources (manifest lists sizes); warehouse rebuilds via re-ingest |
| Model artifacts loss | retrain from `ml.experiments` params (registry rows survive in DB dump) |

## 4. Checks

- After backup: `ls -lh backups/` non-empty + `gzip -t` passes.
- After restore: `healthcheck.sh` all OK; spot-check row counts
  (`SELECT count(*) FROM warehouse.fact_sales` etc.); run `tests/run.sh`.
- Keep one off-host copy (S3 or scp) — a backup on the same disk is not a backup.

## 5. S3 setup (optional off-host)

```bash
# .env
BACKUP_S3_BUCKET=aidata-backups
BACKUP_S3_PREFIX=prod/
AWS_ACCESS_KEY_ID=...  AWS_SECRET_ACCESS_KEY=...  AWS_DEFAULT_REGION=ap-southeast-1
```

The script `aws s3 cp`s each dump after local write; failures warn but don't fail the
job (local copy remains). Enable bucket versioning + SSE-S3/SSE-KMS; lifecycle rule to
Glacier after 90 days. Verify with `aws s3 ls s3://aidata-backups/prod/`.

## 6. Volume snapshots (datasets/models)

DB dumps don't include `datasets-data` / `models-cache` file contents. Monthly (or
pre-upgrade), snapshot them at host level:

```bash
docker run --rm --volumes-from aidata-laravel -v $(pwd)/backups:/bk alpine \
  tar czf /bk/files_$(date +%Y%m%d).tgz /var/www/html/storage/app/datasets
docker run --rm --volumes-from aidata-fastapi -v $(pwd)/backups:/bk alpine \
  tar czf /bk/models_$(date +%Y%m%d).tgz /app/data/models
```

Restore by reversing the tar into a fresh volume before `up`. Record snapshot names in
the change log; prune with the same 14-day (or longer) retention policy.

## 7. Restore drill (quarterly, required)

1. `make backup` on prod. 2. Copy dump to staging host. 3. `restore.sh` there.
4. `migrate --force` + `healthcheck.sh` + `tests/run.sh`. 5. Log result (pass/fail +
   duration) in admin notes. A backup never restored is assumed broken.
