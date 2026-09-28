# Data Ingestion

Pipeline: `raw → staging → warehouse`, async on Celery `imports` queue. Entry:
Laravel `POST /api/datasets` or FastAPI `POST /api/v1/ingest` (service key).

## 1. Upload contract

- Multipart `file` + `dataset_name`; caps `MAX_UPLOAD_MB=500`, `ALLOWED_EXTENSIONS=
  csv,xlsx,xls,parquet,json,zip`; Nginx `client_max_body_size 500M`, timeouts 300 s.
- Laravel validates extension + MIME + size, stores to `storage/app/datasets`
  (volume `datasets-data`), then calls FastAPI ingest → gets `job_id` → creates
  `import_jobs` row (`queued`). Browser polls Laravel, Laravel polls FastAPI
  `GET /api/v1/jobs/{job_id}`.

## 2. Stages

| Stage | Schema | What happens |
|---|---|---|
| LAND | `raw.<dataset>` | byte-faithful copy + checksum, row count, source meta |
| CLEAN | `staging.<dataset>` | type inference, header normalize, trim, encoding fix, bad-row quarantine |
| SERVE | `warehouse.fact_*` + `dim_*` | star-schema load, surrogate keys, month partitions |

Manifest per dataset in `raw.ingest_manifest` (`dataset_id, filename, checksum,
rows, started/finished, job_id`). Failures mark job `failed` with `error` payload;
partial loads roll back per-stage transaction.

## 3. Worker behavior

Task `ingest_dataset(job_id)` (idempotent — replays same `job_id` safely):
streams file (never full-RAM), chunked inserts (10 k rows/batch), progress callbacks
(`progress` 0-100 on job row). Large Excel → converted to Parquet first. ZIP may hold
one dataset file. Concurrency: `--concurrency=4`, scale workers for parallel datasets.

## 4. After ingest

Auto-triggers quality profile (`quality` queue, see `data-quality.md`). On
`score < QUALITY_THRESHOLD (0.75)` the warehouse load is flagged `quarantined`
(`QUALITY_FAIL_ACTION=quarantine`) — visible in UI, excluded from ML/RAG until approved.

## 5. Ops

- Track: UI **Imports** page or `GET /api/v1/jobs/{job_id}`; logs `celery-worker`.
- Retry: re-`POST /ingest` (new job) or replay task by `job_id`.
- Retention: Beat purges `raw` payloads older than `BACKUP_RETENTION_DAYS`-style policy
  (configurable); warehouse facts are never auto-deleted.
