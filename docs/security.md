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
`SERVICE_API_KEY_HEADER`, and the engine reads the same variable through
`app/core/config.py::service_api_key_header` and resolves it with
`ai-engine/app/core/security.py::resolve_service_key_header`. Compose injects the one
root-`.env` value into `laravel`, `laravel-queue`, `laravel-schedule`, `fastapi`,
`celery-worker` and `celery-beat`, so the two sides cannot drift apart. Keep it at
`X-Service-Key`: the engine falls back to that name whenever the configured one is not a
valid RFC 7230 token or collides with a load-bearing header (`Authorization`, `Cookie`,
`Host`, `X-Request-ID`, …), so a malformed value desynchronises the pair rather than
failing loudly.

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

What you must still get right is the *key itself*: it has to be non-empty and real. Compose
injects one root-`.env` value into all six services, so a mismatch is unlikely; a placeholder
or a stale value is not.

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models                        # 401
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models -H "X-Service-Key: wrong" # 401
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8001/api/v1/models -H "X-Service-Key: $SERVICE_API_KEY" # 200
```

`GET /api/v1/health` is deliberately unauthenticated and answers `200` regardless, so a green
health check proves nothing about key configuration. `GET /api/v1/readiness` and
`GET /api/v1/liveness` likewise. `platform:doctor` closes that gap with an `engine_auth` check
that makes one authenticated round trip to `GET /api/v1/models` and reports the mismatch by
name.

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
prefix stripped, but the `location` block clears `X-Service-Key` before proxying, so no caller
can present the credential through it: every business route answers `401` and only the
unauthenticated routes (`/api/v1/health`, `/api/v1/readiness`, `/api/v1/liveness`, `/docs`,
`/redoc`, `/openapi.json`) answer at all. `/ai-api/metrics` gets a hard `404` from nginx
without reaching the engine, because proxying it would make the peer the nginx container —
which is on the internal network — and hand the full scrape to the internet. That is
intentional; keep both directives, and if you want `/ai-api/` gone entirely, remove the
`location` block. In Docker, `docker-compose.yml` publishes `fastapi` on host port 8001 and
Postgres on 5432 and Redis on 6379 — the published engine port is the one real exposure,
because it bypasses Nginx; bind those to `127.0.0.1` or drop the `ports:` entries so they
are reachable only from the host and the compose network. UFW allows 22, 80 and 443.

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

**Authorization.** `App\Http\Middleware\EnsureRole` backs the `role:` middleware used in
`routes/web.php` and `routes/api.php`. It resolves each role string with
`UserRole::tryFrom()` and refuses the whole route with `403` when any entry is unrecognised,
rather than treating it as `viewer` — a typo such as `role:admin,analist` must not widen the
gate. An inactive account is refused before the role comparison, and an unauthenticated JSON
request is answered `401` with `Unauthenticated.` rather than a redirect.

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
`node:22-alpine` (frontend assets stage, not in the runtime image), `python:3.13-slim`.
Laravel requires `php ^8.2` and `laravel/framework ^12.0`; the engine pins its Python
dependencies in `ai-engine/requirements.txt`. The frontend stage runs `npm ci` against the
committed `application/package-lock.json` and aborts the build if it is missing, so a
non-reproducible asset tree cannot reach an image. Run `composer audit` and `pip-audit` before
a release; `.github/workflows/laravel.yml` and `python.yml` are where to add them as blocking
steps.

`infrastructure/nginx/default.conf` is mounted read-only, and the Laravel container runs as a
non-root user with `storage/` and `bootstrap/cache` chowned to `www-data`. In compose,
`./application` is bind-mounted over `/var/www/html`, so anything writable in that tree on the
host is writable from a compromised container — keep it read-only in production or copy the
image content instead of mounting.

## 6. Audit and incident response

`audit_logs` is append-only and Laravel-owned. It records the actor (the user's e-mail, or
`system`), the action, the resource and its id, the request IP, and a JSON detail blob, for
login, logout, password changes, upload, mapping, quality, commit, assistant turns, user
administration, training and promotion. See `administrator.md` §6 for the full action list and
ready-to-run compliance queries. Review it monthly and on every leaver.

There is no separate audit trail inside the engine, and there cannot be one: `audit_logs` is
declared only by the Laravel migration, so the engine has no table to write to. `ai_conversations`
and `ai_messages` store the assistant transcript without an actor. Attribute AI work through
the Laravel rows.

On a suspected leak, in order: rotate `SERVICE_API_KEY` (root `.env`, then
`docker compose up -d laravel laravel-queue laravel-schedule fastapi celery-worker celery-beat`),
rotate the LLM keys, deactivate the affected user, then investigate `audit_logs` and the
container logs. Preserve `docker compose logs` output before restarting anything — it is the
only record of the failure.

## 7. Enterprise hardening pass (A8)

### 7.1 Permission matrix

Role gates enforced today by `EnsureRole` + `EnsureAccountActive` on every
authed route (pinned by `SecurityEnterpriseTest`, `RoleMiddlewareTest`,
`SecurityHardeningTest`):

| Capability | admin | analyst | viewer | inactive / guest |
|---|---|---|---|---|
| Read pages + token API (`datasets.index/show`, `dashboard`, `api.me`, …) | yes | yes | yes | no (403 `account_inactive` / redirect) |
| Write datasets (`store`, `preview/mapping/quality/commit`, `destroy`) | yes | yes | no (403 `forbidden`) | no |
| Assistant threads (`assistant.*`, scoped to own) | own only | own only | own only | no |
| `POST /api/agent/chat`, `POST /api/rag/query` (any active account) | yes | yes | yes | no |
| Train models (`ml.train`) | yes | yes | no | no |
| Promote models (`ml.promote`) | yes | no | no | no |
| Manage users / read audit log (`admin.*`, `audit.index`) | yes | no | no | no |

Row-level ownership is stricter than the role gate and is NOT yet enforced
(see A8-03 below). The intended policy, defined in
`application/app/Policies/DatasetPolicy.php` (owner-or-admin for
write/delete, global read) and
`application/app/Policies/ChatThreadPolicy.php` (strictly owner-only —
mirrors the existing `abort_unless(..., 404)` in `AssistantController`, so
even admins get 404 on another account's thread), plus the pure
`User::canDo(action, resource)` helper that restates the matrix without new
grants. Master wiring (A8 must not edit the shared files):

```php
// app/Providers/AppServiceProvider.php, inside boot():
Gate::policy(\App\Models\Dataset::class, \App\Policies\DatasetPolicy::class);
Gate::policy(\App\Models\ChatThread::class, \App\Policies\ChatThreadPolicy::class);

