# Testing guide — enterprise AI data/BI/ML platform

Single-tree repo, two suites, one rule: **keep existing suites green.**
No agent commits; no `.env` edits; no secrets in tests. If a test fails after
your change, the change broke a contract — fix the code, never weaken the test
(security tests in particular: a failure there is the finding).

## 1. How to run everything

### Laravel (`application/`, PHP 8.2+)

```powershell
cd application
php vendor/bin/phpunit tests/Feature/ContractRegressionTest.php
```

The suite always runs against in-memory sqlite and never touches
`database/database.sqlite`: `DB_CONNECTION`/`DB_DATABASE` are pinned in
`tests/TestCase.php`, not in `phpunit.xml`. The engine client points at
`http://fastapi.test` with a fake service key; tests fake the engine at the
HTTP boundary (`Http::fake`), so the real engine is never needed.

| Command (workdir `application/`) | What it runs |
|---|---|
| `php vendor/bin/phpunit tests/Feature/ContractRegressionTest.php` | QA cross-layer pins (13 tests) |
| `php vendor/bin/phpunit tests/Feature/OpsCommandOutputTest.php tests/Feature/EngineClientContractTest.php tests/Feature/DatasetLifecycleTest.php` | Nearest-neighbour regressions (115 tests) |
| `php vendor/bin/phpunit` | Full suite (~800 tests) |
| `php vendor/bin/pint --test tests/Fixtures tests/Feature/ContractRegressionTest.php` | Style gate (CI runs `pint --test` on everything) |

CI (`.github/workflows/laravel.yml`) runs the full suite on PHP 8.3 + 8.4
with real env vars (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
`CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`,
`SERVICE_API_KEY=ci-test-service-key`, `QUALITY_THRESHOLD=0.75`), plus
`php artisan route:list`, migrations, Pint, and the contract job
(`tests/verify-contract.sh`, `verify-env.sh`, `verify-routing.sh`).

### Engine (`ai-engine/`, Python 3.13, `.venv`)

```powershell
cd ai-engine
& "D:\project laravel\aidataplatform\.venv\Scripts\python.exe" -m pytest tests/test_contract_regression.py -q -p no:cacheprovider
```

Env is self-contained in `tests/conftest.py`: shared-cache in-memory sqlite
warehouse (`file:aidata_pytest_warehouse?mode=memory&cache=shared`), temp
storage dir, fake service key `pytest-not-a-real-service-key`. No Postgres, no
Redis, no LLM key needed (embeddings degrade to deterministic hash vectors).

| Command (workdir `ai-engine/`) | What it runs |
|---|---|
| `pytest tests/test_contract_regression.py -q -p no:cacheprovider` | QA contract pins (11 tests) |
| `pytest tests/test_schemas.py tests/test_security.py -q -p no:cacheprovider` | Nearest-neighbour regressions (68 tests) |
| `pytest -q -p no:cacheprovider` | Full suite (~400 tests, sqlite) |

CI (`.github/workflows/python.yml`) runs the full suite on Postgres
(`pgvector/pg18`) + Redis instead of sqlite. `-p no:cacheprovider` keeps
`.pytest_cache/` out of the tree; `PYTHONDONTWRITEBYTECODE=1` is set in CI.

## 2. Fixture catalog

### PHP — `application/tests/Fixtures/`

**`EnterpriseDatasets.php`** — deterministic retail fixtures. No RNG calls, no
faker, no DB writes; every number is a closed-form formula
(seasonal × trend × `md5`-hash noise namespaced by `SEED = 20260930`), so
output is byte-identical on every PHP version and OS. PII-free (synthetic shop
names, ledger codes; no emails/phones/persons).

| Method | Content |
|---|---|
| `branches()` | 12 branches `BR-01..BR-12` (city, region, flagship/regular tier) |
| `chartOfAccounts()` | 11 ledger categories (`5101` sewa … `5111` renovasi) with typical monthly IDR |
| `salesMonthly()` / `salesCsv()` / `salesSummary()` | 144 rows (12×12, year 2025). Dec peak 1.35, Feb trough 0.88, +0.8%/mo trend, ±3% noise. Golden: revenue `24891605715`, transactions `250355` |
| `products()` / `inventorySnapshots()` / `inventoryCsv()` | 288 rows (24 products × 12 branches). 31 dead-stock rows (`days_of_stock > 120`) |
| `customers()` / `customersCsv()` / `churnedCustomers()` | 150 B2B customers. Churn signal `recency > 180 && frequency ≤ 3` → exactly 3 rows |
| `expenses()` / `expensesCsv()` / `anomalousExpenses()` | 1584 rows (12×11×12). Exactly 3 anomalies (`ANOMALIES`: BR-03/06 renovasi ×3.2, BR-07/11 listrik ×2.4, BR-11/02 transportasi ×2.9) |
| `datasetAttributes($type, $overrides)` | Mass-assignment-safe `Dataset` attributes (keys ⊆ `Dataset::$fillable`, which covers `import_job_id`) for `factory()->create()` / `create()` / seeders |

