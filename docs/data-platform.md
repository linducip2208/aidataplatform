# Data Platform — Catalog, Versioning, Lineage, Schema Registry, Contracts

Owner: A1 data-platform. The ingestion lifecycle (`DatasetIngestionService`:
upload → preview → mapping → quality → commit), the `datasets` row and the
`AuditLog` trail are the foundation; this document covers what is built on
top: the catalog entry, per-dataset version history, the column registry, the
lineage graph, the schema registry / drift check, data contracts with
freshness SLAs, and the health roll-up.

## 1. Catalog entry

`GET /api/catalog/datasets/{uuid}` returns the catalog card for a dataset:
identity (`id`, `name`, `dataset_type`, `status`), counts (`row_count`,
`column_count`, `versions_count`, `columns_count`), quality
(`quality_score`, `quality_verdict`), the current `schema_hash`, whether an
active contract exists, and timestamps. It is read-only and visible to
`admin, analyst, viewer`.

## 2. Versioning

`dataset_versions(dataset_id FK datasets.id cascade, version, schema_snapshot
JSON, schema_hash, row_count, created_by FK users.id nullOnDelete, notes,
timestamps)`, unique `(dataset_id, version)`.

`POST /api/catalog/datasets/{uuid}/versions` (`admin,analyst`) snapshots the
current schema (explicit `schema` payload, or the dataset's stored `columns`
when omitted) as version 1, 2, … and syncs the column registry from the
snapshot. `GET .../versions` lists newest-first. Every registration writes
`catalog.version_registered` to the audit log.

Service: `CatalogService::registerVersion($dataset, $schema, $rowCount,
$notes, $createdBy)`.

## 3. Column registry and annotation

`column_metadata(dataset_id FK cascade, name, dtype, nullable, is_pii,
sensitivity low|internal|confidential|restricted (default `internal`),
business_description, distinct_count, null_pct, min_value/max_value as
strings, timestamps)`, unique `(dataset_id, name)`.

`syncColumnMetadata()` refreshes statistics from a preview-style column
list and preserves steward annotations already stored. Stewards annotate via
`POST /api/catalog/datasets/{uuid}/columns/{column}/annotate`
(`business_description`, `sensitivity`, `is_pii`; audited as
`catalog.column_annotated`). `GET .../columns` and
`GET /api/schema-registry/datasets/{uuid}` expose the registry plus the
current schema hash.

## 4. Lineage

`data_lineages(source_type, source_id, target_type, target_id, transform,
run_reference, timestamps)`, indexed on `(source_type, source_id)`,
`(target_type, target_id)` and `run_reference`. Ids are strings so engine
integer ids (`import_job:42`) and dataset uuids share one edge table.

- `POST /api/lineage` records one edge (`admin,analyst`, idempotent,
  audited as `catalog.lineage_recorded`).
- `GET /api/lineage/{nodeType}/{nodeId}/upstream|downstream?depth=N` walks
  the graph breadth-first (depth clamped to 1–10).
- `GET /api/lineage/datasets/{uuid}/graph?depth=N` returns both directions
  for the graph view.

Model API: `DataLineage::recordTransformation(...)`,
`DataLineage::upstream/downstream/graphFor(...)`.

### Ingestion hook (1-line call site, wired by master)

`DataLineage::recordImportLineage()` records the
`import_job:<id> -> dataset:<uuid>` edge. Master adds this line inside
`DatasetIngestionService::commit()`, after the engine confirms the load:

```php
\App\Models\DataLineage::recordImportLineage($dataset, (int) $dataset->import_job_id);
```

No existing file was edited for this: the method lives on the new model and
only needs the call above.

## 5. Schema registry and drift

The registry baseline is the `column_metadata` rows for the dataset, falling
back to the dataset's stored `columns` when the registry is empty (a dataset
that was never snapshotted). `CatalogService::hashSchema()` (sha256 over the
sorted `name:dtype` map) is the single hash function used by versions,
registries and contracts.

`POST /api/schema-registry/datasets/{uuid}/drift` with a `columns` payload
returns `{added[], removed[], type_changed[{name, from, to}], has_drift}`. It
is compute-only (nothing is persisted) and rides a POST because the schema
payload travels in the request body.

## 6. Contracts and freshness

`data_contracts(dataset_id FK cascade unique, owner, schema_hash,
freshness_sla_hours default 72, quality_threshold default
config('ai_engine.quality_threshold'), is_active default true, timestamps)`.
One row per dataset; `POST /api/catalog/datasets/{uuid}/contracts` upserts
it (`admin,analyst`, audited as `catalog.contract_upserted`).

`GET .../contracts` returns the contract plus its live evaluation from
`CatalogService::checkContract()`: `meets_schema` (current hash vs promised
hash), `meets_freshness` (hours since `committed_at`, else `updated_at`,
against the SLA), `meets_quality` (score vs contract threshold), and the
combined `passed`. Freshness alone is available through
`freshnessStatus()`, which defaults to a 72 h SLA when no contract exists.

## 7. Health

`GET /api/catalog/datasets/{uuid}/health` returns
`CatalogService::datasetHealth()`: the freshness, quality, drift and
contract blocks plus a `verdict` (`healthy` | `degraded` | `critical`) and a
human-readable `issues` list. `critical` means the dataset is quarantined /
failed, or is both stale and below its quality threshold; anything else
non-clean is `degraded`.

## 8. Route registration (for master)

Reads (`role:admin,analyst,viewer`), writes (`role:admin,analyst`); envelope
`{"data": ...}` via `ApiResponse`. See the report / `CatalogTest` +
`LineageTest` `setUp()` for the exact local route copies used in tests.