// bootstrap/app.php, inside ->withMiddleware(...), LAST so error pages keep them:
$middleware->append(\App\Http\Middleware\SecurityHeaders::class);
```

### 7.2 Upload security

The web upload (`DatasetController@store`, A8-owned) now enforces, in order:
(1) `max:` in **kilobytes** (`MAX_UPLOAD_MB × 1024` — the old spelling passed
the raw MB value, capping uploads at ~500 KB instead of 500 MB; fixed),
extension allowlist, and a filename screen rejecting path components
(`/`, `\`, NUL, controls, leading dots) and double extensions with an
executable middle (`sales.php.csv`); (2) an explicit byte-size re-check
before the synchronous engine round trip; (3) post-create re-sanitisation of
`source_filename`/`name` (no-op for normal names). Rejections are 422/session
errors and never reach the engine (`Http::assertNothingSent`).

### 7.3 Response headers

`App\Http\Middleware\SecurityHeaders` sets `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`
and a restrictive `Permissions-Policy`; `Strict-Transport-Security` only when
the request itself is HTTPS, so plain-HTTP dev/test never pins HSTS for
localhost. Mirrors `infrastructure/nginx/default.conf` (read-only for A8),
which additionally keeps HSTS commented until TLS is terminated.

### 7.4 PII masking

`App\Support\PiiMask` (`maskEmail` keeps the domain, `maskPhone` keeps the
last two digits, `maskText`/`maskArray` for free text and structured rows) is
dependency-free by design. Adopted today in exactly one safe place: the
`EnsureRole` denial log masks the actor email. Wider adoption (audit `detail`
blobs, CSV exports, assistant evidence) is left to the owning agents.

### 7.5 Secret management (no real values below)

Unchanged: everything sensitive comes from the root `.env`; placeholders
(`change-me*`, `changeme`, `secret`, empty) are treated as *not configured*
and every engine call fails closed with 401. Every engine auth rejection now
also emits a structured `auth.failed` log (`reason=unconfigured|missing|invalid`,
`header=`, `has_key=`, `has_bearer=`) that never contains a secret value
(pinned by `ai-engine/tests/test_security_enterprise.py`).

### 7.6 Audit findings (A8)

| ID | Severity | Location | Finding | Status |
|---|---|---|---|---|
| A8-01 | High | `application/app/Http/Controllers/DatasetController.php:58` + `Api/DatasetController.php:57` | `max:` got the raw MB value (KB semantics) → effective cap ~500 KB, contradicting the documented 500 MB | **Fixed** (web, A8-owned). API mirror identical — flagged for master (A8-02 also covers it) |
| A8-02 | High | `application/app/Http/Controllers/Api/DatasetController.php@store` (read-only for A8) | No double-extension / traversal filename screen; `sales.php.csv` accepted (201). Pinned as known-gap test | **Open** — port `DatasetController::unsafeFilenameReason()` + helpers into the API controller |
| A8-03 | High | dataset controllers (web + API) | No per-row ownership check: any `analyst` can mutate/delete any dataset; any active user can read all | **Open** — policies defined (`Policies/*`); master must `$this->authorize()` them in mutating actions |
| A8-04 | Medium | `ai-engine/app/api/v1/models.py:90` (A5-owned) | Engine `GET /models` decorates versions with server-side `artifact_path`; Laravel strips it but direct engine callers see filesystem layout | **Open** — strip server-side or gate the route; Laravel already `Arr::except`s it |
| A8-05 | Medium | `application/app/Models/Dataset.php:42` | `$fillable` is deliberately wide (server-owned columns mass-assignable); safe today only because no client key reaches `create()/update()` | **Accepted risk** — documented in the model; tightening needs service+seeder+factory conversion together |
| A8-06 | Low | `application/app/Models/User.php:56` | `role()` falls back to `Viewer` on corrupt values (fail-open to viewer reads) | **Accepted risk** — behaviour frozen per task; `EnsureRole` strictness + `EnsureAccountActive` bound the blast radius |
| A8-07 | Low | `application/config/sanctum.php:53` | `expiration => null`; expiry relies on per-token `expires_at` written at issue (`token_ttl_days`) | **Accepted** — all tokens are issued with expiry; no path mints non-expiring tokens |

Fixed in this pass: A8-01 (web); `EnsureRole`/`EnsureAccountActive` denial
audit logging (responses unchanged); `SecurityHeaders` (awaits master
wiring); engine `auth.failed` audit hook (no secret values);
`DatasetController` filename + size hardening; `PiiMask` + `User::canDo()`
pure helpers (no behaviour change; account lockout deliberately NOT added —
the 5/min login throttle already bounds guessing and lockout risked
`AuthTest` regressions).

Login/session verified (read-only, no change): web login regenerates the
session, logout invalidates + rotates CSRF, `last_login_at` + audit rows
written, deactivated refused on both form and token paths, `throttle:login`
on both, token TTL read at issue time.

### 7.7 Residual risks (for A9 / operators)

- Published host ports (`fastapi` 8001, Postgres, Redis) bypass Nginx
  entirely — bind to `127.0.0.1` or drop `ports:` outside dev.
- No TLS in the Nginx file yet (correct — HSTS stays commented until
  certbot has written real certs); internal compose traffic is plain HTTP.
- Laravel hop forwards the caller `Host` (needed for absolute URLs); add the
  documented `server_name` + `return 444` allowlist once the deployment has a
  real name.
- No per-dataset ACL / row-level security: the warehouse is single-tenant;
  `viewer` chat reads committed sales data from every dataset.
- Engine has no separate audit table by design — attribute AI work through
  Laravel `audit_logs` + the new `auth.failed` engine log.
