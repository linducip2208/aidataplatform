# Backup & Restore

Covers the PostgreSQL database, which holds every table both services own, plus the volumes
that a dump cannot capture. Test restores quarterly — a backup never restored is assumed
broken. See `deployment.md` for the production cron and `security.md` for at-rest encryption.

## 1. What a backup actually covers

| Data | In the `pg_dump`? | Notes |
|---|---|---|
| Engine tables (`raw_uploads`, `import_jobs`, `fact_*`, `dim_*`, `ml_*`, `rag_*`, `ai_*`, `data_quality_reports`) | yes | `alembic`/`public` schema |
| Laravel tables (`users`, `datasets`, `chat_*`, `audit_logs`, `sessions`, `cache`, `jobs`, `personal_access_tokens`) | yes | same database |
| Uploaded files Laravel stored | **no** | `datasets-data` / `laravel-storage` volume |
| The engine's own copy of each upload (`raw_uploads.stored_path`) | **no** | under the engine's `STORAGE_PATH` |
| ML artifacts (`.joblib`) | **no** | under the engine's `MODEL_PATH` |

`datasets.checksum_sha256` is in the dump, so a restore can be checked against the original
files — but the files themselves need a volume snapshot (§5). The engine's
`model_versions.artifact_path` points at files that also need snapshotting; without them the
registry rows survive but every model becomes unloadable and churn inference answers
`no production churn model`.

## 2. Backup

```bash
make backup
# or: bash infrastructure/scripts/backup.sh
```

Output, in `BACKUP_DIR` (default `./backups`):

```
backups/aidata_20260928_020000.sql.gz
backups/aidata_20260928_020000.manifest.txt
```

What the script does:

1. Sources the root `.env` for `POSTGRES_USER` / `POSTGRES_DB` (only those two).
2. `docker compose exec -T postgres pg_dump -U <user> -d <db> --clean --if-exists`, piped
   through `gzip`. The dump includes the `CREATE EXTENSION` statements and the empty
   `raw`/`staging`/`warehouse`/`analytics`/`ml`/`ai` schemas, so a restore into a fresh
   database reproduces the full extension and schema state.
3. Writes a manifest with the timestamp, `du -sh` of the Laravel datasets directory and the
   engine models directory, and the state of every container.
4. If `BACKUP_S3_BUCKET` is set, `aws s3 cp` the dump; a failure warns and continues, leaving
   the local copy.
5. Prunes local dumps and manifests older than `BACKUP_RETENTION_DAYS` (default 14).

`deploy-ubuntu24.sh` installs the cron entry: `0 2 * * *` as the `aidata` user, writing to
`$APP_DIR/backups/cron.log`, rotated by logrotate.

Verify a backup immediately — the script does not:

```bash
ls -lh backups/
gzip -t backups/aidata_20260928_020000.sql.gz && echo "gzip ok"
zcat backups/aidata_20260928_020000.sql.gz | grep -c 'CREATE TABLE'
```

## 3. Restore

```bash
make restore FILE=backups/aidata_20260928_020000.sql.gz
# or: bash infrastructure/scripts/restore.sh backups/aidata_20260928_020000.sql.gz
```

The script resolves the path, checks it exists, sources `.env` for the database name, asks for
confirmation (`y`), then pipes `gunzip -c` into
`docker compose exec -T postgres psql -U <user> -d <db> -v ON_ERROR_STOP=1` in the live
container. It restores over the running database — the `pg_dump --clean --if-exists` output
drops and recreates the tables it knows about, so unrelated objects in the same database are
left alone. `ON_ERROR_STOP=1` aborts on the first error, which means a partial restore is
possible: check the psql output, not just the exit status.

Afterwards, always re-run the migrations. A dump taken before a schema change will not have
the new columns:

```bash
make migrate                              # artisan migrate --force + alembic upgrade head
bash infrastructure/scripts/healthcheck.sh
docker compose exec laravel php artisan platform:doctor
bash tests/run.sh
```

Restore order does not matter, and there is no collision to reason about: `audit_logs` is
declared by the Laravel migration only — the Alembic revision does not create it
(`data-dictionary.md` §7) — and Alembic guards every `CREATE TABLE` with a `has_table` check
anyway. Verify the columns rather than assume them:

```sql
SELECT column_name FROM information_schema.columns
WHERE table_name = 'audit_logs' ORDER BY ordinal_position;
```

`user_id`, `resource_id` and `ip` must be present, or `App\Models\AuditLog` writes will fail.

