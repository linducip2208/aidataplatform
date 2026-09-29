# Administrator Guide

For platform administrators on production and staging. Assumes the stack is healthy:
`bash infrastructure/scripts/healthcheck.sh` should report zero failures.

## 1. Accounts and roles

`application/database/seeders/UserSeeder.php` creates three demo accounts. The seeder is
idempotent and keyed on the e-mail address: it only fills columns that are still `null` and
only sets the password when the account is created for the first time, so re-running it never
resets a password or re-activates a deactivated user.

| Role | Email | Password | Scope |
|---|---|---|---|
| `admin` | `admin@example.com` | `Admin123!` | Everything: model promotion, user management, audit log |
| `analyst` | `analyst@example.com` | `Analyst123!` | Upload datasets, run quality checks, train models, use the assistant |
| `viewer` | `viewer@example.com` | `Viewer123!` | Read-only dashboards and reports |

Roles are `App\Enums\UserRole`; permissions are enforced by
`App\Http\Middleware\EnsureRole` through the `role:` middleware on route groups in
`routes/web.php` and `routes/api.php`.

| Capability | admin | analyst | viewer |
|---|---|---|---|
| View dashboards, datasets, analytics, ML, reports | yes | yes | yes |
| Use the assistant (`/api/agent/chat`, `/api/rag/query`) | yes | yes | yes |
| Upload, map, commit, delete a dataset | yes | yes | no |
| Train a model (`POST /api/ml/train`) | yes | yes | no |
| Create and delete a chat thread | yes | yes | no |
| Promote a model version | yes | no | no |
| Manage users, read the audit log | yes | no | no |

A viewer attempting a write gets `403` with `code: forbidden` on the API and a plain 403 page
in the UI. A `viewer` calling the assistant still reaches every committed fact row, because
the agent's tools are not dataset-scoped — see `ai-agent.md` §3.

Rotate the demo passwords on day one and deactivate leavers immediately
(`is_active = false`; login then returns `422` and `EnsureRole` returns `403`).

## 2. User management

From the UI: **Admin → Users** (`/admin/users`, admin only) — list, create, update and delete.

From the shell:

```bash
docker compose exec laravel php artisan tinker
>>> $u = \App\Models\User::create([
...     'name' => 'Ops', 'email' => 'ops@example.com',
...     'password' => \Illuminate\Support\Facades\Hash::make('a-strong-password'),
...     'role' => 'admin',            // admin | analyst | viewer
...     'is_active' => true,
... ]);
>>> \App\Models\User::where('email', 'ops@example.com')->update(['is_active' => false]);
```

`role` is cast to the `UserRole` enum, so any value outside `admin|analyst|viewer` is
rejected on write. Deactivating is preferred over deleting: it preserves the `user_id` history
on `datasets` and `audit_logs` (`datasets.user_id` is `nullOnDelete`, so a real delete leaves
the dataset in place with no owner).

For SSO or LDAP, extend `application/config/auth.php` and the login flow. Do not hand-edit
vendored files.

## 3. Daily, weekly, monthly

Daily:

```bash
docker compose ps
docker compose exec laravel php artisan platform:doctor
ls -lh backups/            # the 02:00 cron should have added a dump
docker compose logs --tail=100 celery-worker laravel-queue laravel-schedule
```

`docker compose ps` should show eleven services Up: `mysql`, `redis`, `laravel`,
`laravel-queue`, `laravel-schedule`, `fastapi`, `celery-worker`, `celery-beat`, `nginx`,
`prometheus`, `grafana`. Only the first five plus `nginx` carry a healthcheck; the others are
covered by the restart policy, so a container that is repeatedly restarting is the signal for
those.

- Investigate `failed` datasets on the **Datasets** page: they are `failed` status, and the
  reason is in the `metadata.validation` payload or the engine's `import_jobs.report`.
- Reconcile anything stuck: `php artisan sync:import-status` moves local statuses to match
  the engine, `php artisan sync:quality` re-checks stale committed datasets. Both support
  `--dry-run`.

Weekly:

- `docker system df`; prune when the cache is over 70% (`docker image prune -f`).
- Review quarantined datasets on the **Quality** page and decide per dataset: fix the source
  and re-upload, or accept the data knowing it never reached the warehouse.
