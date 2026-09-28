# Troubleshooting

Start here:

```bash
bash infrastructure/scripts/healthcheck.sh   # .ps1 on Windows
docker compose ps
docker compose logs --tail=50 <service>
docker compose exec laravel php artisan platform:doctor
```

`healthcheck.sh` probes Laravel `/up`, the engine `/api/v1/health`, Nginx `/health`,
`pg_isready`, `redis-cli ping`, and that `celery-worker` and `celery-beat` are up.
`platform:doctor` adds configuration, service-key, schema and storage checks, and prints a
remedy line per failure. The engine-backed pages are all proxy calls, so a Laravel `502` or
`503` almost always means the engine is down or the two services disagree about the key.

## 1. Boot and health

| Symptom | Cause → Fix |
|---|---|
| Every page `500`, "Vite manifest not found" | The frontend was never built. `cd application && npm install && npm run build`. Compose bind-mounts `./application` over `/var/www/html`, so build on the host before `up -d`. `npm run dev` while iterating. |
| `laravel` unhealthy, `/up` fails | `APP_KEY` empty (every request fails on the encrypter) → `docker compose exec laravel php artisan key:generate --show`, set it in `.env`, `up -d laravel`. Or Postgres unreachable, or `storage/` not writable: `chown -R www-data:www-data storage bootstrap/cache`. |
| Stack never finishes starting, `redis` unhealthy | You set `REDIS_PASSWORD`. The Redis healthcheck runs `redis-cli ping` with no `-a`, so with a password Redis stays unhealthy and `laravel`/`fastapi`/`celery-*` block on `service_healthy` forever. Leave it empty, or add `-a $REDIS_PASSWORD` to that healthcheck. |
| `nginx` 502 | Upstreams not healthy yet (30 s start period) — wait, then `healthcheck.sh`. Otherwise the `default.conf` mount path is wrong. |
| `postgres` auth failed after changing `POSTGRES_PASSWORD` | `pgdata` keeps the first-boot password. `docker compose exec postgres psql -c "ALTER USER aidata PASSWORD 'new';"` and update `.env`. `docker compose down -v` fixes it and destroys all data. |
| `celery-beat` crash-loops, "Cannot load the scheduler class" | `CELERY_BEAT_SCHEDULER=redbeat.RedBeatScheduler`, and `redbeat` is not in `ai-engine/requirements.txt`. Use `celery.beat.PersistentScheduler`. |
| `celery-worker` crash-loops on Windows | Needs the solo pool: `-P solo`. |
| `fastapi` `/api/v1/health` returns `200` but business calls fail | `/health` is deliberately unauthenticated. A green health check proves nothing about key enforcement — see §2. |
| `platform:doctor` reports missing engine tables | `alembic upgrade head` was never applied. `make migrate`, then re-run the doctor. |
| `platform:doctor` reports missing Laravel tables | `php artisan migrate --force`. The Laravel image runs it on start, so this usually means a migration failed — check `docker compose logs laravel`. |
| `platform:doctor` reports `missing extension(s): vector, pg_trgm` | `CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS pg_trgm;` as a superuser against the database, or delete the `pgdata` volume and let `infrastructure/docker/postgres/init.sql` run again. |

## 2. Auth and roles

| Symptom | Cause → Fix |
|---|---|
| Engine-backed pages `502`, engine log shows `401` | `SERVICE_API_KEY` mismatch, or a placeholder. Compose injects one value into all four services, so this normally means `application/.env` was edited separately and now diverges, or the value was changed without restarting `celery-worker`/`celery-beat`. `platform:doctor` names this case explicitly. |
| Every engine call is `401`, and the key in `.env` is the shipped one | Placeholders are treated as *not configured*: empty, `change-me`, `change-me-service-key`, `changeme` and `secret` all reject every caller by design. Generate a real key and restart `laravel fastapi celery-worker celery-beat`. |
| Renaming `SERVICE_API_KEY_HEADER` breaks every engine call | Expected. Compose passes that variable to `laravel` but not to `fastapi`, so the engine keeps reading `X-Service-Key`. Keep `SERVICE_API_KEY_HEADER=X-Service-Key`. |
| `fastapi` will not start, log says `APP_ENV=... is not a known environment` | The engine accepts only `dev`, `demo`, `local`, `test`, `staging`, `stage`, `prod`, `production` and now raises instead of guessing. Correct the value in the root `.env`. |
| `fastapi` will not start, log says `SERVICE_API_KEY is not a usable HTTP header name` | `SERVICE_API_KEY_HEADER` is not a valid RFC 7230 token. The engine falls back to `X-Service-Key` for a malformed value, so rename it only to a well-formed name — and only on both services. |
| Login returns `422` with a correct password | The account is `is_active = false`, or the seeder never ran for that address (`UserSeeder` only sets a password when it creates the row). Check `users.is_active`. |
| `403` with `code: forbidden` on a write | Role gate. `EnsureRole` allows `POST /datasets`, `/mapping`, `/commit`, `/ml/train` for `admin` and `analyst` only, and `POST /ml/models/{id}/promote` for `admin` only. Check the role in `GET /api/me`. |
| `404` on `/api/datasets/{id}` | The path takes a UUID, not the integer `id`. `GET /api/datasets` returns `data[].id` as the uuid. |
| Re-running the seeder did not reset a password | By design: `UserSeeder` is keyed on the e-mail and only fills columns that are still `null`. Deactivate or delete the account, or set the password with tinker. |

