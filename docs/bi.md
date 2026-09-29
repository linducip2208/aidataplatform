# BI Enterprise — KPI Engine, Dashboards, Exports, Automated Reports

Owner: AGENT 4. Engine envelope stays `{success, data}`; Laravel stays `{data}`.

## 1. KPI engine (`ai-engine/app/analytics/kpi.py`)

Named KPIs with formula description, target, warn/crit thresholds and unit.
Built-ins (DB rows override by name):

| name | formula | unit | target | warn | crit | higher_is_better |
|---|---|---|---|---|---|---|
| revenue | sum(revenue) | IDR | 10.000.000 | 8.000.000 | 5.000.000 | true |
| orders | count(rows) | orders | 400 | 300 | 150 | true |
| units | sum(quantity) | units | 900 | 700 | 400 | true |
| aov | revenue/orders | IDR | 10.000 | 5.000 | 2.000 | true |
| growth_pct | (2H−1H)/1H·100 | % | 5 | 0 | −5 | true |
| margin_pct | net_profit/revenue·100 | % | 15 | 8 | 0 | true |
| net_profit | revenue−cogs−expenses | IDR | 1.500.000 | 800.000 | 0 | true |
| gross_profit | revenue−cogs | IDR | 3.000.000 | 2.000.000 | 500.000 | true |

- `validate_definition()` enforces `^[a-z][a-z0-9_]{1,63}$`, requires
  description/formula, checks threshold ordering per `higher_is_better`.
- `evaluate_kpi()` is `ok`/`warn`/`crit`; `breach = status != ok`. No NaN/None.
- `compute_kpi_values()` reuses `sales.sales_kpi` + `finance.finance_summary`.
- Comparison: `compare_kpis()` deltas + `delta_pct` (`prev=0 → 0.0` when
  `cur=0` else `100.0`); `compare_periods()` / `compare_by_filter()`.
- Drill-down: `drilldown(df, dimension)` for `branch|product|customer` with
  revenue/orders/units/share_pct. Unknown dimension raises `ValueError`.
- Pareto: `pareto_analysis()` adds share/cumulative/`pareto (cum ≤ 80)`.
- Profitability: `profitability(by=product|branch)` needs quantity +
  per-unit cost; without a cost basis returns
  `{supported: False, rows: [], reason}` — never invents COGS.
- Employees: `employee_analytics()` returns `supported: True` only when the
  frame names a salesperson column; otherwise explicit
  `{supported: False, reason: "unsupported: no employee/… column"}`.

## 2. History + automated reports

- `compute_and_store()` / `store_snapshot()` / `get_history()` persist to
  `kpi_snapshots`; `register_definition()` / `list_definitions()` manage
  `kpi_definitions`.
- Beat entry point: `app.analytics.kpi:snapshot_kpis(db_session, period, filters)`
  loads the sales window, evaluates thresholds and stores one row per KPI.
  Callable name for beat: **`bi.snapshot_kpis`**.

Beat wiring for master (do NOT edit `app/workers/*` here):

```python
# ai-engine/app/workers/celery_app.py — master integration only
_beat_schedule["bi-snapshot-kpis"] = {
    "task": "app.workers.tasks.bi_snapshot_kpis",  # thin wrapper calling app.analytics.kpi.snapshot_kpis
    "schedule": crontab(minute=0),  # hourly; daily also acceptable: crontab(hour=1, minute=5)
}
```

The wrapper task itself (`bi_snapshot_kpis`) is created by master in
`app/workers/tasks.py`; this agent ships only the analytics-side function.

## 3. Dashboards (`ai-engine/app/analytics/dashboards.py`)

Catalog: executive, sales, finance, customer, inventory, operations,
marketing, management. Each widget is
`{id, title, endpoint, params, chart_type}` with
`chart_type ∈ {line,bar,pie,table,stat,area,donut}` and
`endpoint ∈ {kpi,trend,rfm,abc,cohort,branches,finance,compare,drilldown,pareto,profitability}`.
`validate_widget()` / `validate_dashboard()` raise `ValueError`;
`resolve_dashboard()` executes widgets against real DataFrames.

## 4. Exports (`ai-engine/app/analytics/exports.py`)

- CSV: UTF-8-SIG bytes, `to_csv_bytes()` + chunked `iter_csv()` streaming.
- XLSX: `openpyxl` (`to_xlsx_bytes()`), round-trips via `pandas.read_excel`.
- `export_rows(rows, fmt, columns, filename)` validates `csv|xlsx`.
- **PDF boundary:** `reportlab` is NOT installed (absent from
  `requirements.txt` and the venv). `pdf_status()` reports
  `{supported: False, reason}`; `export_rows(..., "pdf")` raises `ValueError`.
  CSV/XLSX ship; PDF needs an explicit new dependency.

## 5. Engine endpoints (`POST` computes/persists, `GET` reads)

Existing 7 routes unchanged in behaviour. Additive:

