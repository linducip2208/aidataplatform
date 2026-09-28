# Data Quality

Every dataset is profiled on demand — there is no `quality` queue task. The checks live in
`ai-engine/app/ingestion/quality.py`, run inside the ETL per chunk and again on demand from
`GET /api/v1/imports/quality/{job_id}`.

## 1. Run and read

Through Laravel, with a bearer token and the dataset UUID:

```bash
curl -s -X GET "http://localhost:8080/api/datasets/$UUID/quality" -H "Authorization: Bearer $TOKEN"
```

```json
{"data": {"dataset_id": "0f0e...", "score": 0.94, "threshold": 0.75, "verdict": "pass",
          "checks": {"completeness": 1.0, "uniqueness": 0.98, "validity": 0.95, "consistency": 0.89},
          "issues": [], "profiled_at": "2026-09-28T14:02:11+00:00"}}
```

Or straight at the engine, with the service key:

```bash
curl -s "http://localhost:8001/api/v1/imports/quality/$JOB_ID" -H "X-Service-Key: $SERVICE_API_KEY"
```

```json
{"success": true, "data": {"score": 0.94, "breakdown": {"completeness": 1.0, "uniqueness": 0.98,
  "validity": 0.95, "consistency": 0.89}, "issues": [], "passed": true}}
```

Both calls re-read the stored file, recompute, and append a row to
`data_quality_reports` (`import_job_id`, `score`, `breakdown`, `issues`). Laravel also
mirrors the outcome onto `datasets.quality_score`, `datasets.quality_verdict` and
`datasets.quality_checked_at`.

## 2. The four checks

`score` is the plain arithmetic mean of the four sub-scores, each in 0.0–1.0 and rounded to
four decimals. There are no tunable weights.

| Check | Metric | How it is computed | Issue rules it raises |
|---|---|---|---|
| `completeness` | `1 − null_cells / (rows × columns)` | `df.isna()` over the whole frame | `null` per column, with up to 5 sample row indexes |
| `uniqueness` | `1 − duplicated_rows / rows` | `df.duplicated()`, exact rows only — no `pg_trgm` involved | `duplicate` |
| `validity` | `1 − invalid_cells / total_cells` | negative values in `quantity`/`qty`/`stock` columns; unparseable dates in columns whose name contains `date`, `tanggal` or `tgl`; unparseable numbers in `price`/`harga`/`revenue`/`amount`/`cost`/`total` columns | `negative_quantity`, `invalid_date`, `invalid_currency` |
| `consistency` | `1 − outliers / total_cells` | 1.5×IQR outliers on numeric columns with ≥8 non-null values, plus a reported `\|z\|>4` count (the z-score figure is reported as an issue but does not lower the score) | `outlier`, `outlier_zscore` |

An empty dataset short-circuits to `score: 0.0`, all four sub-scores `0.0`, a single `empty`
issue and `passed: false`.

Each issue is `{rule, column, count, sample_rows, message}`. `column` is `null` for
row-level findings such as `duplicate` and `empty`.

## 3. Which threshold applies

One variable name, read by both services: `QUALITY_THRESHOLD`. The engine's settings class
takes it as the canonical name for its `quality_min_score` field and accepts the old
engine-local `QUALITY_MIN_SCORE` as a deprecated fallback (which logs a deprecation warning).

The two sides still ship **different defaults**, which is the part that surprises people:

- Laravel reads `config('ai_engine.quality_threshold')` → `QUALITY_THRESHOLD`, default `0.75`.
- The engine reads `settings.quality_min_score` → same variable, default `0.6`.

So a dataset scoring `0.70` reports `threshold: 0.75` in the Laravel response and still comes
back `passed`, because the engine compared it against its own `0.6`. Laravel prefers the
engine's `passed` flag and only falls back to its own threshold when the response has no
`passed` key; the `threshold` field it reports is always its own.

To make the two agree, set `QUALITY_THRESHOLD` explicitly in the root `.env` — it is injected
into both the `laravel` and `fastapi` services, so one value covers both. A dataset scoring
exactly `0.70` with `QUALITY_THRESHOLD=0.75` will then report `quarantine`.

Verdicts are `pass` or `quarantine` (`App\Enums\QualityVerdict`). A quarantined dataset gets
`datasets.status = quarantined`, which is terminal — it will not import until something
re-checks it and the score improves.

## 4. Storage and history

`data_quality_reports(id, import_job_id, score, breakdown JSONB, issues JSONB, created_at)`.
History is per call and there is no pruning job, so the table grows by one row per quality
request. There is no `column_profiles` table; per-column statistics live inside the
`data_quality_reports.issues` payload instead.

## 5. Re-checking on a schedule

Beat has no quality re-check scheduled, so the re-check is a Laravel command. From cron, once
a day:

```bash
docker compose exec laravel php artisan sync:quality
```

| Option | Effect |
|---|---|
| `--dataset=<uuid>` | Only that dataset |
| `--days=30` | Re-check datasets whose last check is older than this (default 30) |
| `--limit=50` | Cap per run; the summary reports the backlog left over |
| `--force` | Ignore the staleness window |
| `--dry-run` | List the candidates without calling the engine |
| `--queue` | Dispatch to the Laravel queue instead of running inline |

It only considers datasets with status `committed`, orders the ones never checked first, and
exits non-zero if any re-check raised an engine error. It runs a preflight first, so a
misconfigured engine is reported once rather than once per dataset.

## 6. Tuning

- Raise the bar: `QUALITY_THRESHOLD=0.85` in the root `.env`, which Compose injects into both
  `laravel` and `fastapi`, then restart both. `QUALITY_MIN_SCORE` still works on the engine
  alone but logs a deprecation warning — do not set both.
- Fix the source before lowering a threshold. The four rules above are all mechanical; a
  `duplicate` rule on an exact-row basis usually means concatenated files or a repeated
  export, which will double-count revenue in the warehouse.
- New rules belong in `run_quality_checks`. Keep the return shape — `score`, `breakdown`,
  `issues`, `passed` — because `data-quality` readers and `DatasetIngestionService` both
  depend on it.