## 4. Disaster scenarios

| Loss | Recovery |
|---|---|
| One table dropped or corrupted | Restore the dump into a scratch database, then copy the table out: `pg_dump -t <table>` and replay it into production |
| Whole database lost | `docker compose up -d postgres` → `restore.sh` with the newest dump → `make migrate` → `healthcheck.sh` → `tests/run.sh` |
| `datasets-data` volume lost | The rows and checksums are in the dump, the files are not. Re-upload the sources: the ETL is idempotent per `import_job_id`, so re-committing an existing job id replaces its own fact rows instead of duplicating them |
| Engine upload copies lost | Same as above; `raw_uploads.stored_path` will point at files that no longer exist, so re-upload rather than re-commit |
| ML artifacts lost | Registry rows survive in the dump, artifacts do not. Retrain. Under Compose the artefacts are on the `models-cache` volume (`MODEL_PATH=/code/data/models`), so a volume loss is the only realistic cause; outside Compose `MODEL_PATH` is `./models` beside the code and is lost on every rebuild |
| Password changed and `pgdata` still has the old one | The first-boot password is baked into the volume. `docker compose exec postgres psql -c "ALTER USER aidata PASSWORD 'new';"` and update `.env`. `docker compose down -v` does fix it and destroys all data — never in production |

## 5. Volume snapshots

`datasets-data` and `models-cache` hold the files a dump cannot. Snapshot them on the same
schedule as the database, and before any upgrade:

```bash
docker run --rm --volumes-from aidata-laravel -v $(pwd)/backups:/bk alpine \
  tar czf /bk/files_$(date +%Y%m%d).tgz /var/www/html/storage/app/datasets

docker run --rm --volumes-from aidata-fastapi -v $(pwd)/backups:/bk alpine \
  tar czf /bk/models_$(date +%Y%m%d).tgz /code/data/models
```

Restore by reversing the tar into a fresh volume before `up -d`. Prune on the same
`BACKUP_RETENTION_DAYS` schedule as the dumps, and keep the file snapshots off-host alongside
the database dump — two copies on the same disk are one failure domain.

Volume paths are worth confirming before you trust a snapshot. The Laravel datasets volume is
mounted at `/var/www/html/storage/app/datasets`, which is where the upload service writes, and
`laravel` owns the upload. The engine volumes are mounted at `/code/data/datasets`
(`datasets-data`) and `/code/data/models` (`models-cache`), and Compose sets `STORAGE_PATH` and
`MODEL_PATH` to exactly those two paths, so the engine's uploads and artefacts survive a
rebuild. The engine's own defaults are `./datasets` and `./models` relative to its `/code`
working directory, which are *not* those paths — set both variables explicitly if you run the
engine outside Compose, or its files will live in the container's ephemeral layer where no
snapshot will ever see them.

## 6. Off-host copy

```bash
# root .env
BACKUP_S3_BUCKET=aidata-backups
BACKUP_S3_PREFIX=prod/
AWS_ACCESS_KEY_ID=...  AWS_SECRET_ACCESS_KEY=...  AWS_DEFAULT_REGION=ap-southeast-1
```

The script uploads each dump to
`s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/` after writing it locally, and warns rather than
fails if the upload does not work. Enable bucket versioning and SSE-KMS, add a lifecycle rule
to Glacier after 90 days, and confirm with `aws s3 ls s3://aidata-backups/prod/`. The volume
tarballs from §5 are not uploaded by the script — copy them yourself.

## 7. Restore drill (quarterly)

1. `make backup` on production. Copy the dump to a staging host.
2. Bring up a scratch stack there (or a second database in the existing one) and
   `restore.sh` the dump.
3. `make migrate`, then `platform:doctor` — the 27 engine tables and 12 Laravel tables must all
   be present, and `audit_logs` must have the Laravel columns.
4. `bash infrastructure/scripts/healthcheck.sh` and `bash tests/run.sh`, and log in as
   `admin@example.com` to confirm the UI renders.
5. Spot-check the data:

```sql
SELECT count(*) FROM users;         -- 3 demo accounts
SELECT count(*) FROM datasets;
SELECT count(*) FROM import_jobs;
SELECT status, count(*) FROM datasets GROUP BY status;
SELECT count(*) FROM fact_sales;
```

6. Record the result and the duration in the admin notes. Also verify the dataset files are
   present in the restored volumes; a green `platform:doctor` proves the database restored, not
   that the files did.