- Check the Grafana latency panel and the engine error rate.

Monthly:

- Rotate `SERVICE_API_KEY`. Change it in the root `.env` only — Compose injects the same value
  into `laravel`, `fastapi`, `celery-worker` and `celery-beat` — then
  `docker compose up -d laravel laravel-queue laravel-schedule fastapi celery-worker celery-beat`.
  Laravel and the engine disagreeing on the key shows up as `502` on every engine-backed page
  and `401` in the engine log.
- Restore drill to staging (`backup-restore.md`). A backup never restored is assumed broken.
- Review LLM provider spend.
- `VACUUM (ANALYZE)` the database after a month of bulk loads; nothing does it for you.

## 4. Governance

Model promotion is the one gated action. The engine's version status is
`DRAFT → TRAINING → VALIDATED → PRODUCTION → ARCHIVED` (plus `FAILED`); a new version starts
`VALIDATED`. Promote from the **ML** page or the API (admin only):

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8080/api/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"Admin123!","device_name":"cli"}' | jq -r .data.token)

curl -s -X POST "http://localhost:8080/api/ml/models/$MODEL_ID/promote" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"version_id":7,"to_status":"PRODUCTION"}'
```

`to_status` accepted by the platform: `PRODUCTION`, `STAGED`, `ARCHIVED`. `STAGED` is not one
of the engine's own states, so it stores without effect — use `VALIDATED` to hold a version
back (it needs a direct engine call) and `PRODUCTION` to promote. Setting `PRODUCTION` also
sets `ml_models.production_version_id`, which is what churn inference loads. Every train and
promote writes an `audit_logs` row (`model.trained`, `model.promoted`).

Data governance: a dataset whose quality verdict is `quarantine` never reaches the warehouse,
so it is absent from the assistant, the analytics and the training inputs. There is no
per-dataset threshold override in this build — the two global thresholds are
`QUALITY_THRESHOLD` (Laravel) and `QUALITY_MIN_SCORE` (engine); see `data-quality.md` §3.

## 5. Quotas, capacity, retention

| Limit | Where | Default |
|---|---|---|
| Upload size | `MAX_UPLOAD_MB` (Laravel) + `client_max_body_size` (Nginx) + PHP `upload_max_filesize`/`post_max_size` | 500 MB / 500M / 500M+550M |
| Accepted extensions | `config('ai_engine.allowed_extensions')` | `csv, xlsx, xls, json, parquet, zip, txt` |
| Engine request rate | `RATE_LIMIT_PER_MINUTE` per credential **and** 3× that per path, enforced in-process and fail-closed | 120 |
| `per_page` on list endpoints | clamped in `App\Support\ApiResponse` | 20, max 100 |
| `top_k` on RAG queries | `RagQueryRequest` | 5, max 20 |
| `top_k` on recommendations | `RecommendRequest` | 5, max 50 |

Nothing prunes data in this build. `celery-beat` runs three entries — `scheduled_data_sync`
at 01:15, which is a placeholder returning a status message; an hourly AI report; and the
per-minute alert evaluation — and `laravel-schedule` runs only `sync:import-status` and
`sync:quality`, which reconcile existing rows rather than deleting any. So `raw_uploads` rows
and their stored files, old `fact_*` rows, `rag_chunks`, `data_quality_reports`, `alerts` and
`audit_logs` all grow without limit. Plan a retention job before the warehouse outgrows the
disk, and keep `docker system df` and the `mysql-data` volume on the weekly review.

Space per dataset is roughly: the copy Laravel stores, the copy the engine stores, and the
`fact_*` rows the ETL produced. Those are three separate volumes' worth of growth for one
upload — check free disk before raising `MAX_UPLOAD_MB`.

## 6. Audit

`audit_logs(id, user_id, actor, action, resource, resource_id, ip, detail, created_at)` is
append-only — `App\Models\AuditLog` sets `UPDATED_AT = null`, so there is no update path. Read
it in the UI at `/audit` (admin only).

Actions written by the current code:

| Action | Resource | Written when |
|---|---|---|
| `auth.api_login`, `auth.api_logout` | `user` | `POST /api/login`, `POST /api/logout` |
| `auth.login`, `auth.logout` | `user` | the Blade `POST /login` and `POST /logout` |
| `auth.password_changed` | `user` | `PUT /profile/password` |
| `dataset.uploaded` | `dataset` | Upload completes, with `filename`, `size_bytes`, `import_job_id` |
| `dataset.mapping_applied` | `dataset` | Mappings saved, with the mapping and the template name |
| `dataset.quality_checked` | `dataset` | Quality run, with `score`, `verdict`, `threshold` |
| `dataset.committed` | `dataset` | Commit called, with `status` and `async` |
| `agent.chat` | `agent` | Assistant turn over the API, with `message_length` and `steps` |
| `assistant.chat` | `chat_thread` | Assistant turn started from the Blade UI |
| `model.trained` | `model` | Training, with `model_type`, `name`, `version` |
| `model.promoted` | `model` | Promotion, with `version_id`, `to_status` |
| `user.created`, `user.updated`, `user.deleted` | `user` | **Admin → Users**, with `role` |

Compliance extracts, all from `public`:

```sql
-- governance trail
SELECT created_at, actor, resource_id AS model_id, detail FROM audit_logs
WHERE action = 'model.promoted' ORDER BY created_at DESC;

