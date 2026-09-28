# Development

Conventions for working across `application/` + `ai-engine/` without breaking the contract.

## 1. Branch + env

- Branch per feature; never commit `.env`, `storage/`, `data/`, `*.sql.gz` (see `.gitignore`).
- Each dev has own `.env` from `.env.example`; share only `SERVICE_API_KEY` for local
  cross-service calls. `APP_DEBUG=true` locally, `false` in any shared env.

## 2. Daily loop (Docker)

```bash
docker compose up -d
docker compose logs -f laravel fastapi celery-worker   # one window
docker compose exec laravel php artisan migrate        # after pulling migrations
docker compose exec fastapi pytest -q -x               # fast feedback
bash tests/run.sh                                      # contract check before push
```

Targeted rebuilds: `docker compose up -d --build laravel` (PHP change) or `fastapi`
(Python change). Workers pick up code via bind-mount; restart after dependency changes.

## 3. Contract-first rule (load-bearing)

- FastAPI prefix is `/api/v1`; Laravel calls it with header `X-Service-Key: $SERVICE_API_KEY`.
  Endpoint shapes (`/ingest`, `/quality/*`, `/ml/*`, `/agent/*`, `/rag/*`) are frozen in
  `api.md` — changing request/response requires updating `api.md` + `tests/run.sh` + the
  other service in the same PR.
- Queue names (`default,imports,quality,ml,agent,rag`) and schemas
  (`raw/staging/warehouse/analytics/ml/ai`) are likewise frozen; see `data-dictionary.md`.

## 4. Laravel specifics

- Code in `application/` (other agent owns it). Standard: `php artisan make:*`, Pint
  (`./vendor/bin/pint`), Pest/PHPUnit. Migrations must be reversible; seeders idempotent.
- Calling AI: centralize in one HTTP client class (timeout 120 s, retries, service key);
  never hardcode `http://fastapi:8000` — use `AI_ENGINE_URL`.

## 5. AI-engine specifics

- Code in `ai-engine/` (other agent owns it). Python 3.13, Ruff, pytest. Long work goes
  in Celery tasks, never in request handlers (300 s Nginx timeout is a ceiling, not a goal).
- LLM access only via provider abstraction (`LLM_PROVIDER`, default OpenRouter);
  no raw API keys in code; `EMBED_MODEL` changes require re-embedding (`rag.md`).

## 6. Testing

- Unit: Pest (Laravel), pytest (ai-engine). Integration: `tests/run.sh` (curl health +
  ingest → quality → ML → RAG smoke). CI runs all three (`laravel.yml`, `python.yml`).
- Fixtures live in `tests/fixtures/` (small CSVs); large datasets stay out of git.

## 7. Debugging

`make logs` / `docker compose logs -f <svc>`; `make shell-laravel` / `shell-ai`;
`docker compose exec postgres psql -U aidata -d aidata -c '\dn'` for schemas;
`docker compose exec redis redis-cli ping`. Full matrix: `troubleshooting.md`.
