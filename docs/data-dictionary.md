# Data Dictionary

One MySQL database (`aidata`, `utf8mb4`). Every table lives in it and is namespaced by
**table-name prefix**. Do not expect separate schemas: MySQL has none, and no code relies on them.

Each table has exactly one DDL owner: Alembic for the data tables, Laravel migrations for the
app tables. Laravel links to engine-owned ids through soft integer columns and never through
foreign keys. See `architecture.md` §3 for the rule; §7 covers the one table that is only
declared once, on purpose.

Column types below are read from `ai-engine/alembic/versions/0001_initial_data_platform.py`
(tables) and `0002_query_indexes.py` (indexes), and from
`application/database/migrations/`. Primary keys are `integer` (a `SERIAL`) everywhere in the
engine; the one exception is `dim_date`, whose natural key `date_key` is declared
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
| `fact_sales` | `transaction_date`, `customer_id` FK `dim_customer.id`, `product_id` FK `dim_product.id`, `branch_id` FK `dim_branch.id`, `quantity`, `selling_price`, `discount`, `revenue`, `import_job_id` | `ix_fact_sales_transaction_date`, `ix_fact_sales_import_job_id` |
| `fact_inventory` | `snapshot_date`, `product_id` FK `dim_product.id`, `warehouse_id` FK `dim_warehouse.id`, `stock_qty`, `import_job_id` | `ix_fact_inventory_snapshot_date`, `ix_fact_inventory_import_job_id` |
| `fact_purchases` | `purchase_date`, `supplier_id` FK `dim_supplier.id`, `product_id` FK `dim_product.id`, `quantity`, `cost`, `import_job_id` | `ix_fact_purchases_purchase_date`, `ix_fact_purchases_import_job_id` |
| `fact_expenses` | `expense_date`, `department_id` FK `dim_department.id`, `amount`, `category`, `import_job_id` | `ix_fact_expenses_expense_date`, `ix_fact_expenses_import_job_id` |

`import_job_id` on every fact is a plain integer with **no** foreign key: it is the
idempotency handle. `run_etl` deletes the rows of that job before rewriting them, so
re-running a job replaces its own output and touches nothing else. The tables are not
partitioned; `fact_sales` grows linearly and is the first candidate when it gets large.

Revision `0002_query_indexes.py` is the diff between what the models declare and what the
queries need. It adds the three missing `import_job_id` indexes above, because the identical
purge delete runs against all three tables, and it drops `ix_fact_sales_date` as a duplicate of
`ix_fact_sales_transaction_date` on the same column. That drop is one half of a fix: the
standalone `Index("ix_fact_sales_date", FactSales.transaction_date)` at the bottom of
`app/database/models.py` still declares it, so a future `create_all()` would recreate the
duplicate. `downgrade()` puts it back, on purpose, so the revision is reversible.

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
  thread. `title` is the first line of the user message, truncated to 200 characters
  (`MAX_TITLE_CHARS` in `app/ai/agent.py`).
- `ai_messages(id, created_at, updated_at, conversation_id FK ai_conversations.id, role,
  content, evidence JSONB)` — `role` is `user` or `assistant`; `evidence` holds the tool
  output the answer was grounded in. Indexed by `ix_ai_messages_conversation_id`.
- `rag_documents(id, created_at, updated_at, source, title, doc_type, meta JSONB)` — one row
  per ingested *content*, not per call. `ingest_text` looks up `(source, title)` first and
  compares the SHA-256 of the content; the same text re-ingested replaces the chunks in place
  and answers `unchanged`, different text answers `updated`.
- `rag_chunks(id, created_at, updated_at, document_id FK rag_documents.id, chunk_index,
  content, embedding, meta JSON)` — the embedding column is plain `JSON` on this stack
  (`pgvector Vector(1536)` only when the optional pgvector package is importable, which the
  MySQL deployment deliberately does not install). **There is no ANN index on it.** Retrieval in
  `app/ai/rag.py` loads up to 2000 chunks (`SCAN_LIMIT`) and scores them in Python (cosine,
  plus a +0.05 keyword boost); when the embedding call fails it falls back to a substring
  match scored 1.0 or 0.0. Plan for that O(n) scan before indexing at scale. `0002` explains
  why adding an ANN index now would be dead weight.

