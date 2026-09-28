# Security

Threat model: an internet-facing Nginx, one Laravel application and one FastAPI engine
sharing a database. The browser is untrusted; Laravel is the only surface it reaches. The
engine is internal and must never be called directly by a client.

## 1. Authentication and authorisation

Two independent mechanisms, and they must both be configured correctly.

**Users** — Laravel Sanctum bearer tokens. `POST /api/login` returns a plain-text token in
`data.token`; send it as `Authorization: Bearer <token>`. `POST /api/logout` revokes the
current token. The Blade UI uses the same `auth` session middleware. Roles are `admin`,
`analyst`, `viewer` (`App\Enums\UserRole`), enforced server-side by
`App\Http\Middleware\EnsureRole`, which returns `401` when unauthenticated, `403` for a
deactivated account, and `403` with `code: forbidden` for the wrong role. Route-level
deactivation is a second gate, independent of the token: a valid token for an
`is_active = false` user cannot write.

The three seeded demo accounts in `README.md` are development credentials. Rotate
`admin@example.com` before go-live and delete or deactivate the other two.

**Service-to-service** — `X-Service-Key: $SERVICE_API_KEY` on every Laravel → engine call,
injected by `App\Services\AiEngineClient`. The key never reaches a browser, a Blade template
or a Vite-exposed variable. Laravel sends it in the header named by
`SERVICE_API_KEY_HEADER`, and the engine reads the same variable
(`ai-engine/app/core/security.py::resolve_service_key_header`), so the two agree as long as
the name is left alone. It must stay `X-Service-Key`: `docker-compose.yml` passes
`SERVICE_API_KEY_HEADER` to the `laravel` service but **not** to `fastapi`, `celery-worker` or
`celery-beat`, so renaming it on the Laravel side alone makes the engine keep listening on
`X-Service-Key` while Laravel sends something else. The engine also rejects a header name that
is not a valid RFC 7230 token or that collides with a load-bearing header (`Authorization`,
`Cookie`, `Host`, `X-Request-ID`, …), falling back to `X-Service-Key` in both cases.

Generate and rotate per environment:

```bash
python -c "import secrets; print(secrets.token_urlsafe(48))"
```

The engine's enforcement is **fail-closed**: `require_service_auth` accepts a service key
compared in constant time (`secrets.compare_digest`) or a valid Bearer JWT, and raises **401**
for everything else. There is no environment in which a missing credential succeeds, and
placeholder secrets are treated as "not configured" — an empty value, `change-me`,
`change-me-service-key`, `changeme` or `secret` all reject every caller rather than accepting
anyone who read the repository. So a correct deployment cannot serve the warehouse to an
anonymous client, and an unconfigured one cannot serve it to anybody.

