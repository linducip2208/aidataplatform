# Development

Conventions for working across `application/` and `ai-engine/` without breaking the contract.
The endpoint contract lives in `api.md` and is the source of truth for every path.

## 1. Branch and environment

- One branch per feature. Never commit `.env`, `storage/`, `datasets/`, `models/` or
  `*.sql.gz`; `.gitignore` covers them.
- Each developer has their own root `.env` from `.env.example`. Share one `SERVICE_API_KEY`
  per machine — it must be byte-identical between Laravel and the engine.
- `APP_DEBUG=true` locally, `false` in any shared environment.
- Never commit `application/.env`; it carries the four `AI_ENGINE_*_TIMEOUT` values that
  Compose does not inject, so the container reads them from there.

## 2. Daily loop (Docker)

```bash
docker compose up -d
docker compose logs -f laravel laravel-queue laravel-schedule fastapi celery-worker   # one window
make migrate                                          # after pulling a schema change
docker compose exec fastapi pytest -q                  # fast feedback
bash tests/run.sh                                     # contract check before pushing
```

Targeted rebuilds: `docker compose up -d --build laravel` after a PHP dependency change,
`... fastapi` after a Python one. Source is bind-mounted, so code edits are picked up
without a rebuild; restart the container after changing `requirements.txt` or any env var.

Make targets worth knowing: `make ps`, `make logs`, `make health`, `make test`, `make lint`,
`make shell-laravel`, `make shell-ai`, `make backup`, `make restore FILE=…`.

## 3. Contract-first rule (load-bearing)

The engine prefix is `/api/v1`; Laravel calls it with the service-key header. Changing any
request or response shape means updating, in the same PR:

1. `docs/api.md` — the contract.
2. `tests/run.sh` — the integration checklist.
3. Both sides of the call.

The engine's endpoint groups are fixed: `/imports/*`, `/analytics/*`, `/forecast`,
`/customers/{churn,segment}`, `/inventory/health`, `/anomaly/detect`, `/recommend`,
`/models/*`, `/training/{train,predict}`, `/ai/{chat,report}`, `/rag/{ingest,query}` and
`/health`, `/readiness`, `/liveness`. `/health`, `/readiness`, `/liveness`, `/metrics` and the
schema routes are the only ones without a `require_service_auth` dependency, and adding a route
outside that set is a security change, not a refactor. The queue names in `CELERY_QUEUES` and the
DDL split between Alembic and Laravel migrations are equally frozen; see `data-dictionary.md`.

## 4. Laravel specifics

- `application/` is a standard Laravel 12 app: `php artisan make:*`, Pint for formatting
  (`./vendor/bin/pint`, or `make lint`), PHPUnit/Pest for tests. Migrations must be
  reversible; seeders idempotent (`UserSeeder` is keyed on the e-mail and only fills columns
  that are still `null`). The frontend is built by Vite; `npm run build` is required for a
  non-Docker run, and the Docker image does it in its own assets stage.
- All engine access goes through `App\Services\AiEngineClient`. Add a method there rather
  than calling `Http::` from a controller. It already handles the envelope unwrap, the
  service-key header, per-call timeouts and retries (2 attempts, 250 ms apart).
- Never hardcode `http://fastapi:8000`; read `config('ai_engine.base_url')`.
- Long or queued work is Laravel's own queue: `App\Jobs\RefreshQualityScoreJob`, dispatched
  with `php artisan sync:quality --queue` onto the `datasets` queue, which `laravel-queue`
  consumes as `datasets,default`.
- Recurring work belongs in `application/routes/console.php`. The two entries there run under
  `laravel-schedule` (`php artisan schedule:work --whisper`); keep `withoutOverlapping()` on
  anything that hits the engine, because its mutex lives in the cache store.
- Roles and permissions are declared in `App\Enums\UserRole` and enforced by
  `App\Http\Middleware\EnsureRole` via the `role:` middleware. Add a capability by extending
  the middleware's role list in `routes/web.php` and `routes/api.php`, not with ad-hoc
  `abort(403)` calls.

## 5. AI-engine specifics

- `ai-engine/` targets Python 3.13, Ruff for lint and format (`make lint`, `make format`),
  pytest for tests. Long work belongs in a Celery task in `app/workers/tasks.py`; if you add
  one, also add its queue to `CELERY_QUEUES`.
- LLM access only through `app/ai/llm.py` and the settings in `app/core/config.py`. No raw
  keys in code. When no API key is configured, `chat()` returns a local echo summary and
  `embed()` returns deterministic 128-dimension hash vectors — the rest of the platform keeps
  working, so never let a feature depend on a real LLM answer.
- Adding a table means a new Alembic revision in `ai-engine/alembic/versions/` and an update
  to `data-dictionary.md` in the same PR.

## 6. Testing

- Unit: PHPUnit/Pest under `application/tests/`, pytest under `ai-engine/tests/`.
  `make test` runs both; `make test-laravel` and `make test-ai` run one.
- Integration: `bash tests/run.sh` — health, token login, role enforcement, upload, import
  job poll, quality, analytics, engine-direct model list, missing-service-key rejection and a
  RAG query. Run it before every push that touches either service.
- Fixtures live in `tests/fixtures/` (small CSVs). `sample_sales.csv` is the one
  `run.sh` uploads. Large datasets stay out of git.
- CI: `.github/workflows/laravel.yml` and `python.yml` run the unit suites, `docker.yml`
  builds the images, `deploy.yml` handles the deploy job. Add new suites there.

## 7. Debugging

`make logs` or `docker compose logs -f <service>`; `make shell-laravel` / `make shell-ai`.
For a single dataset or the whole stack:

```bash
docker compose exec laravel php artisan platform:doctor            # config, key, schema, storage
docker compose exec laravel php artisan platform:doctor --json     # same, for a monitor
docker compose exec laravel php artisan sync:import-status --dry-run
docker compose exec laravel php artisan sync:quality --dry-run
docker compose exec mysql mysql -u aidata -p aidata -e 'SHOW TABLES'     # all tables
```

The engine sets `X-Request-ID` on every response and logs it; send that header from a
reproduction to correlate the two sides. Full symptom matrix in `troubleshooting.md`.
