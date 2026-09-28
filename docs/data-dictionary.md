# Data Dictionary

One PostgreSQL database, one schema. Every table lives in `public` and is namespaced by
**table-name prefix**, not by a PostgreSQL schema. `infrastructure/docker/postgres/init.sql`
creates the `vector`, `pg_trgm` and `uuid-ossp` extensions and the `raw`, `staging`,
`warehouse`, `analytics`, `ml`, `ai` schemas, but no code writes to those schemas — they stay
empty. Do not qualify a table with one of them.

Each table has exactly one DDL owner: Alembic for the data tables, Laravel migrations for the
app tables. Laravel links to engine-owned ids through soft integer columns and never through
foreign keys. See `architecture.md` §3 for the rule and the one known `audit_logs` collision.

Column types below are read from `ai-engine/alembic/versions/0001_initial_data_platform.py`
and `application/database/migrations/`. Primary keys are `integer` (a `SERIAL`) everywhere in
the engine; the one exception is `dim_date`, whose natural key `date_key` is declared
`autoincrement=False` so it never becomes a sequence-backed `SERIAL`.

## 1. Ingestion (engine, Alembic)

- `raw_uploads(id, created_at, updated_at, filename, stored_path, size_bytes, mime,
  checksum_sha256, status, row_count)` — one row per accepted upload. `stored_path` is the
  engine's own copy on disk, independent of the copy Laravel keeps in
  `/var/www/html/storage/app/datasets/YYYY/MM/`.
- `import_jobs(id, created_at, updated_at, upload_id FK raw_uploads.id, dataset_type, status,
  progress, total_rows, processed_rows, error_rows, mapping JSONB, report JSONB,
  error_log JSONB)` — the integer id returned by `POST /api/v1/imports/upload` and polled at
  `GET /api/v1/imports/jobs/{job_id}`. `progress` is a 0.0–1.0 fraction, not 0–100.
  `status` runs `uploaded` → `queued` (async commit) → `done` / `done_with_errors`, or
  `failed` when validation rejects the file.
- `staging_tables(id, created_at, updated_at, import_job_id FK import_jobs.id, table_name,
  row_count, columns_meta JSONB)` — metadata about the staged chunk set for a job. Rows are
  not held here; the ETL loads the star schema directly.
- `mapping_templates(id, created_at, updated_at, name UNIQUE, dataset_type, mapping JSONB)` —
  named column mappings saved from `POST /api/v1/imports/mapping` with `save_as_template`.
  The engine also mirrors each one to a JSON file under
  `STORAGE_PATH/mapping_templates/`.

## 2. Dimensions (engine, Alembic)

| Table | Columns | Natural-key index |
|---|---|---|
| `dim_customer` | `customer_code`, `customer_name`, `segment`, `city`, `extra` JSONB | `ix_dim_customer_customer_code` UNIQUE |
| `dim_product` | `product_code`, `product_name`, `category`, `unit`, `cost_price`, `selling_price` | `ix_dim_product_product_code` UNIQUE |
| `dim_branch` | `branch_code`, `branch_name`, `city` | `ix_dim_branch_branch_code` UNIQUE |
| `dim_supplier` | `supplier_code`, `supplier_name` | `ix_dim_supplier_supplier_code` UNIQUE |
| `dim_warehouse` | `warehouse_code`, `warehouse_name` | `ix_dim_warehouse_warehouse_code` UNIQUE |
| `dim_date` | `date_key int PK` (natural key), `full_date`, `year`, `month`, `day`, `weekday` | PK |
| `dim_department` | `dept_code`, `dept_name` | `ix_dim_department_dept_code` UNIQUE |

`dim_*` rows carry no `created_at`/`updated_at`; they are upserted by natural key during the
load. `dim_date` is declared but no ETL branch populates it — the warehouse loader derives
dates inline in `_load_warehouse`.

## 3. Facts (engine, Alembic)

| Table | Columns | Indexes |
|---|---|---|
| `fact_sales` | `transaction_date`, `customer_id` FK `dim_customer.id`, `product_id` FK `dim_product.id`, `branch_id` FK `dim_branch.id`, `quantity`, `selling_price`, `discount`, `revenue`, `import_job_id` | `ix_fact_sales_transaction_date`, `ix_fact_sales_date`, `ix_fact_sales_import_job_id` |
| `fact_inventory` | `snapshot_date`, `product_id` FK `dim_product.id`, `warehouse_id` FK `dim_warehouse.id`, `stock_qty`, `import_job_id` | `ix_fact_inventory_snapshot_date` |
| `fact_purchases` | `purchase_date`, `supplier_id` FK `dim_supplier.id`, `product_id` FK `dim_product.id`, `quantity`, `cost`, `import_job_id` | `ix_fact_purchases_purchase_date` |
| `fact_expenses` | `expense_date`, `department_id` FK `dim_department.id`, `amount`, `category`, `import_job_id` | `ix_fact_expenses_expense_date` |