What you must still get right is the *key itself*: it has to be identical on both sides and
non-empty. Compose injects one root-`.env` value into `laravel`, `fastapi`, `celery-worker` and
`celery-beat`, so they agree by construction; a mismatch means someone edited
`application/.env` separately (the container reads the four `AI_ENGINE_*_TIMEOUT` values from
there) or restarted only some of the containers. Verify:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models                        # 401
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models -H "X-Service-Key: wrong" # 401
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models -H "X-Service-Key: $SERVICE_API_KEY" # 200
```

`GET /api/v1/health` is deliberately unauthenticated and answers `200` regardless, so a green
health check proves nothing about key configuration. `GET /api/v1/readiness` likewise.

The engine's limiter is a sliding window that fails closed: `RATE_LIMIT_PER_MINUTE`
requests per minute (default 120) per credential fingerprint **and** three times that per path
regardless of credential, so varying an unknown header value does not mint a fresh bucket. Only
the credential's SHA-256 prefix is stored. Exceeding either limit returns `429` with
`code: RATE_LIMITED`, which Laravel surfaces as `502`.

**Grafana** — `GF_USERS_ALLOW_SIGN_UP=false` is set in compose. Set a strong
`GRAFANA_ADMIN_PASSWORD`; the default is `admin`.

## 2. Secrets

Everything sensitive comes from the root `.env` via `${VAR}` in `docker-compose.yml`. Never
commit `.env`, `*.pem` or `*.key`. Required in production:

| Secret | Consequence if left at the default |
|---|---|
| `POSTGRES_PASSWORD` | The shipped value is a literal dev placeholder; the database is open to anything that reaches port 5432 |
| `SERVICE_API_KEY` | A placeholder or empty value is treated as *not configured*, so every engine call fails with `401` and the platform is unusable. The shipped default is a known public string, which is worse than either |
| `APP_KEY` | The Laravel entrypoint refuses to start without it, and every request would fail on the encrypter |
| `GRAFANA_ADMIN_PASSWORD` | Graph panels and the Prometheus data behind them |
| `LLM_API_KEY` / `OPENROUTER_API_KEY` | Someone else's quota; the assistant falls back to a local echo summary |

`APP_KEY` is a base64 string: `openssl rand -base64 32`, keeping the `base64:` prefix, or
`php artisan key:generate --show`. Note that Compose passes `APP_KEY` through from the root
`.env`, so an empty root value **overrides** a key set in `application/.env`.
`platform:doctor` flags an empty key, a key without the `base64:` prefix, and `APP_DEBUG=true`
while `APP_ENV=production`. `JWT_SECRET` matters for the same reason: a placeholder there
makes the engine refuse to mint tokens, and an unset one means the Bearer path never
validates.

LLM keys stay in the engine's environment. They are never sent to the browser and never
included in an error body — `PlatformHealth::redact()` additionally strips the service key
from any message it prints, as defence in depth.

## 3. Transport and headers

Terminate TLS in front of Nginx with Certbot (`deployment.md` §3), then uncomment the HSTS
header in `infrastructure/nginx/default.conf` and restart nginx. `default.conf` already sets
`X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection` and
`Referrer-Policy: strict-origin-when-cross-origin` on every response, and gzips JSON, CSS and
JS.

The browser must reach the engine only through Laravel. Nginx does expose `/ai-api/` with the
prefix stripped, which makes the engine reachable from the internet on the same origin as the
app; the service key is the only thing protecting it. Either remove that `location` block, or
restrict it to internal callers with `allow`/`deny` plus an `internal;` directive. In Docker,
`docker-compose.yml` publishes `fastapi` on host port 8001 and Postgres on 5432 and Redis on
6379 — bind those to `127.0.0.1` or drop the `ports:` entries so they are reachable only from
the host and the compose network. UFW allows 22, 80 and 443.

Internal traffic is plain HTTP on the compose network, which is the normal arrangement; the
one thing to avoid is `AI_ENGINE_URL` pointing at a plain-HTTP non-internal host from a
production deployment, which `platform:doctor` warns about.

## 4. Input validation and data protection

**Uploads.** Three independent limits must agree: Nginx `client_max_body_size 500M` and its
300 s timeouts, PHP `upload_max_filesize=500M` / `post_max_size=550M` from
`infrastructure/docker/laravel.Dockerfile`, and Laravel's `max:MAX_UPLOAD_MB` rule
(default 500). Laravel also enforces an extension allowlist (`csv, xlsx, xls, json, parquet,
zip, txt`) and a `dataset_type` allowlist, and stores the file under a
server-generated path (`datasets/YYYY/MM/<random>`), so an uploaded filename never reaches
the filesystem as a path. The engine writes its own copy under a `<uuid>_<filename>` name.

A file that fails the extension or size check gets `422`, not `413`, with the reason in
`errors.file` — do not treat a `422` on upload as a Laravel bug.

**SQL.** Every database call is through the query builder or Eloquent; the engine uses
SQLAlchemy with bound parameters. `AiEngineClient` sends JSON only, so there is no way to
inject a filter expression through the analytics endpoints — the `branch`, `category`,
`date_from`, `date_to` and `granularity` query parameters are passed as bound values.

**PII.** The warehouse (`fact_sales`, `fact_purchases`, `fact_expenses`, `fact_inventory`, `dim_customer`) and the
RAG corpus (`rag_documents`, `rag_chunks`) contain the business data. There is no row-level
security and no per-dataset ACL in this build: role checks happen in the controllers only, and
`POST /api/agent/chat` — which a `viewer` may call — reads committed sales data from every
dataset. Treat the database as single-tenant and put your real per-tenant isolation in front
of the platform. Do not back up to an unencrypted bucket, and restrict `backups/`
permissions (`BACKUP_RETENTION_DAYS` prunes by age, not by encryption).

**Quarantine.** A dataset scoring below the threshold is marked `quarantined` and never
loaded into the warehouse, so it is absent from analytics, the assistant and training inputs.
That is a data gate, not an access control.

**Prompt injection.** Dataset content reaches the assistant's prompt as evidence. The system
prompt instructs the model to answer only from the evidence and to declare missing data, and
the offline fallback path cannot invent values because it prints the evidence verbatim. There
is no output filter and no exfiltration scrubber, so the assistant will faithfully echo
whatever is in the warehouse to anyone allowed to ask a question.

## 5. Supply chain

Base images are pinned to specific tags: `pgvector/pgvector:pg18`, `redis:7-alpine`,
`nginx:1.27-alpine`, `prom/prometheus:v2.53.0`, `grafana:11.2.0`, `php:8.3-fpm-alpine`,
`python:3.13-slim`. Laravel requires `php ^8.2` and `laravel/framework ^12.0`; the engine
pins its Python dependencies in `ai-engine/requirements.txt`. Run `composer audit` and
`pip-audit` before a release; `.github/workflows/laravel.yml` and `python.yml` are where to
add them as blocking steps.

`infrastructure/nginx/default.conf` is mounted read-only, and the Laravel container runs as a
non-root user with `storage/` and `bootstrap/cache` chowned to `www-data`. In compose,
`./application` is bind-mounted over `/var/www/html`, so anything writable in that tree on the
host is writable from a compromised container — keep it read-only in production or copy the
image content instead of mounting.

## 6. Audit and incident response

`audit_logs` is append-only and Laravel-owned. It records the actor (the user's e-mail, or
`system`), the action, the resource and its id, the request IP, and a JSON detail blob, for
login, logout, upload, mapping, quality, commit, assistant turns, training and promotion. See
`administrator.md` §6 for the full action list and ready-to-run compliance queries. Review it
monthly and on every leaver.

There is no separate audit trail inside the engine: its own `audit_logs` table is migrated but
never written, and `ai_conversations`/`ai_messages` store the assistant transcript without an
actor. Attribute AI work through the Laravel rows.

On a suspected leak, in order: rotate `SERVICE_API_KEY` (root `.env`, then
`docker compose up -d laravel fastapi celery-worker celery-beat`), rotate the LLM keys,
deactivate the affected user, then investigate `audit_logs` and the container logs. Preserve
`docker compose logs` output before restarting anything — it is the only record of the
failure.
