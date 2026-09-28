# Security

Threat model: internet-facing Nginx; Laravel + FastAPI must never trust the browser for
service calls. PII lives in `warehouse`/`ai.embeddings` — guard accordingly.

## 1. AuthN/Z

- Users: Laravel Sanctum bearer tokens; roles `admin/analyst/viewer` enforced server-side
  (seeded demo creds in `README.md` — rotate on first prod boot, see `administrator.md`).
- Service-to-service: `X-Service-Key: $SERVICE_API_KEY` on every Laravel → FastAPI call
  AND on Nginx `/ai-api/` if exposed publicly (deny-by-default; prefer internal-only).
  Rotate per environment: `python -c "import secrets; print(secrets.token_urlsafe(48))"`.
- Grafana: `GF_USERS_ALLOW_SIGN_UP=false`; strong `GRAFANA_ADMIN_PASSWORD`.

## 2. Secrets

- All secrets via `.env` → `${VAR}` in compose. Never commit `.env`, `*.pem`, `*.key`
  (`.gitignore` covers). No defaults for `POSTGRES_PASSWORD`, `SERVICE_API_KEY`,
  `LLM_API_KEY` in prod. `APP_KEY` via `php artisan key:generate --show`.
- LLM keys stay server-side (FastAPI env only); never ship to Blade/JS.

## 3. Transport + headers

- Prod TLS via Certbot (see `deployment.md`); then enable HSTS in `default.conf`.
  Nginx already sends `X-Frame-Options`, `X-Content-Type-Options`, XSS + Referrer-Policy.
- Postgres/Redis `ports:` are for ops convenience — in hardened prod bind to
  `127.0.0.1` or drop publishing; UFW allows only 22/80/443.

## 4. Data protection

- Upload cap `MAX_UPLOAD_MB=500` + `client_max_body_size 500M` prevents disk-fill DoS;
  validate `ALLOWED_EXTENSIONS` + MIME sniff in Laravel before FastAPI ingest.
- Quality gate `QUALITY_THRESHOLD=0.75` quarantines bad data (`QUALITY_FAIL_ACTION`);
  quarantine tables are analyst-only.
- Backups (`backup.sh`) are gz dumps — encrypt at rest (S3-SSE or encrypted volume) and
  restrict `backups/` perms; test restores quarterly (`backup-restore.md`).

## 5. Supply chain / CI

- Pinned base images (`php:8.3-fpm-alpine`, `redis:7-alpine`, `prom/prometheus:v2.53.0`,
  `grafana:11.2.0`); `docker.yml` includes Trivy/Hadolint hints (allow-failure until enforced).
- Dependabot/Renovate recommended; `composer audit` + `pip audit` in CI before release.

## 6. Audit

All AI actions log `actor, dataset_id, job_id, model, timestamp` (Laravel audit table +
FastAPI structured logs). Admin reviews via `administrator.md` runbook. Report suspected
leaks: revoke `SERVICE_API_KEY` + `LLM_API_KEY` first, then investigate.
