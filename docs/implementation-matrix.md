# Implementation Matrix — Enterprise AI Data/BI/ML Platform

Single-tree execution (no per-agent branches: avoids unmergeable divergence).
Each agent owns its files EXCLUSIVELY. Shared files are read-only for agents;
route includes, alembic revisions and cross-cutting wiring are done by master
at integration.

## Global contracts

- Laravel API envelope: `{"data": ...}` (`App\Support\ApiResponse`). Errors via
  `AiEngineException` rendering. Auth: Sanctum bearer; roles via `EnsureRole`.
- Engine envelope: `{"success": true, "data": ...}` / `{"success": false,
  "error": {...}}`. Auth: `X-Service-Key` (fail-closed).
- GET = read-only. Anything that computes + persists = POST / job.
- Tests: PHP `php vendor/bin/phpunit <file>` (workdir `application/`, env
  `DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array
  SESSION_DRIVER=array QUEUE_CONNECTION=sync`). Python:
  `& "<root>\.venv\Scripts\python.exe" -m pytest tests/<file> -q -p
  no:cacheprovider` (workdir `ai-engine/`).
- No commits by agents. No `.env` edits. No secrets. Keep existing suites green.

## Ownership

| Agent | Domain | Exclusive files (create/edit) | Must NOT touch |
|---|---|---|---|
| A1 data-platform | Catalog/lineage/schema registry | `application/app/Models/{DatasetVersion,ColumnMetadata,DataLineage,DataContract}.php`, migration `2026_09_30_010000_*`, `application/app/Services/CatalogService.php`, `application/app/Http/Controllers/Api/{CatalogController,LineageController}.php`, `application/tests/Feature/{CatalogTest,LineageTest}.php`, `docs/data-platform.md`, `docs/data-dictionary.md` (append) | routes/*, bootstrap, existing models |
| A2 ingestion | Readers/ETL/resume | `ai-engine/app/ingestion/**` (sole writer), `ai-engine/app/api/v1/ingestion.py` (new), `ai-engine/tests/test_ingestion_enterprise.py`, `application/app/Jobs/RunImportJob.php`, `docs/data-ingestion.md` | workers/*, existing routers |
| A3 quality | Rules/PII/governance | `ai-engine/app/quality/**` (new pkg), `ai-engine/app/api/v1/quality.py` (new), `ai-engine/tests/test_quality_enterprise.py`, `application/app/Models/{QualityRule,QualityProfile}.php`, migration `2026_09_30_020000_*`, `application/app/Services/QualityService.php`, `application/app/Http/Controllers/Api/QualityController.php`, `application/tests/Feature/QualityEnterpriseTest.php`, `docs/data-quality.md` | ingestion/quality.py logic (read-only import ok) |
| A4 BI | KPI/dashboards/exports | `ai-engine/app/analytics/**` + `ai-engine/app/api/v1/analytics.py` (sole writer), `ai-engine/tests/test_bi_enterprise.py`, `application/app/Http/Controllers/{AnalyticsController,ReportController}.php` + `Api\AnalyticsController.php` (sole writer), `application/resources/views/{analytics,reports}/**`, `application/tests/Feature/BiEnterpriseTest.php`, `docs/bi.md` (new) | other controllers |
| A5 ML | Training/registry/forecast | `ai-engine/app/ml/**` + `ai-engine/app/api/v1/{training,models,forecast,customers,inventory,anomaly,recommendation}.py` + `ai-engine/app/schemas/ml.py` (sole writer), `ai-engine/tests/test_ml_enterprise.py`, `application/app/Http/Controllers/{MlController,Api\MlController}.php` (sole writer), `application/resources/views/ml/**`, `application/tests/Feature/MlEnterpriseTest.php`, `docs/machine-learning.md`, `docs/model-governance.md` | other routers |
| A6 AI/RAG | Assistant/RAG/SQL guardrails | `ai-engine/app/ai/**` + `ai-engine/app/api/v1/{ai,rag}.py` + `ai-engine/app/schemas/ai.py` (sole writer), `ai-engine/tests/test_ai_enterprise.py`, `application/app/Http/Controllers/{AssistantController,Api\{AgentController,RagController}}.php` (sole writer), `application/app/Models/AiUsage.php` + migration `2026_09_30_030000_*`, `application/tests/Feature/AiEnterpriseTest.php`, `docs/rag.md`, `docs/ai-agent.md` | other controllers |
| A7 decision | Decision/scenario engine | `ai-engine/app/decision/**` (new) + `ai-engine/app/api/v1/decision.py` + `ai-engine/app/schemas/decision.py` (new), `ai-engine/tests/test_decision_engine.py`, `application/app/Services/DecisionService.php`, `application/app/Http/Controllers/Api/DecisionController.php`, migration `2026_09_30_040000_*`, `application/tests/Feature/DecisionEngineTest.php`, `docs/decision-engine.md` (new) | existing routers |
| A8 security | Audit/hardening | `application/app/Http/Middleware/**`, `application/app/Policies/**` (new), `application/app/Models/User.php` (sole writer), `application/app/Http/Controllers/DatasetController.php` (upload validation, sole writer), `ai-engine/app/core/security.py` (sole writer), `application/tests/Feature/SecurityEnterpriseTest.php`, `ai-engine/tests/test_security_enterprise.py`, `docs/security.md` | weakening auth (forbidden) |
| A9 devops | Infra/observability | `docker-compose.yml`, `infrastructure/**`, `Makefile`, `.env.example` (additive), `tests/verify-*.sh`, migration `2026_09_30_050000_*` (perf indexes), `docs/{deployment,monitoring,backup-restore}.md`, `docs/operations.md` (new) | app code |
| A10 QA | Fixtures/testing guide | `application/tests/Fixtures/**` (new), `ai-engine/tests/fixtures/**` (new), `application/tests/Feature/ContractRegressionTest.php` (new, existing surface only), `docs/testing.md` (new) | feature code |

## Route namespaces reserved (wired by master at integration)

- A1: `/api/catalog/*`, `/api/lineage/*`, `/api/schema-registry/*`
- A2 (engine): `/api/v1/imports/{resume,cancel,checkpoints}` via new router
- A3 (engine): `/api/v1/quality/*` via new router; Laravel `/api/quality/*`
- A4: existing analytics URIs (extend); exports `/api/analytics/export`
- A5: existing ML URIs (extend); `/api/ml/experiments`, `/api/ml/batch-predict`
- A6: existing `/api/agent/chat`, `/api/rag/query` (extend); `/api/ai/usage`
- A7 (engine): `/api/v1/decision/*`; Laravel `/api/decisions/*`

## Engine DB models

Agents define SQLAlchemy models by importing the shared `Base` from
`app.database.connection` in their OWN new modules (never edit
`app/database/models.py`). Master generates the single alembic revision from
the collected DDL specs at integration.