`import_job_id` on every fact is a plain integer with **no** foreign key: it is the
idempotency handle. `run_etl` deletes the rows of that job before rewriting them, so
re-running a job replaces its own output and touches nothing else. The tables are not
partitioned; `fact_sales` grows linearly and is the first candidate when it gets large.

## 4. ML registry (engine, Alembic)

- `ml_models(id, created_at, updated_at, name UNIQUE, model_type, status,
  production_version_id)` — one row per model name. `status` starts at `DRAFT`;
  `production_version_id` points at the version currently serving predictions.
- `model_versions(id, created_at, updated_at, model_id FK ml_models.id, version, artifact_path,
  metrics JSONB, status)` — `version` is generated as `v1`, `v2`, … per model.
  `artifact_path` is the joblib file on disk. `status` follows
  `DRAFT → TRAINING → VALIDATED → PRODUCTION → ARCHIVED` (plus `FAILED`); a freshly trained
  version is `VALIDATED`, never `STAGED`.
- `training_runs(id, created_at, updated_at, model_id FK ml_models.id, version_id FK
  model_versions.id, model_type, params JSONB, metrics JSONB, status)` — one row per
  `POST /api/v1/training/train` call.
- `prediction_runs(id, created_at, updated_at, model_id, model_type, input_summary JSONB,
  output_summary JSONB)` — declared in the schema; the current `training/predict` handler
  does not write to it.

## 5. AI assistant and RAG (engine, Alembic)

- `ai_conversations(id, created_at, updated_at, title, meta JSONB)` — one per assistant
  thread. `title` is the first message truncated to 80 characters.
- `ai_messages(id, created_at, updated_at, conversation_id FK ai_conversations.id, role,
  content, evidence JSONB)` — `role` is `user` or `assistant`; `evidence` holds the tool
  output the answer was grounded in. Indexed by `ix_ai_messages_conversation_id`.
- `rag_documents(id, created_at, updated_at, source, title, doc_type, meta JSONB)` — one row
  per `POST /api/v1/rag/ingest` call.
- `rag_chunks(id, created_at, updated_at, document_id FK rag_documents.id, chunk_index,
  content, embedding, meta JSONB)` — the embedding column is `pgvector Vector(1536)` when
  pgvector is importable, otherwise `JSONB`. **There is no HNSW index on it.** Retrieval in
  `app/ai/rag.py` loads up to 2000 chunks and scores them in Python (cosine, plus a +0.05
  keyword boost); when the embedding call fails it falls back to a substring match scored
  1.0 or 0.0. Plan for that O(n) scan before indexing at scale.

## 6. Alerting and quality (engine, Alembic)

- `alert_rules(id, created_at, updated_at, name, metric, condition, threshold, is_active)`
- `alerts(id, created_at, updated_at, rule_id FK alert_rules.id, severity, message, status)`
- `alert_events(id, created_at, updated_at, alert_id FK alerts.id, event_type, payload JSONB)`

All three are declared and migrated. No endpoint and no Celery task writes to them yet, so
alerting is schema-only in this build.

- `data_quality_reports(id, created_at, updated_at, import_job_id FK import_jobs.id, score,
  breakdown JSONB, issues JSONB)` — one row per `GET /api/v1/imports/quality/{job_id}` call,
  written by `persist_report`. `breakdown` holds `completeness`, `uniqueness`, `validity`,
  `consistency`; `issues` is a list of `{rule, column, count, sample_rows, message}`.
  Laravel mirrors the latest run onto `datasets.quality_score` / `datasets.quality_verdict`.

## 7. `audit_logs` — declared twice

