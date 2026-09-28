# Data Dictionary

Schemas bootstrap in `infrastructure/docker/postgres/init.sql` (`vector`, `pg_trgm`).
Laravel migrations own app-meta tables; Alembic owns data tables below. `uuid` PKs
(`uuid-ossp`), timestamps `timestamptz`.

## raw (landing)

- `raw.ingest_manifest(dataset_id uuid PK, filename text, checksum sha256, rows bigint,
  source text, job_id uuid, status text, started_at, finished_at)` — one row per upload.
- `raw.<dataset_slug>_<yyyymm>(like raw.sales_202601)` — byte-faithful copy, all-text
  columns + `_line_no`; partitioned per ingest for traceability.

## staging (typed/cleaned)

- `staging.<dataset>(dataset_id, row_id bigserial, <typed cols...>, _bad_row bool,
  _errors jsonb)` — bad rows flagged, never dropped silently.
- `staging.validation_errors(dataset_id, row_id, rule text, detail jsonb)`.

## warehouse (star schema, served)

- `dim_date(date_key int PK, date, year, quarter, month, week, is_weekend)`.
- `dim_customer(customer_id uuid PK, name, segment, region, created_at)`.
- `dim_product(product_id uuid PK, sku, name, category, price numeric(12,2))`.
- `fact_sales(sale_id uuid PK, date_key int FK, customer_id uuid FK, product_id uuid FK,
  qty int, amount numeric(14,2), dataset_id uuid, quality_flag text default 'pass')`
  — partition by month (`fact_sales_yyyy_mm`) past ~100 M rows.
- `warehouse.quality_reports(report_id uuid PK, dataset_id, score numeric(3,2),
  threshold numeric(3,2) default 0.75, verdict text, checks jsonb, profiled_at)`.
- `warehouse.column_profiles(dataset_id, column_name, null_rate, distinct_count,
  min_val, max_val, sample jsonb, profiled_at)` — composite PK `(dataset_id, column_name)`.

## analytics (marts/views)

- `analytics.monthly_sales(month date PK, revenue numeric, orders bigint, avg_score numeric)`.
- `analytics.churn_features(customer_id PK, recency_days, freq_90d, monetary_90d, label bool)`.

## ml (registry)

- `ml.features(feature_id uuid PK, dataset_id, snapshot_sql text, created_at)`.
- `ml.experiments(experiment_id uuid PK, dataset_id, target text, task text, model text,
  params jsonb, metrics jsonb, seed int, code_hash text, created_at)`.
- `ml.registry(model_id uuid PK, experiment_id FK, version text unique, stage text
  (draft|staged|production|archived), artifact_uri text, created_at)`.
- `ml.approvals(approval_id uuid PK, model_id FK, actor text, from_stage, to_stage,
  note text, created_at)` — append-only.

## ai (agent/RAG)

- `ai.embeddings(dataset_id, chunk_id uuid, content text, embedding vector(384),
  meta jsonb, created_at)` — PK `(dataset_id, chunk_id)`, HNSW cosine index on embedding.
- `ai.chat_memory(session_id uuid, turn int, role text, content text, created_at)` —
  PK `(session_id, turn)`, 50-turn window enforced by app.

Conventions: `dataset_id` threads every layer; `job_id` threads async ops; money
`numeric`, counts `bigint`, scores `numeric(3,2)`; PII only in `warehouse`/`ai` (ACL'd).

## Laravel app-meta tables (public schema)

- `users(id uuid PK, name, email unique, password_hash, role text
  (admin|analyst|viewer), created_at)` — seeded demo accounts, see `README.md`.
- `datasets(id uuid PK, name, source_filename, status text, quality_score numeric,
  quality_verdict text, fastapi_dataset_id uuid, owner_id FK users, created_at)`.
- `import_jobs(id uuid PK, dataset_id FK, fastapi_job_id uuid, status text,
  progress int 0-100, error jsonb, created_at, updated_at)`.
- `audit_logs(id bigserial PK, actor_id FK users, action text, entity text,
  entity_id uuid, meta jsonb, ip text, created_at)` — append-only.

## Indexes / performance notes

- `fact_sales(dataset_id, date_key)` composite + month partitions; `dim_*` PK lookups.
- `ai.embeddings` HNSW (`vector_cosine_ops`, `m=16`, `ef_construction=64`); tune `ef_search`
  per query via `SET hnsw.ef_search = 40` for recall/latency tradeoff.
- `staging`/`raw` tables are per-dataset (wide, short-lived) — no global indexes; EXPLAIN
  before adding. Run `VACUUM (ANALYZE)` after bulk loads; Beat does this nightly.

## Migrations ownership

- `public.*` (users/datasets/jobs/audit): Laravel migrations in `application/`.
- `raw/staging/warehouse/analytics/ml/ai`: Alembic revisions in `ai-engine/`.
- `init.sql` only creates extensions + empty schemas — never tables. Both migration
  sets must be reversible (`down` tested in CI) and update this file in the same PR.