**`EngineFakes.php` + `EngineFakeState.php`** — reusable `Http::fake` builder
unifying the closures in `OpsCommandOutputTest` / `WebWorkflowTest` /
`DatasetLifecycleTest` / `AuditTrailTest` / `QueryCountTest`.

```php
$this->engine = EngineFakes::install();   // setUp(): registers ONE closure
$this->engine->qualityScore = 0.42;       // per test: read at request time
$this->engine->failingJobs = [42 => 500]; // per-job failure injection
$this->engine->qualityScores = [43 => 0.21];
$this->engine->omitPassedFlag = true;     // exercise the threshold fallback
EngineFakes::callsTo('/imports/quality/'); // incl. retried attempts
```

Contract notes: business routes answer `{success: true, data: …}`;
`/health` and `/readiness` answer **bare** bodies (the client `decode()`s
them, never `unwrap()`s); `/models` honours `modelsStatus` for key-rejection
tests. `qualityReport($score, $passed)` follows
`config('ai_engine.quality_threshold')` when `$passed` is null — the same
fallback `runQuality()` applies.

### Python — `ai-engine/tests/fixtures/`

**`enterprise.py`** — the same distributions as the PHP fixtures, as pandas
frames: `make_sales_frame()` (144), `make_inventory_frame()` (288),
`make_customers_frame()` (150), `make_expenses_frame()` (1584, 3 anomalies).
Golden `EXPECTED_*` constants (revenue `24891605715`, dead-stock `31`,
churned `3`, …) are recomputed by `assert_enterprise_kpis()`, which also pins
the math behind them (December is the peak month; anomaly keys match
`ANOMALIES` exactly; churned rows satisfy the signal). `service_headers()` and
`make_client()` are reusable outside `conftest` for suites that need their own
client.

## 3. Fake-engine patterns (read before writing a test)

1. **One closure, state-driven.** `Http` stubs resolve
   first-registered-wins, so a second `Http::fake()` in a test can never
   override `setUp()`. Put knobs on state (`EngineFakeState`) read at request
   time — never re-fake.
2. **Envelope vs bare.** Fake the envelope for business routes, bare bodies
   for `/health`/`/readiness`. Faking the envelope there makes the client
   misread a healthy engine.
3. **Count attempts, not calls.** `EngineFakes::callsTo()` counts retried
   attempts too: a 5xx-backed call is attempted exactly twice
   (`retry(2, 250)`), a 422-backed call exactly once. Assert both sides.
4. **Multipart is raw.** `ClientRequest::data()` only decodes url-encoded/JSON
   bodies — assert `dataset_type`/filename against `$request->body()`.