The engine's Alembic revision declares `audit_logs(id, actor, action, resource, detail JSONB,
created_at)`. Laravel's `2026_09_28_000400_create_audit_logs_table.php` declares the wider
`audit_logs(id, user_id FK users.id, actor, action, resource, resource_id, ip, detail JSONB,
created_at)`, with indexes on `(resource, resource_id)` and `created_at`.

Laravel's definition is the one `App\Models\AuditLog` requires — it writes all of `user_id`,
`actor`, `action`, `resource`, `resource_id`, `ip`, `detail`. Alembic's `upgrade()` skips a
table that already exists, so if the engine migrates first, Laravel's `Schema::create` fails
on a missing `migrations` row and Laravel's columns are never added. Run
`php artisan migrate --force` (or `make migrate`, which does both) and verify with
`php artisan platform:doctor` that the table has the Laravel columns.

## 8. App tables (Laravel, migrations)

### `users`

`id, name, email UNIQUE, email_verified_at, password, remember_token, timestamps`, plus three
columns added by `2026_09_28_000100_add_role_to_users_table.php`:

- `role` — `string(32)`, default `viewer`, indexed, cast to `App\Enums\UserRole`
  (`admin|analyst|viewer`).
- `is_active` — `boolean`, default `true`. `false` makes login return `422` and
  `EnsureRole` return `403`.
- `last_login_at` — `timestamp`, nullable, set on every API login.

### `datasets`

`id, uuid UNIQUE, name, dataset_type, source_filename, disk, path, size_bytes, mime,
checksum_sha256, status, import_job_id, row_count, column_count, columns JSONB, mappings JSONB,
metadata JSONB, quality_score, quality_verdict, quality_checked_at, committed_at,
user_id FK users.id (nullOnDelete), timestamps`, with indexes on `dataset_type`, `status`,
`checksum_sha256`, `import_job_id`, and `(user_id, created_at)`.

- `uuid` is the public identifier. Every Laravel path is `/api/datasets/{uuid}`; `id` never
  leaves the database.
- `import_job_id` is a **soft reference** to the engine's `import_jobs.id` with no foreign
  key. Nullable: it is `null` when the upload never reached the engine.
- `status` cast to `App\Enums\DatasetStatus`: `uploaded`, `previewing`, `mapped`, `importing`,
  `committed`, `quarantined`, `failed`. The last three are terminal
  (`DatasetStatus::isTerminal()`).
- `quality_verdict` holds `pass` or `quarantine` (`App\Enums\QualityVerdict`).
- `dataset_type` is one of `config('ai_engine.dataset_types')`:
  `sales`, `inventory`, `purchases`, `expenses`, `customers`, `generic`.
- `metadata` accumulates the raw engine payloads under the keys `validation`, `preview`,
  `quality` and `import_job`.
- `disk` and `path` locate the copy Laravel stores; deleting the dataset row also removes
  that file.

### `chat_threads` and `chat_messages`

`chat_threads(id, title, ai_conversation_id, message_count, last_message_at,
user_id FK users.id cascadeOnDelete, timestamps)`, indexed on `ai_conversation_id` and
`(user_id, last_message_at)`. `ai_conversation_id` is a **soft reference** to the engine's
`ai_conversations.id` with no foreign key.

`chat_messages(id, chat_thread_id FK chat_threads.id cascadeOnDelete, role, content,
evidence JSONB, steps, timestamps)`, indexed on `(chat_thread_id, created_at)`.

### `audit_logs` (Laravel definition)

See §7. Written by `AuditLog::record($action, $resource, $resourceId, $detail)`, which fills
`actor` from `auth()->user()->email` (or the literal `system`) and `ip` from the current
request. Append-only: the model sets `UPDATED_AT = null`, so there is no `updated_at` column.
Actions emitted by the current code: `auth.api_login`, `auth.api_logout`, `dataset.uploaded`,
`dataset.mapping_applied`, `dataset.quality_checked`, `dataset.committed`, `agent.chat`,
`model.trained`, `model.promoted`.

### Framework tables

`sessions` and `password_reset_tokens` (both from the users migration), `cache` +
`cache_locks`, `jobs` + `job_batches` + `failed_jobs`, and `personal_access_tokens`
(Sanctum). `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are all `redis` in
`application/.env.example`, so these tables stay empty in the default configuration.
`PlatformHealth::LARAVEL_TABLES` checks 12 of them; it does not list
`password_reset_tokens`.

## 9. Conventions

- `dataset_id` and `import_job_id` thread the pipeline, but as plain integers without foreign
  keys across the service boundary.
- Money and measures are `float` / `double precision` in the engine, not `numeric`. Do not
  assume exact decimal arithmetic on `revenue`, `cost` or `amount`.
- JSONB columns hold unvalidated engine payloads; the shapes are documented in `api.md`, not
  enforced by the schema.
- `status` columns are free-form strings in the engine (`import_jobs.status`,
  `ml_models.status`, `model_versions.status`) and constrained only in Laravel, where
  `datasets.status` is cast to an enum and `users.role` to a `UserRole` enum.
- Run `VACUUM (ANALYZE)` after large bulk loads; nothing does it for you, because Beat's
  schedule covers only a placeholder nightly sync and an hourly report.