## 3. Uploads and ingestion

| Symptom | Cause → Fix |
|---|---|
| `413 Payload Too Large` | Three separate ceilings: Nginx `client_max_body_size 500M` (`infrastructure/nginx/default.conf`), PHP `upload_max_filesize=500M` / `post_max_size=550M` (baked in by `infrastructure/docker/laravel.Dockerfile`), and Laravel's `max:MAX_UPLOAD_MB` rule. Raise all three together. |
| `422` on upload with `errors.file` | Extension not in `config('ai_engine.allowed_extensions')` (`csv, xlsx, xls, json, parquet, zip, txt`), or `dataset_type` not in `config('ai_engine.dataset_types')` (`sales, inventory, purchases, expenses, customers, generic`). This is a validation error, not a size error. |
| `422` "Dataset has no import job yet" | `datasets.import_job_id` is null, so the upload never reached the engine. Check the Laravel log for `ai_engine.unreachable` and confirm `AI_ENGINE_URL` resolves from the container. Re-upload to create a new job. |
| Import job stuck in `queued` | Nothing is consuming the `imports` queue, which is where `celery_app.py` routes `app.workers.tasks.import_file`. Confirm `CELERY_QUEUES` covers every route in `TASK_ROUTES` (`imports`, `quality`, `ml`, `agent`, `rag`, `default`) and that `celery-worker` is up. `docker compose logs -f celery-worker`. If the worker exits at once with an import error about the `-A` target, see the next row. |
| `celery-worker` or `celery-beat` exits immediately with "Unable to load celery application" | The `-A` target in `docker-compose.yml` is `app.celery_app.celery_app`, but the module lives at `app/workers/celery_app.py`, so the correct target is `app.workers.celery_app.celery_app`. Nothing is consumed while the container is down, so every async commit stays `queued`. |
| `tests/run.sh` warns "import still done after ~60s" | The job actually succeeded. `run_etl` sets the terminal status to `done` / `done_with_errors`, while the script's poll loop only recognises `succeeded|success|completed`. Check `GET /api/import-jobs/{importJobId}` directly; treat `done` as success. |
| `failed` status right after upload | Engine validation rejected the file: wrong extension, empty, or unparseable. `datasets.metadata.validation` holds the engine's `{ok, meta}` payload, and the reason is in the `raw_uploads` row. |
| Duplicate rows in `fact_sales` after re-uploading the same file | Re-uploading creates a *new* `import_job_id`, and the loader only deletes rows belonging to the job it is given. Delete the dataset and re-upload, or re-commit the original job id. |
| Commit returns `202` and nothing happens | `run_async: true` (the default) requires a running worker subscribed to the `imports` queue. Pass `run_async: false` to run the ETL inside the request. |
| Everything is stale in the UI | Local `datasets.status` is a mirror. `php artisan sync:import-status` reconciles it against the engine, `--dry-run` first. |

## 4. Quality

| Symptom | Cause → Fix |
|---|---|
| Dataset stuck at `quarantined` | Its score is below the threshold, and `quarantined` is terminal in `App\Enums\DatasetStatus`. Fix the source and re-upload, or re-run `php artisan sync:quality` after the data is corrected. |
| A score of 0.70 reports `threshold: 0.75` but `verdict: pass` | One variable, two defaults. Both services read `QUALITY_THRESHOLD`, but Laravel's default is `0.75` and the engine's is `0.6`, and Laravel prefers the engine's `passed` flag. Set `QUALITY_THRESHOLD` explicitly in the root `.env` so both agree. See `data-quality.md` §3. |
| The engine logs "`QUALITY_MIN_SCORE` is deprecated" | Expected. `QUALITY_THRESHOLD` is the canonical name; the old engine-local name still works. Rename it and stop setting both. |
| `data-quality` check counts a real duplicate as unique | `uniqueness` uses `df.duplicated()` on whole rows. Rows that differ in any column are not duplicates. |
| `invalid_date` issues on valid data | The date rule fires on any column whose name contains `date`, `tanggal` or `tgl`, and counts anything `pd.to_datetime` cannot parse. Ambiguous formats (`01/02/2026`) parse but may be interpreted as the wrong month. |