-- data holds
SELECT uuid, name, quality_score, quality_verdict, quality_checked_at
FROM datasets WHERE quality_verdict = 'quarantine' ORDER BY created_at DESC;

-- ingestion history
SELECT created_at, actor, detail->>'import_job_id' AS job, detail->>'filename' AS file
FROM audit_logs WHERE action = 'dataset.uploaded' ORDER BY created_at DESC;
```

Export with `mysql --batch -e` or a CSV query. Note that `detail` also holds the column mappings
submitted for a dataset, so treat the extract as potentially sensitive.

Known gap: there is no `ml.approvals` table and no engine-side audit trail — `audit_logs` is
created only by the Laravel migration, so the engine has no table to write to and
`ai_conversations` / `ai_messages` keep the assistant transcript without an actor. `audit_logs`
is therefore the only trail; see `data-dictionary.md` §7 and back it up with the database dump.

## 7. Incidents

1. **Scope it.** `bash infrastructure/scripts/healthcheck.sh` covers Laravel `/up` plus
   `POST /api/login` and `GET /api/me`, the engine `/api/v1/health` and `/api/v1/readiness`,
   the Nginx `/ai-api/` prefix strip and `/health`, Postgres, Redis, and the two Celery
   containers. It does not cover `laravel-queue` or `laravel-schedule`; `docker compose ps` does.
2. **Read the logs.** `docker compose logs --tail=100 <service>`; the engine logs
   `X-Request-ID`, which also appears in the Laravel response, so one id ties the two sides
   together.
3. **Mitigate.** Scale workers (`docker compose up -d --scale celery-worker=3`,
   `... --scale laravel-queue=3`) for a backlog;
   revoke `SERVICE_API_KEY` and the LLM keys if a secret leaked; restore from a dump
   (`backup-restore.md`) for corruption.
4. **Record it.** A postmortem in the repository issue tracker, with the `X-Request-ID`s, the
   job ids and the dataset UUIDs involved.

First responder map: Laravel routes, auth, Blade and audit → the Laravel owner; parsing,
ETL, analytics, ML, agent and RAG → the engine owner; host, Docker, Nginx, TLS and backups →
infrastructure.

## 8. Upgrades

```bash
cd /opt/aidataplatform
bash infrastructure/scripts/backup.sh
git pull
docker compose up -d --build
make migrate
bash infrastructure/scripts/healthcheck.sh
bash tests/run.sh
```

Read the release notes and run `tests/run.sh` before and after. Pin base-image bumps (PHP,
Postgres, Redis, Prometheus, Grafana, Nginx) to a maintenance window and re-run the whole
checklist after each, since only `application/**` and `ai-engine/**` are covered by unit
tests. A `down -v` on MySQL destroys `mysql-data`, the database password and all data — never
use it in production; change the password with `ALTER USER` instead.
