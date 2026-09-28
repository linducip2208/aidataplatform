# Data Ingestion

Pipeline: upload → validate → preview → map → profile → commit (ETL into the star schema).
Entry points are the Laravel UI/API (`POST /api/datasets` plus the four workflow steps) or the
engine directly (`POST /api/v1/imports/upload` and friends, service key). Table definitions are
in `data-dictionary.md`.

## 1. Upload contract

Laravel validates before anything reaches the engine:

| Rule | Source | Default |
|---|---|---|
| Max size | `max:MAX_UPLOAD_MB` on the `file` field, from `config('ai_engine.max_upload_mb')` | `500` |
| Extension allowlist | `extensions:` from `config('ai_engine.allowed_extensions')` | `csv, xlsx, xls, json, parquet, zip, txt` |
| `dataset_type` | `in:` from `config('ai_engine.dataset_types')` | `sales, inventory, purchases, expenses, customers, generic` |
| `name` | optional, max 150; defaults to the filename without extension | — |

A file that is too large or the wrong type gets `422` with an `errors` object keyed by
`file`; it is not a `413`. Nginx (`client_max_body_size 500M`, 300 s send/read timeouts) and
PHP (`upload_max_filesize=500M`, `post_max_size=550M` from
`infrastructure/docker/laravel.Dockerfile`) enforce the same ceiling one layer earlier, so
raise all three together.

`POST /api/datasets` then does two things: it stores the file under
`/var/www/html/storage/app/datasets/YYYY/MM/` on the `FILESYSTEM_DISK` volume, and it forwards
the file to
the engine as multipart `POST /api/v1/imports/upload` with `dataset_type` and an
`AI_ENGINE_UPLOAD_TIMEOUT` (300 s) budget. The engine writes its own `raw_uploads` and
`import_jobs` rows and returns:

```json
{"success": true, "data": {"upload_id": 12, "import_job_id": 34, "validation": {"ok": true, "meta": {...}}, "stored_path": "..."}}
```

`data.import_job_id` is an **integer**. Laravel stores it in `datasets.import_job_id` and
answers `201` with the dataset. If the file fails engine validation, `validation.ok` is
`false` and the dataset is created with status `failed`.

## 2. The four workflow steps

| UI / API | Engine call | What it does |
|---|---|---|
| `POST /datasets/{uuid}/preview` | `GET /api/v1/imports/preview/{job_id}` | Profiles the file: `columns[]` with `name`/`dtype`/`missing`/`missing_pct`/`unique`/`sample`, up to 20 `sample_rows`, `duplicate_count`, `warnings[]`, `errors[]`. Reads at most `min(50000, CHUNK_ROWS × 3)` rows, so the profiling of a large file is a sample; for CSV the reported `row_count` is a full-file line count instead. Status moves to `previewing` and back to `uploaded`. |
| `POST /datasets/{uuid}/mapping` | `POST /api/v1/imports/mapping/suggest`, then `POST /api/v1/imports/mapping` | With no `mappings` in the body the engine suggests a target per column; with `mappings` it stores them on the job. `save_as_template` also writes a `mapping_templates` row. Status becomes `mapped`. |
| `POST /datasets/{uuid}/quality` | `GET /api/v1/imports/quality/{job_id}` | Runs the four checks and writes a `data_quality_reports` row. See `data-quality.md`. |
| `POST /datasets/{uuid}/commit` | `POST /api/v1/imports/commit` | Runs the ETL. `run_async: true` (the default) enqueues it and returns `{"import_job_id": 34, "status": "queued"}`; Laravel answers `202`. |

The suggester is `ai-engine/app/ingestion/mapper.py`: an exact alias table covering Indonesian
and English column names, then `difflib` fuzzy matching with a 0.78 cutoff, then a
semantic fallback for date-like and quantity-like names. Canonical targets per
`dataset_type` are `transaction_date`, `customer_code`, `customer_name`, `product_code`,
`product_name`, `branch_name`, `quantity`, `selling_price`, `discount`, `revenue` for sales.
A suggestion is a proposal: review it, then post the mappings you want.

## 3. The ETL

`run_etl` (`ai-engine/app/ingestion/etl.py`) reads the file in `chunksize=20000` chunks. Per
chunk it applies the mapping, strips column names, trims strings and turns `""`, `"nan"` and
`"None"` into nulls, coerces date-like and numeric-like columns, derives `revenue` from
`quantity × selling_price − discount` when `revenue` is all zeros, and runs the quality
checks. It then upserts the star schema: natural-key dimension rows are created on demand and
`fact_*` rows are inserted for the job.

Idempotency is per `import_job_id`: before loading, the loader deletes the rows that job
already wrote, so replaying a job replaces its own output and touches nothing else.

The engine's `DATASET_TYPES` is `sales`, `inventory`, `purchases`, `expenses`, `customers`
and `products`. The first four have a fact loader; `customers` upserts `dim_customer` only
and `products` upserts `dim_product` only, so neither writes a fact row. Anything else
raises `Unsupported dataset_type` and fails the job. Laravel's allowlist in
`config('ai_engine.dataset_types')` is `sales`, `inventory`, `purchases`, `expenses`,
`customers`, `generic`, so a `generic` dataset passes validation and fails here on commit,
and a `products` dataset is rejected by Laravel before it ever gets this far. Keep the two
lists in step.

Canonical target columns per type, which the mapper suggests against, are listed in
`ai-engine/app/ingestion/mapper.py::CANONICAL_FIELDS`; `generic` has no entry there.

## 4. Job status

`GET /api/import-jobs/{importJobId}` (bearer) proxies `GET /api/v1/imports/jobs/{job_id}` and
returns `job_id`, `type: "import"`, `status`, `progress`, `total_rows`, `processed_rows`,
`error_rows`, `report`, `error`.

| Engine `import_jobs.status` | Meaning |
|---|---|
| `uploaded` | Created by the upload; not committed yet |
| `queued` | `run_async` accepted the job, the Celery worker has not finished |
| `done` | ETL finished, no chunk failed |
| `done_with_errors` | ETL finished, at least one chunk failed (see `error_log`) |
| `failed` | Validation rejected the file, or the worker gave up after retries |

`progress` is a 0.0–1.0 fraction written by the task's progress callback, not 0–100.
`DatasetIngestionService::syncStatus` treats `done` as committed, and `failed`/`error` as
failed; anything else leaves the dataset `importing`. There is no webhook — poll the endpoint
or run `php artisan sync:import-status`.

## 5. Ops

- Watch progress in the UI **Imports** page or with
  `GET /api/import-jobs/{importJobId}`. Worker logs: `docker compose logs -f celery-worker`.
- A job stuck in `queued` means nothing is consuming the `imports` queue.
  `celery_app.py` routes `app.workers.tasks.import_file` there, and the worker subscribes to
  `CELERY_QUEUES` (Compose default `default,imports,quality,ml,agent,rag`). If you added a
  custom queue list, confirm `imports` is still in it.
- Re-running a commit for the same `import_job_id` is safe and replaces that job's rows.
  Re-uploading the file creates a **new** job id and does not clean up the previous one, so
  duplicate facts appear if you upload the same file twice; delete and re-create the dataset
  instead.
- Retention: nothing purges `raw_uploads` rows, their stored files, old fact rows or
  `data_quality_reports` in this build. `celery-beat` runs `scheduled_data_sync` (a
  placeholder), an hourly AI report and the per-minute alert evaluation, none of which delete
  anything, so plan the cleanup yourself. `php artisan sync:import-status` reconciles existing
  rows rather than pruning them.
