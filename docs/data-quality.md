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

Beat has no quality re-check of its own, so the re-check is a Laravel command — and it is
already scheduled: `application/routes/console.php` runs `sync:quality --limit=100 --days=30`
every day at 02:45 under `laravel-schedule` (`php artisan schedule:work`), guarded by
`withoutOverlapping(30)`. `sync:import-status --limit=100` runs at 02:15. To run it by hand:

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

## 7. Enterprise rule engine (`ai-engine/app/quality/`)

The four checks above stay the import-time profiler. The enterprise layer adds
*configurable* rules on top, without touching `app/ingestion/quality.py`:

- `rules.py` — `evaluate(df, rules)` with 15 rule types; `validate_rule()`
  rejects unknown types and bad params with a field-naming error.
- `profiles.py` — named bundles (`sales_strict`, `inventory_standard`,
  `customers_pii_aware`); `validate_profile()` enforces unique rule ids.
- `history.py` — persists runs + per-rule findings, serves history/trend.
- `pii.py` — regex detection + masking, stdlib only.
- `models.py` — `quality_rules`, `quality_runs`, `quality_findings` on the
  shared `Base` (DDL spec for master: `quality_rules(id, name UNIQUE,
  dataset_type, column NULL, rule_type, params JSON, severity, active)` /
  `quality_runs(id, dataset_ref NULL INDEX, job_id NULL, profile NULL,
  scores JSON, verdict, created_at)` / `quality_findings(id,
  run_id FK→quality_runs.id, rule_id VARCHAR NULL, column NULL, sample JSON,
  count)`).

### 7.1 Rule catalog

Each rule is `{id, column, type, params, severity error|warn}`. `column` may be
`null` only for `duplicate` (whole-row), dataset-level `completeness`, and
`schema_drift`.

| Type | Params | Fails when |
|---|---|---|
| `required` | — | null or blank string (both dtypes handled) |
| `nullable` | `max_null_ratio` (0–1, default 1.0) | null ratio above the cap |
| `unique` | — | value occurs more than once (all copies counted) |
| `duplicate` | `columns` (list or null = whole row) | extra copies beyond the first |
| `regex` | `pattern` (required, must compile) | non-null value does not fullmatch |
| `range` | `min`/`max` (≥1 required), `include_min`/`include_max` | outside bounds; unparseable non-null counts too |
| `enum` | `allowed` (required, non-empty) | non-null value not in the set |
| `datatype` | `dtype`: int\|float\|number\|string\|bool\|date\|datetime | non-null value does not coerce (nulls are `required`'s job) |
| `referential` | `allowed_values` (required, non-empty) | non-null value outside the reference set |
| `freshness` | `max_age_days` (>0), `reference` (ISO, default now UTC) | newest date older than the window; unparseable column fails shut |
| `completeness` | `min_ratio` (default 0.9); column optional | column null ratio below `min_ratio`, or dataset null-cell ratio when columnless |
| `consistency` | `max_outlier_ratio` (default 0.05) | 1.5×IQR outlier ratio above the cap (same formula as §2) |
| `validity` | `check`: no_negative\|parse_date\|parse_number | check violated (unparseable non-null counts for `no_negative`) |
| `schema_drift` | `expected_columns` (required), `allow_extra` (default false) | missing or (unless allowed) unexpected columns |
| `anomaly_ref` | `sensitivity` (0.5–6.0, default 2.5), `max_anomaly_ratio` (default 0.05) | \|z\|-anomaly ratio above the cap; zero variance passes |

Per-rule result: `{id, column, type, severity, passed, failure_count,
sample_failures≤10}`. `score` is the mean of per-rule pass rates
(`1 − failures/rows`, `1.0`/`0.0` for `schema_drift`), rounded to 4 decimals;
an empty frame scores `0.0`. `column_scores` averages each column's rules
(`__dataset__` for columnless rules). `verdict`: `fail` if any `error` rule
failed, `warn` if only `warn` rules failed, else `pass`. `evaluate()` is
deterministic — same frame + rules always gives the same bytes. Large frames
stream through `ChunkAccumulator` / `evaluate_in_chunks(df, rules,
chunksize)`, which reproduce the single-pass result exactly (global rules
retain one column; chunks keep original index labels).

### 7.2 Profiles

`GET` the bundle, don't hand-roll it: `sales_strict` (required customer/
product, non-negative quantity, parseable price, fresh date as warn,
whole-row dupes as warn, branch allowlist as warn), `inventory_standard`,
`customers_pii_aware` (required customer, email regex, segment enum, unique
customer). Custom profiles go through the same `validate_profile()` —
non-empty rules, unique ids, known types.

### 7.3 History and trend

`POST /quality/evaluate` persists a `quality_runs` row plus one
`quality_findings` row per *failed* rule. `GET /quality/history?dataset_ref=`
returns newest-first runs; with `dataset_ref` it also returns `trend`:
oldest-first `{run_id, score, verdict, created_at}` points, `direction`
(`improving`/`degrading`/`stable`, newest vs oldest, 1e-9 epsilon) and `delta`.
`GET /quality/runs/{id}` returns one run with its findings, or 404.

### 7.4 PII detection and masking

`detect(df)` scans `email`, `phone` (`+62`/`0`…), `credit_card` (13–19 digits
passing Luhn), `national_id` (16 digits) plus caller `extra_patterns`
`{kind: regex}`. All-same-digit placeholders never match. Findings are
`{column, kind, count, samples≤5}`. `mask_dataframe(df, strategy)` returns
`(masked_frame, findings)` without mutating the input; only flagged columns
are masked. Strategies: `email` (default, email-aware: `b***@example.com`,
non-emails partially masked), `partial` (keep last 4), `full` (`*` × length),
with a `per_column` override. Quarantine flow: run PII masking *before*
evaluation on customer frames so the masked frame is what gets scored and
stored — findings then reference masked samples only.

### 7.5 POST/job semantics and quarantine

Method contract, both services: GET is read-only (`/quality/rules`,
`/quality/history`, `/quality/runs/{id}` never write — pinned by tests
asserting unchanged row counts). Compute + persist is POST-only:
`POST /quality/rules` stores a rule (409 on duplicate name, 422 on unknown
type/bad params); `POST /quality/evaluate {dataset_ref?, job_id?, profile?,
rules?, rows?}` runs the engine, persists run + findings, and returns scores.
With inline `rows` it is synchronous; with `job_id` it re-reads the stored
upload (`read_full`, same helper as the import profiler). Laravel mirrors the
outcome onto `datasets.quality_score/quality_verdict/quality_checked_at` via
`QualityService::evaluateViaEngine()` — engine `fail` → `quarantine`, anything
else → `pass` — preserving terminal statuses exactly like `runQuality()`: a
`committed` row keeps `committed` while recording the verdict, so a re-check
can never strand warehouse rows. Long/periodic re-checks belong in jobs
(`sync:quality` / `RefreshQualityScoreJob` pattern), never behind GET.

Laravel surface (`/api/quality/*`, Sanctum; `QualityService` uses its own
private HTTP helper from `config/ai_engine` — `AiEngineClient` stays
master-owned): `GET rules` (filter `dataset_type/rule_type/active`),
`POST rules` (validates `rule_type` against the 15-type allowlist),
`POST evaluate` (validates, evaluates, mirrors), `GET history`, `GET runs/{id}`.
