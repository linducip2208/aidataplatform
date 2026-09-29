# Decision Engine (Agent 7)

In-process decision support: evidence aggregation → versioned business rules
→ scored recommendations → what-if scenarios → explanations → persisted
history with an audit trail.

## Concepts

A **subject** is what the decision is about:
`{dataset_ref, branch, period, granularity, horizon}`. All fields optional;
`granularity` defaults to `daily`, `horizon` to 7 (clamped to 1..365).

A **case** (`decision_cases`) is one `recommend()` call: the normalised
subject plus `status` (`recommended`). Each fired rule becomes one
`decision_recommendations` row; every case gets a `system / recommended`
audit row at creation, and humans append `decision_audits` rows
(`approved`, `rejected`, `note`, … — free-form, max 64 chars).

## Evidence model

`collect_evidence(subject, sales_df=…, db_session=…)` calls the existing
analytics/ML functions in-process (read-only imports) and returns four
items in fixed order (`ev-0001`…`ev-0004`):

| kind | source | available when |
|---|---|---|
| `kpi` | `analytics.kpi.compute_kpi_values` + `evaluate_all` | non-empty sales frame |
| `anomaly` | `ml.anomaly.detect_anomalies` on daily revenue | ≥ 6 daily points with variance |
| `forecast` | `ml.forecasting.forecast` (subject horizon) | method ≠ `insufficient_data` |
| `ml_prediction` | `ml.recommendation.recommend` + `ml.segmentation.segment` on derived customer features | ≥ 1 recommendation or ≥ 2 customers to cluster |

Each item: `{id, kind, source, metrics, observed_at, confidence,
status, reason}`. `status` is `available` | `unavailable`; unavailable
means `metrics: {}`, `confidence: 0.0` and a non-empty `reason`.
Confidence heuristics (documented in code): KPI `min(1, n_rows/30)`,
anomaly `min(0.9, 0.4 + n/60)`, forecast `0.75` (empirical interval) /
`0.45` (floored interval), ML `clamp(n_customers/20, 0.3…1)`.

## Rules + scoring

`RULES_VERSION = "1.0.0"` (`app/decision/rules.py`). Five rules:

* `revenue_drop_rule` — second-half growth < −5%; severity `|growth|/20`.
* `margin_crit_rule` — `margin_pct` warn (0.5) / crit (0.9).
* `anomaly_spike_rule` — flagged points; severity blends rate and peak score.
* `churn_risk_rule` — `at_risk`/`dormant`/`low_value` segment pocket.
* `forecast_decline_rule` — forecast ≥ 5% below baseline.

Scoring formula: `score = round(100 * (0.6*severity + 0.4*mean_conf), 2)`,
`mean_conf` over referenced evidence (0 when none). Severe-but-uncertain
scores below moderate-but-certain by construction.

## Scenarios

`run_scenario(type, params, subject, …)`; types `price_change_pct`
(−50…100), `inventory_change_pct` (−100…200), `churn_rise_pp` (0…50).

* **price**: OLS elasticity of quantity on price (needs quantity +
  selling_price, variance, ≥ 8 rows, negative slope). Deltas + 80% CI from
  the fit residuals.
* **inventory**: scales fulfillable units at observed AOV-per-unit (needs
  revenue + quantity, ≥ 5 rows). Assumes demand absorbs the change.
* **churn**: revenue loss `pp% × baseline` (needs customer key, ≥ 5
  customers). Assumes churned customers stop buying entirely.

Unsupported data → `{supported: false, reasons: [...], assumptions: [...]}`
with **no** `deltas`/`confidence_interval` keys at all. Unknown types and
out-of-range params raise `ValueError` (422 at the API).

## API (engine, service-key auth, `{success, data}` envelope)

* `POST /api/v1/decision/recommend {subject}` → case + recommendations (persists).
* `GET /api/v1/decision/cases?limit=` → headers, newest-first (read-only).
* `GET /api/v1/decision/cases/{id}` → detail + recommendations + audits (read-only, 404).
* `POST /api/v1/decision/scenarios/run {type, params, subject}` → deltas or unsupported shape (compute-only).
* `POST /api/v1/decision/cases/{id}/audit {actor, decision, rationale}` → 201 (404).
* `GET /api/v1/decision/rules` → versioned catalogue (read-only).

Master wiring (router.py is master-owned — do NOT edit as Agent 7):

```python
from app.api.v1 import decision  # alongside the other routers
router.include_router(decision.router)
```

Laravel (`/api/decisions/*`, master-owned routes file): `DecisionService`
(private engine helper over `config/ai_engine` + `X-Service-Key`,
`AiEngineException` mapping) + `Api/DecisionController` (`index`, `show`,
`rules` read; `recommend`, `runScenario`, `audit` compute/persist,
`role:admin,analyst`).

## Audit

System writes `recommended` at case creation; humans append via the audit
endpoint (actor + decision required, rationale ≤ 2000 chars). `get_case`
returns recommendations and the full audit list; list/detail GETs never
write.

## Explicit-unsupported policy

No predicted value or business metric is ever invented. Empty/insufficient
inputs produce `unavailable` evidence and `supported: false` scenarios with
reasons — never zeros, textbook constants, or silently imputed numbers.
Every recommendation carries `limitations` stating this.

## Engine DDL spec (for master's alembic revision)

```sql
CREATE TABLE decision_cases (
  id SERIAL PRIMARY KEY,
  subject JSON NOT NULL DEFAULT '{}',
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON decision_cases (status);
CREATE TABLE decision_recommendations (
  id SERIAL PRIMARY KEY,
  case_id INTEGER NOT NULL REFERENCES decision_cases(id) ON DELETE CASCADE,
  action VARCHAR(512) NOT NULL DEFAULT '',
  impact JSON NOT NULL DEFAULT '{}',
  confidence DOUBLE PRECISION NOT NULL DEFAULT 0,
  evidence JSON NOT NULL DEFAULT '{}',
  explanation JSON NOT NULL DEFAULT '{}',
  score DOUBLE PRECISION NOT NULL DEFAULT 0,
  rule VARCHAR(128) NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON decision_recommendations (case_id);
CREATE TABLE decision_audits (
  id SERIAL PRIMARY KEY,
  case_id INTEGER NOT NULL REFERENCES decision_cases(id) ON DELETE CASCADE,
  actor VARCHAR(128) NOT NULL DEFAULT '',
  decision VARCHAR(64) NOT NULL DEFAULT '',
  rationale TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON decision_audits (case_id);
```

Laravel mirror: `2026_09_30_040000_create_decision_tables.php`
(`decision_cases` / `decision_recommendations` / `decision_audits`,
FKs with `cascadeOnDelete`, indexes on `status` / `case_id`).
