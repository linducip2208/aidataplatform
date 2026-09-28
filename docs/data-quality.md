# Data Quality

Every ingested dataset is profiled on the `quality` queue; verdict gates ML/RAG.
Threshold: `QUALITY_THRESHOLD=0.75` (`QUALITY_FAIL_ACTION=quarantine`).

## 1. Run + read

```bash
curl -X POST http://fastapi:8000/api/v1/quality/run \
  -H "X-Service-Key: $SERVICE_API_KEY" -H 'Content-Type: application/json' \
  -d '{"dataset_id":"<uuid>"}'            # -> 202 {job_id}
curl http://fastapi:8000/api/v1/quality/<uuid> -H "X-Service-Key: $SERVICE_API_KEY"
# -> {score, threshold, verdict: pass|quarantine, checks:{...}, profiled_at}
```

UI: **Quality** page per dataset; Laravel caches latest verdict alongside dataset row.

## 2. Checks (score = weighted composite 0-1)

| Check | Metric | Weight | Fail signal |
|---|---|---|---|
| Completeness | `null_rate` per column → avg | 30% | > 20% nulls in key cols |
| Uniqueness | `dupe_rate` (exact-row + key dupes via `pg_trgm`) | 25% | > 5% dupes |
| Validity | `schema_violations` (type/range/enum) | 25% | any PK null / out-of-range |
| Consistency | cross-field rules (dates, FK to dims) | 10% | contradictions |
| Timeliness | staleness vs `expected_refresh` | 10% | stale source |

`score < 0.75` → `quarantine`: warehouse rows flagged, excluded from training/RAG,
analyst notified; admin can override in governance flow (`model-governance.md`).

## 3. Storage

Results in `warehouse.quality_reports(dataset_id, score, checks JSONB, verdict,
profiled_at)` + column stats in `warehouse.column_profiles`. History kept per run
for trend charts (Grafana panel "Quality Score Avg").

## 4. Scheduled re-checks

Celery Beat nightly re-profiles datasets with fresh source files + prunes reports
older than 90 days. Manual re-run any time via `POST /quality/run` (idempotent).

## 5. Tuning

Raise bar: `QUALITY_THRESHOLD=0.85` (.env + restart fastapi/worker). Per-dataset
override column in UI (admin). Custom rules: add to `staging` validation layer, they
surface as `schema_violations` with rule names. See `data-dictionary.md` for table defs.