5. **Python: unique RAG keys.** RAG tests use a uuid-suffixed `source` per
   test instead of emptying tables (see §6, known issue #1).

## 4. What CI runs

- `laravel.yml`: full PHPUnit on 8.3+8.4 (sqlite `:memory:`), Pint gate,
  `route:list` smoke, migrations, `verify-contract.sh` (docs/api.md ↔ routes),
  `verify-env.sh`, `verify-routing.sh` (nginx). Path-filtered to
  `application/**` + engine API + infra.
- `python.yml`: full pytest on Postgres+Redis (pgvector/pg18, `CREATE
  EXTENSION vector/pg_trgm`), Python 3.13. Path-filtered to `ai-engine/**`.

## 5. Flaky-test policy

- Determinism first: hash-noise / fixed seeds only; no wall-clock, no
  `sleep()`, no faker without a seed, no test-order dependence. A test that
  fails only on rerun is quarantined (skipped with a tracking note) only after
  the nondeterminism source is identified — never "fixed" with a retry loop.
- Time bounds are smoke bounds (`< 30s` PHP list render, `< 60s` Python chunk
  read, `> 1 MB/s` throughput floor): an order of magnitude above healthy, so
  they catch regressions, not busy CI runners.
- Concurrent-edit hazard (observed 2026-09-29): other agents edit
  `app/ai/**`, `app/api/v1/rag.py`, `app/ml/**` in the same tree. A test that
  fails with a syntax/import error in a file you do not own is almost certainly
  a mid-edit snapshot — wait, re-run, and check `git diff` on that file before
  concluding anything. Never "fix" another agent's file.

## 6. Coverage gaps observed (for the owning agents)

1. **Subset-run fragility (test infra, fail-closed hole in the harness).**
   `tests/conftest.py::_reset_schema` deletes every table on shared `Base`
   metadata, but `warehouse` runs `create_all` after importing only
   `app.database.models`. `app/api/v1/models.py:15` imports
   `app.ml.registry` (which defines `ml_model_events`) at module top when the
   app is created — i.e. *after* `create_all` in any run that does not collect
   a module importing the registry. Repro: run any single test file that uses
   the `clean_warehouse`/`db_session` fixture without `test_ml_enterprise.py`
   collected → `sqlite3.OperationalError: no such table: ml_model_events`
   (`DELETE FROM ml_model_events`). Full-suite runs are green only because
   collection imports the registry first. Owner: whoever owns `conftest.py`
   (likely master/A9) — e.g. `create_all(checkfirst=True)` after app creation,
   or a registry of model modules imported up front.
2. **Two error-envelope shapes (engine).** `app/api/v1/imports.py:66,106,108,
   125,127,151` return hand-rolled `{"success": false, "error": {"message"}}`
   while `app/core/errors.py:172` builds the ordered 9-key `ErrorDetail`
   (`module/operation/error_type/code/message/technical/request_id/resolution/
   details`). The Laravel client reads both, but they are different contracts;
   the ordered shape is only reachable via `build_error_response` routes (e.g.
   `rag.py:26-48` oversized-ingest 413). Owner: engine team — converge or
   document.
3. **RAG citation shape in flux (engine, concurrent).**
   `app/ai/rag.py::query` docstring promises citations as
   `{content, score, document_id, chunk_index}`; the working tree already
   returns reshaped citations (`chunk_id/source/char offsets`, no `content`)
   plus new top-level keys (`confidence/evidence/limitations`). Laravel's
   `Api\RagController.php:32` forwards citations opaquely, so nothing breaks
   today, but any Blade view that reads `citation.content` will. Owner: A6 —
   update `docs/rag.md` + `docs/api.md` with the final shape.
4. **Quality-terminal inconsistency (docs vs code, Laravel).**
   `DatasetLifecycleTest.php:292` already pins it: `docs/data-quality.md §3`
   says a quarantined dataset is re-checked until the score improves, but
   `DatasetIngestionService.php:178` keeps terminal states on re-check and the
   sweeps only look at non-committed rows — the only way out is a re-upload.
   Owner: docs owner or A3 — either implement re-check-release or correct §3.
5. **`Dataset::$fillable` is deliberately wide (`application/app/Models/
   Dataset.php:42-64`).** Covers server-owned columns incl. `import_job_id`;
   safe today only because no client-controlled key reaches a guarded write
   (upload path builds a literal; controllers pass `file/name/dataset_type/
   mappings.*`). Tightening needs service + seeders + factories converted
   together. Not a live hole; a latent footgun. Owner: master/security.
6. **Perf smoke covers CSV only (engine).** `app/ingestion/reader.py`
   xlsx/xls/json/parquet/xml/zip paths have no throughput pin — only the CSV
   path is benchmarked (`test_chunk_reader_throughput_floor`). Owner: A2.
7. **New agent surfaces unpinned by design (not regressions).**
   `ai-engine/app/decision/**`, `app/quality/**`, `app/analytics/**` rewrites
   and the new Laravel `Catalog/Quality/Decision` controllers belong to their
   agents' suites; this QA layer pins only the pre-existing surface.

## 7. Debt / follow-ups for QA

- Re-run the golden constants if `SEED` or any formula changes (PHP
  `EnterpriseDatasets::salesSummary()`, Python `assert_enterprise_kpis()` fail
  loudly — that is the point).
- Consider a nightly full-suite run on sqlite *and* Postgres for the engine:
  gap #1 only bites subset runs today, but any new lazily-registered model
  reopens it.
- `docs/api.md ↔ routes` drift is covered by `verify-contract.sh` in CI, not
  by PHPUnit — keep it that way (fast), but remember it exists when a route
  test "passes" yet the docs lie.
