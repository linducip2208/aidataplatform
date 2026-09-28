# Integration Tests

Contract checklist against a running stack (`docker compose up -d`). No framework needed —
`tests/run.sh` uses curl + python stdlib only (works on Linux/Git Bash/WSL).

## Run

```bash
bash tests/run.sh
# targeted: BASE_LARAVEL=http://localhost:8080 BASE_AI=http://localhost:8001 bash tests/run.sh
# Windows PowerShell: the script needs Git Bash/WSL; healthcheck.ps1 covers basic health natively.
```

## What it checks (in order, fail-fast with summary)

1. Laravel `GET /up` → 200.
2. FastAPI `GET /api/v1/health` → 200 (`db/redis up` if reported).
3. FastAPI `GET /docs` → 200 (OpenAPI served).
4. Ingest smoke: `POST /api/v1/ingest` with `tests/fixtures/sample_sales.csv`
   (needs `SERVICE_API_KEY` from `.env`) → `202 {job_id}` → poll `GET /jobs/{id}` until terminal.
5. Quality: `POST /quality/run` → `GET /quality/{dataset_id}` shows `score/threshold/verdict`.
6. ML dry-run: `POST /ml/train` small payload → `202` (worker picks up; script does not block on completion).
7. RAG query skeleton: `POST /rag/query` (expects indexed data; warns — not fail — if 404/empty).

## Fixtures

`tests/fixtures/sample_sales.csv` — 20-row sales sample (date, customer, product, qty, amount).
Keep fixtures < 100 KB and PII-free. Large/private datasets stay out of git (`.gitignore`).

## CI

Workflows run unit suites (Laravel Pest + pytest); this script is the pre-merge
manual gate and the post-deploy smoke (`deployment.md`). Extend it when `docs/api.md` changes.