## 6. Alerting and quality (engine, Alembic)

- `alert_rules(id, created_at, updated_at, name VARCHAR(128), metric VARCHAR(64),
  condition VARCHAR(16), threshold FLOAT, is_active BOOLEAN)`
- `alerts(id, created_at, updated_at, rule_id FK alert_rules.id, severity VARCHAR(16),
  message TEXT, status VARCHAR(32))`
- `alert_events(id, created_at, updated_at, alert_id FK alerts.id, event_type VARCHAR(32),
  payload JSONB)` — indexed on `alert_id`

All three are declared, migrated and now written. `ai-engine/app/alerts/` is the first and
only writer: `rules.py` holds the metric registry, the operator aliases and the
open/acknowledged/resolved state machine; `service.py` holds the CRUD, the evaluation and the
Celery task; `notifiers.py` holds the optional webhook. `celery-beat` runs
`app.alerts.service.evaluate_alerts` every minute, and the read surface is the engine's own
`/api/v1/alerts/*` routes — see `api.md` §Alerting and `monitoring.md` §8.

`alerts.status` is one of `open`, `acknowledged`, `resolved`; an acknowledged alert is still
un-resolved, so it keeps suppressing a duplicate. `alert_events.event_type` is one of `fired`,
`acknowledged`, `resolved`, `notified`.

Two schema limitations shape the design and are worth knowing before you write a rule. There
is no severity column on `alert_rules`, so severity is a property of the *metric* and is copied
onto the alert when it opens. And there is no per-rule dimension, filter or evaluation window,
so the window is the constant `EVALUATION_WINDOW_DAYS` (1) for every rule. "One un-resolved
alert per rule" is an application invariant, not a database one — the service takes a per-rule
process lock to hold it.

- `data_quality_reports(id, created_at, updated_at, import_job_id FK import_jobs.id, score,
  breakdown JSONB, issues JSONB)` — one row per `GET /api/v1/imports/quality/{job_id}` call,
  written by `persist_report`. `breakdown` holds `completeness`, `uniqueness`, `validity`,
  `consistency`; `issues` is a list of `{rule, column, count, sample_rows, message}`.
  Laravel mirrors the latest run onto `datasets.quality_score` / `datasets.quality_verdict`.

## 7. `audit_logs` — Laravel only

Declared once, by
`application/database/migrations/2026_09_28_000400_create_audit_logs_table.php`:
`audit_logs(id, user_id FK users.id, actor, action VARCHAR(128) indexed, resource VARCHAR(191),
resource_id, ip, detail JSONB, created_at)` with indexes on `(resource, resource_id)` and
`created_at`.

The Alembic revision does **not** create it, and that is deliberate. `laravel` and `fastapi`
start concurrently; two `CREATE TABLE audit_logs` would race, and the loser's whole migration
run aborts on "relation already exists" — taking the engine's other 26 tables with it on a
first boot. `App\Models\AuditLog` therefore only ever talks to the Laravel definition, and
`laravel` is the only writer.

The engine keeps no audit trail of its own, so `ai_conversations` and `ai_messages` hold the
assistant transcript with no actor attached. Attribute AI work through the Laravel rows; see
`administrator.md` §6.

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
`checksum_sha256`, `import_job_id`, `(user_id, created_at)`, `created_at`, `updated_at` and
the composite `(quality_verdict, quality_checked_at)`.

- `uuid` is the public identifier. Every Laravel path is `/api/datasets/{uuid}`; `id` never
  leaves the database.
- `import_job_id` is a **soft reference** to the engine's `import_jobs.id` with no foreign
  key. Nullable: it is `null` when the upload never reached the engine.