## 5. ML, assistant, RAG

| Symptom | Cause → Fix |
|---|---|
| `POST /api/ml/train` returns `422` on `params` | `params` is a JSON-encoded **string**, not an object: `{"params":"{\"n_clusters\":4}"}`. |
| Training through the platform produces empty or meaningless metrics | `POST /api/ml/train` sends no `dataset` array, and the training contract has no `dataset_id` — the only row source is the request body. Call `POST /api/v1/training/train` directly with a `dataset` array to train on real rows. |
| `422` "no production churn model" | No `ml_models.production_version_id` is set, or the artifact is gone from disk. Train, then promote the version to `PRODUCTION`. |
| `to_status: "STAGED"` does nothing | `STAGED` is accepted by `Api\MlController` but is not one of the engine's states (`DRAFT, TRAINING, VALIDATED, PRODUCTION, ARCHIVED, FAILED`), so it stores without effect. Use `PRODUCTION` or `ARCHIVED`. |
| Assistant returns "LLM offline" or an echo summary | No LLM API key, or the provider is unreachable after `LLM_MAX_RETRIES` (3) attempts. Check `LLM_API_KEY` / `OPENROUTER_API_KEY` and `LLM_PROVIDER`, then `docker compose logs fastapi`. The rest of the platform works without a key. |
| Assistant answers are wrong but plausible | The tools are not dataset-scoped and `_load_frame` truncates at 5000 joined rows before aggregating, so a large warehouse understates totals. Use the analytics endpoints for exact numbers. |
| Assistant `502` on a heavy question | Laravel gives up at `AI_ENGINE_LLM_TIMEOUT` (120 s) while the engine may spend `LLM_MAX_RETRIES × LLM_TIMEOUT_SECONDS` (3 × 60 s). Lower `LLM_TIMEOUT_SECONDS` or raise `AI_ENGINE_LLM_TIMEOUT`. |
| RAG query returns irrelevant or no citations | The corpus is empty, or `rag_chunks` has more than 2000 rows — `query` loads at most 2000 and ranks them in Python, so the tail is invisible. There is no vector index. |
| RAG answers are nonsense but non-empty | The engine fell back to `_hash_embed` (128-dimension hash vectors) because the embedding call failed. That happens with no API key or a provider error; check the engine log for `embeddings failed, fallback`. |
| `GET /metrics` answers 403 or is empty from outside the compose network | Intentional. `/metrics` is served only to internal peers unless `METRICS_ALLOW_PUBLIC=true`, because Compose publishes the engine on the host. Prometheus on the compose network is internal and unaffected. |
| Swagger `/docs` is gone | `DOCS_ENABLED=false` closes `/docs`, `/redoc` and `/openapi.json`. |
| Model artifacts disappear after a container rebuild | The engine's `MODEL_PATH` defaults to `./models` under its `/code` working directory, while compose mounts `models-cache` at `/app/data/models`. Set `MODEL_PATH` to the mounted path. |

## 6. Observability

Prometheus targets `laravel`, `redis` and `postgres` show DOWN. Expected: Laravel exposes no
`/metrics` route, and neither exporter is in the compose file. The engine target is the only
one that resolves, and it emits only `http_requests_total` and
`http_request_latency_seconds` — every other panel in the shipped Grafana dashboard shows
"No data" because those metrics do not exist. See `monitoring.md` for the working queries and
the exporter commands.

Grafana login fails: use `GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD` from the root `.env`,
reset with `docker compose exec grafana grafana cli admin reset-admin-password`. The dashboard
is mounted but no provider is provisioned, so import the JSON by hand.

## 7. `tests/run.sh` results

The checklist is the contract test. It reads `SERVICE_API_KEY` from the root `.env` and skips
steps 5–8 as warnings when that is empty, so an all-green run with skips is not a pass.
`BASE_LARAVEL` defaults to `http://localhost:8080` and `BASE_AI` to `http://localhost:8001`.

| Message | Meaning |
|---|---|
| `viewer blocked from upload (403)` FAIL | Role enforcement is broken — a `viewer` reached `POST /api/datasets`. |
| `engine rejects missing service key (403)` WARN | The script asserts `403`, but the engine correctly answers **401** for a missing key, so this warns on a healthy deployment. Read it as "confirm the code yourself": `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models` must print `401`. A `200` there is the real failure. |
| `kpi empty` WARN | No committed `fact_sales` rows yet. Commit a dataset first. |
| `rag empty` WARN | Nothing indexed. That is the normal state on a fresh install. |
| `import still <status> after ~60s` WARN | `run_etl` finishes as `done` / `done_with_errors`, which the poll loop does not recognise. Verify by hand; see §3. |

A FAIL on steps 1–4 (health, login, `/api/me`, wrong-password 422) always indicates a real
problem with the Laravel app or the stack, not the engine.