| method | path | body / query |
|---|---|---|
| POST | /api/v1/analytics/kpi/definitions | KPI definition JSON → 200 + saved row (422 on invalid) |
| GET | /api/v1/analytics/kpi/definitions | list merged registry |
| POST | /api/v1/analytics/kpi/compute | `{period, filter}` → persisted snapshot rows |
| GET | /api/v1/analytics/kpi/history | `?kpi_name=&period=&limit=` → newest-first rows |
| POST | /api/v1/analytics/compare | `{current, previous}` filter blocks → `{kpis: {…delta…}}` |
| POST | /api/v1/analytics/drilldown | `{dimension, metric, filter, limit}` → grouped rows |
| POST | /api/v1/analytics/dashboards/resolve | `{dashboard, filter}` → resolved widgets |
| POST | /api/v1/analytics/export | `{format, dataset, filter, rows?, columns?, filename?}` → file (`csv`/`xlsx`; `pdf`/unknown → 422) |

`dataset` for export: `kpi|trend|rfm|abc|cohort|branches|finance|drilldown`
or explicit `rows`.

## 6. Laravel

- `Api\AnalyticsController` adds `kpiDefinitions`, `storeKpiDefinition`,
  `kpiHistory`, `compare`, `drilldown`, `dashboard`, `export` via direct
  `Http` (the `AiEngineClient` surface is pinned by
  `EngineClientContractTest`, so it is untouched). Failures throw
  `AiEngineException` → `code: ai_engine_error`, same as today.
- Web `AnalyticsController` passes `kpiDefinitions`, `kpiEvaluated`
  (target/threshold badges), `comparison`, `drilldown`, `dashboard` to
  `analytics/index.blade.php` (widget grid + CSV/XLSX export buttons +
  `application/json` payload for charts; engine-down falls back to empties).
- `ReportController` passes `snapshots` + `snapshotsAvailable` to
  `reports/index.blade.php` (automated-report table + CSV/XLSX download
  forms). Existing executive-summary variables are unchanged.

Route deltas: **Laravel `routes/` unchanged by this agent.** Master wires:

```php
Route::get('/api/analytics/kpi/definitions', [AnalyticsController::class, 'kpiDefinitions']);
Route::post('/api/analytics/kpi/definitions', [AnalyticsController::class, 'storeKpiDefinition']);
Route::get('/api/analytics/kpi/history', [AnalyticsController::class, 'kpiHistory']);
Route::post('/api/analytics/compare', [AnalyticsController::class, 'compare']);
Route::post('/api/analytics/drilldown', [AnalyticsController::class, 'drilldown']);
Route::post('/api/analytics/dashboards/resolve', [AnalyticsController::class, 'dashboard']);
Route::post('/api/analytics/export', [AnalyticsController::class, 'export']);
```

Web routes (`/analytics`, `/reports`) already exist — no new web URIs.

## 7. DDL spec (master makes the alembic revision)

```sql
CREATE TABLE kpi_definitions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL UNIQUE,
    description VARCHAR(1024) NOT NULL DEFAULT '',
    formula VARCHAR(1024) NOT NULL DEFAULT '',
    unit VARCHAR(32) NOT NULL DEFAULT '',
    target DOUBLE PRECISION NULL,
    warn_threshold DOUBLE PRECISION NULL,
    crit_threshold DOUBLE PRECISION NULL,
    higher_is_better BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ix_kpi_definitions_name ON kpi_definitions (name);

CREATE TABLE kpi_snapshots (
    id SERIAL PRIMARY KEY,
    kpi_name VARCHAR(128) NOT NULL,
    value DOUBLE PRECISION NOT NULL DEFAULT 0,
    target DOUBLE PRECISION NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'ok',
    period VARCHAR(32) NOT NULL DEFAULT '',
    filters JSON NOT NULL DEFAULT '{}',
    meta JSON NOT NULL DEFAULT '{}',
    computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_kpi_snapshots_kpi_name ON kpi_snapshots (kpi_name);
```

(SQLite tests use the same SQLAlchemy metadata via `Base.metadata.create_all`.)

## 8. Tests

- Engine: `ai-engine/tests/test_bi_enterprise.py` — 18 tests
  (registry validation, thresholds, compare math, drilldown, Pareto,
  profitability supported/unsupported, employees unsupported/supported,
  CSV/XLSX round-trips, PDF boundary, dashboard catalog + resolve, history
  persistence, `snapshot_kpis` beat entry, full API matrix).
  Run: `& "<root>\.venv\Scripts\python.exe" -m pytest tests/test_bi_enterprise.py -q -p no:cacheprovider`
  (workdir `ai-engine`). Regression `tests/test_analytics.py` (3 tests) green.
  The file uses a local `bi_warehouse` fixture (create-missing-tables +
  tolerant empty) instead of the shared `clean_warehouse`, so models other
  agents register mid-tree cannot break its setup with `no such table`.
- Laravel: `application/tests/Feature/BiEnterpriseTest.php` — 12 tests
  (proxies, validation, export download via `streamedContent()`, dashboard
  resolve, web BI sections, snapshot listing, engine-down fallbacks, auth).
  Pint `--test` clean on the four touched PHP files.

## 9. Debt / boundaries

- PDF export intentionally absent (`reportlab` not installed; no heavy dep
  added). Documented in-view ("Ekspor PDF belum tersedia") and here.
- `compare` widget inside dashboard resolve compares current filter vs an
  empty previous window (zeros) — honest baseline, not a hidden date guess.
  Use `POST /analytics/compare` with explicit `current`/`previous` for real
  period-over-period.
- `drilldown.metric` is accepted for forward-compat; revenue is the value.
- Master owns: alembic revision from §7, route includes from §6, Celery beat
  wrapper + schedule from §2.