- `status` cast to `App\Enums\DatasetStatus`: `uploaded`, `previewing`, `mapped`, `importing`,
  `committed`, `quarantined`, `failed`. The last three are terminal
  (`DatasetStatus::isTerminal()`).
- `quality_verdict` holds `pass` or `quarantine` (`App\Enums\QualityVerdict`).
- `dataset_type` is one of `config('ai_engine.dataset_types')`:
  `sales`, `inventory`, `purchases`, `expenses`, `customers`, `generic`. That allowlist is
  Laravel's only: the engine's `DATASET_TYPES` is `sales`, `inventory`, `purchases`,
  `expenses`, `customers`, `products`, and it raises `Unsupported dataset_type` for anything
  else. A `generic` dataset therefore passes Laravel's validation and fails at the engine on
  commit.
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
Actions emitted by the current code: `auth.api_login`, `auth.api_logout`, `auth.login`,
`auth.logout`, `auth.password_changed`, `dataset.uploaded`, `dataset.mapping_applied`,
`dataset.quality_checked`, `dataset.committed`, `agent.chat`, `assistant.chat`,
`model.trained`, `model.promoted`, `user.created`, `user.updated`, `user.deleted`.

### Framework tables

`sessions` and `password_reset_tokens` (both from the users migration), `cache` +
`cache_locks`, `jobs` + `job_batches` + `failed_jobs`, and `personal_access_tokens`
(Sanctum). `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are all `redis` in
`application/.env.example`, so these tables stay empty in the default configuration.
`PlatformHealth::LARAVEL_TABLES` checks 12 of them; it does not list
`password_reset_tokens`.

## 9. Data catalog (Laravel, migration `2026_09_30_010000_*`, owner A1)

- `dataset_versions(id, dataset_id FK datasets.id cascadeOnDelete, version,
  schema_snapshot JSON, schema_hash VARCHAR(64), row_count, created_by FK
  users.id nullOnDelete, notes TEXT, timestamps)` — one row per registered
  snapshot, numbered 1, 2, … per dataset. Unique `(dataset_id, version)`,
  index on `dataset_id`.
- `column_metadata(id, dataset_id FK datasets.id cascadeOnDelete, name,
  dtype, nullable BOOLEAN, is_pii BOOLEAN, sensitivity VARCHAR(32) default
  `internal` (`low|internal|confidential|restricted`), business_description
  TEXT, distinct_count, null_pct FLOAT, min_value VARCHAR(191),
  max_value VARCHAR(191), timestamps)` — the per-column registry.
  `min_value`/`max_value` are strings so one column pair covers dates,
  numbers and text. Unique `(dataset_id, name)`, index on `dataset_id`.
  Table name is the literal `column_metadata` (declared via `$table` on the
  model, not the pluralizer).
- `data_lineages(id, source_type VARCHAR(64), source_id VARCHAR(191),
  target_type VARCHAR(64), target_id VARCHAR(191), transform TEXT,
  run_reference VARCHAR(191), timestamps)` — directed lineage edges. Ids are
  strings so `import_job:42`, `dataset:<uuid>`, `table:fact_sales` and
  `model:churn:v3` share one table. Indexed on `(source_type, source_id)`,
  `(target_type, target_id)` and `run_reference`.
- `data_contracts(id, dataset_id FK datasets.id cascadeOnDelete UNIQUE, owner
  VARCHAR(191), schema_hash VARCHAR(64), freshness_sla_hours default 72,
  quality_threshold FLOAT default `config('ai_engine.quality_threshold')`,
  is_active BOOLEAN default `true`, timestamps)` — one row per dataset,
  indexed on `is_active`.

All four use `json` columns (text on sqlite, JSONB-equivalent on pgsql) and
are created in a single migration so `RefreshDatabase` picks them up
together. Catalog mutations emit `catalog.version_registered`,
`catalog.column_annotated`, `catalog.lineage_recorded` and
`catalog.contract_upserted` audit actions (see §7/`audit_logs`).

## 10. Conventions
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
