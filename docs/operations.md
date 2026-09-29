# Operations (runbooks, on-call, scaling, incident checklist)

AGENT 9 owner. Every command below runs on the production host (`/opt/aidataplatform`); every
file reference is relative to the repo root. Nothing here invents a metric, an endpoint, or a
procedure — the source of each claim is named so it can be checked with `grep`.

## 1. On-call: what pages, what does not

Prometheus evaluates `infrastructure/monitoring/alert-rules.yml` every 15 s (`rule_files` in
`prometheus.yml`). Three alerts, two of which can fire in the base stack:

| Alert | Fires when | Means | First command |
|---|---|---|---|
| `EngineErrorRate` (warning) | 5xx share > 1 % for 5 min | engine is throwing on real traffic | `docker compose logs --tail=50 fastapi` |
| `EngineReadinessDown` (critical) | `up{job="fastapi"} == 0` for 2 min | Prometheus cannot scrape the engine | §3.1 |
| `DiskSpaceLow` (warning) | rootfs < 15 % free for 10 min | **pending**: needs the `node_exporter` target, not in the base stack — never fires until that exporter is added | add exporter (§3.5), free space now |

What does NOT page, by design:

- **Queue depth.** Celery exposes no Prometheus metric and no exporter publishes Redis `llen`,
  so there is no `celery_queue_length` rule (a rule on a metric that does not exist pages "No
  data" forever). Watch stuck work manually: §3.3.
- **True readiness.** `/api/v1/readiness` answers 200 even when not ready; the `ready` field is
  a response body, not a metric. The scrape proxy (`EngineReadinessDown`) plus `healthcheck.sh`
  §5 plus `platform:doctor` are the three layers; no single one is the whole truth.
- **Laravel.** No exporter, no `/metrics` route — the `laravel` scrape job stays commented out
  until one exists. Platform-level signal is `php artisan platform:doctor` + the `/up`
  healthcheck.

Rule edits take effect with `curl -X POST http://localhost:9090/-/reload` (compose enables
`--web.enable-lifecycle`); no rebuild, no restart. Grafana mirrors the two firing-capable
alerts as panels 6/13 (error rate/share) and 8 (scrape up).

## 2. Service map (11 services)

`mysql:8.0`, `redis:7`, `laravel`, `laravel-queue`, `laravel-schedule`, `fastapi`,
`celery-worker`, `celery-beat`, `nginx`, `prometheus`, `grafana` — the eleven `services:` blocks
in `docker-compose.yml`, all on `appnet`, all `restart: unless-stopped`.

Dependency order that matters: `mysql`/`redis` healthy → `laravel`/`fastapi` healthy →
`nginx` + workers. `laravel-queue`/`laravel-schedule` additionally gate on `laravel` healthy
(schema migrated + storage skeleton present before the first job logs). The engine is
deliberately NOT a gate for `laravel-schedule` (scheduled commands preflight it themselves).
Beat gates on `celery-worker: service_started` only.

Health signals per service: `docs/monitoring.md` §3. Process probes (`laravel-queue`,
`laravel-schedule`, `celery-worker`, `celery-beat`) prove the process exists, not that it makes
progress — a wedged-but-alive worker is a queue-depth symptom (§3.3), never a healthcheck.

## 3. Runbooks

### 3.1 Engine down (`EngineReadinessDown`, or healthcheck §4–5 red)

1. `docker compose ps` — is `fastapi` restarting, unhealthy, or merely not ready?
2. `docker compose logs --tail=50 fastapi` — migration failure (`alembic upgrade head` runs at
   start; `AUTO_MIGRATE_STRICT=false` warns and starts anyway) vs `APP_ENV` rejection (unknown
   value raises at import) vs DB/Redis unreachable.
3. `bash infrastructure/scripts/healthcheck.sh` §§4–5: liveness passing + health failing =
   dependency down, not process down — check `mysql`/`redis` next, not the engine log.
4. `docker compose exec laravel php artisan platform:doctor` — the `engine_auth` check reports a
   `SERVICE_API_KEY` mismatch explicitly (engine health/readiness carry no auth, so a key
   mismatch is invisible there).
5. If the schema is behind the code: `make migrate` (`artisan migrate --force` + `alembic
   upgrade head`), then re-run healthcheck. Never edit the schema by hand to clear an alert.

### 3.2 5xx spike (`EngineErrorRate`, or Grafana panels 6/13 climbing)

1. Note the scope first: panel 2 (rate by status) + panel 3 (top paths) say whether one route or
   everything is failing. A 429 climb (panel 7) is the rate limiter, not an exception — one
   client outrunning `RATE_LIMIT_PER_MINUTE` or the limiter failing closed.
2. `docker compose logs --tail=100 fastapi | grep -i 'unhandled\|traceback'` — the engine logs
   full detail server-side and returns a redacted 500 (`X-Request-ID` on every response
   correlates the two sides).
3. Laravel side: a `502` with `code: ai_engine_error` plus an engine `401` on the same path is
   the service-key mismatch signature (`SERVICE_API_KEY` / `SERVICE_API_KEY_HEADER` drift).
   `platform:doctor` confirms it.
4. If the spike started at a deploy: roll back code first (`docs/deployment.md` §7), restore the
   database only if the schema changed under bad writes.

### 3.3 Stuck imports / quality / ML (queue depth growing, nothing failing)

Symptom: `import_jobs` rows sit in `queued`, datasets stay `importing`, no 5xx anywhere. This is
a routing problem until proven otherwise.

1. `docker compose ps` — are `celery-worker` and `laravel-queue` Up (and healthy per the process
   probes)? A restarting `laravel-schedule` is a real failure, not noise.
2. Queue subscription: `docker compose exec celery-worker celery -A
   app.workers.celery_app.celery_app inspect active_queues` (needs the broker; a failure here is
   itself signal). The worker must subscribe to `default,imports,quality,ml,agent,rag`
   (`CELERY_QUEUES`; `deploy-ubuntu24.sh` fatals on a subset). Routes live in
   `app/workers/celery_app.py` `TASK_ROUTES` + `QUEUES`.
3. Depth per queue (no metric exists — this is the manual watch):
   `docker compose exec redis redis-cli llen imports` (also `quality`, `ml`, `agent`, `rag`,
   `default`); Laravel side: `llen datasets`, `llen default`. A depth that never falls while the
   worker is healthy = wedged worker: `docker compose restart celery-worker` (or
   `laravel-queue`), then watch again.
4. Beat missed? The nightly sync (01:15), hourly report, and per-minute alert evaluation are the
   three entries in `celery_app.py`. A live beat that stopped scheduling shows nothing in its own
   healthcheck — compare `SELECT status, count(*) FROM import_jobs GROUP BY status` over time and
   `docker compose logs --tail=50 celery-beat`.
5. Throughput, not a fix for routing: `docker compose up -d --scale celery-worker=3` /
   `laravel-queue=3` only after steps 1–2 prove the queues are subscribed.

### 3.4 Scheduler / beat silent

`celery-beat` must run exactly once (`PersistentScheduler` with a local `/tmp/celerybeat.pid`;
`redbeat` is not installed — switching `CELERY_BEAT_SCHEDULER` crash-loops it). Never
`--scale celery-beat` past 1: two beats double-schedule the per-minute alert evaluation (the
state machine is idempotent per day-grain, but the duplicate `alert_events` rows are not free).
After any beat restart, confirm the next minute's `alert-evaluation` ran via the beat log, not
via the healthcheck.

### 3.5 Disk pressure (no paging rule in the base stack)

1. Immediate: `df -h /` on the host; `docker compose exec laravel du -sh
   /var/www/html/storage/app/datasets`; `docker compose exec fastapi du -sh /code/data/models`.
   The backup manifest records both sizes per run for trend comparison.
2. Retain-or-prune: `BACKUP_RETENTION_DAYS` (default 14) prunes dumps + manifests; volume
   tarballs from `make snapshot` prune on the same schedule — check `ls -lh backups/` before
   deleting anything by hand.
3. To get paging: run `node_exporter` on `appnet`, add the scrape job to `prometheus.yml`
   (uncomment pattern is documented there), confirm the target is UP, and the existing
   `DiskSpaceLow` rule starts evaluating. Do not lower the 15 % threshold to make a full disk
   "pass".

### 3.6 Failed deploy

`deploy-ubuntu24.sh` exits non-zero without tearing anything down — the previous version is still
serving. Read the step number: steps 1–5 are host preconditions (fix and re-run), step 7
`wait_for_stack` prints which of the eight healthchecked services never turned healthy (logs per
service follow), step 8 fails on `migrate`/`seed`/`healthcheck` in that order. Rollback is a
checkout plus rebuild (`docs/deployment.md` §7); restore the database only when the schema must
go back too, then `make migrate` + healthcheck + `tests/run.sh`.

### 3.7 Restore (database only — files are separate)

Full procedure is `docs/backup-restore.md` §3; the short form: `--verify-only` the archive first
(no side effects), stop the six writers (`laravel laravel-queue laravel-schedule fastapi
celery-worker celery-beat`), replay, `make migrate`, healthcheck, `platform:doctor`,
`tests/run.sh`. The safety dump path printed by `restore.sh` is the undo. Dataset files and ML
artefacts are NOT in the dump — restore the `make snapshot` tarballs into fresh volumes before
`up -d`, and expect `raw_uploads.stored_path` / `model_versions.artifact_path` to dangle until
then (re-upload / retrain per the disaster table).

## 4. Scaling

| Knob | What it does | Notes |
|---|---|---|
| `docker compose up -d --scale celery-worker=N` | N engine-task consumers | Same `-Q` list each; subscribe first (§3.3), scale second. Queue list change needs a recreate, not a scale. |
| `docker compose up -d --scale laravel-queue=N` | N Laravel consumers (`datasets`, `default`) | Same image/env/volumes; no build needed. |
| `CELERY_CONCURRENCY` (default 4) | threads per worker | Raise with RAM; ML tasks are memory-hungry. Recreate the worker after changing. |
| `PHP_CLI_SERVER_WORKERS` (default 4) | `php artisan serve` forks | Exported by the entrypoint; a value living only in `application/.env` is never seen. |
| `celery-beat` | exactly 1 | §3.4. |
| `mysql` / `redis` | exactly 1 each | No replication in this stack; scale vertically (deployment §1 sizing). |

No hard `mem_limit`/`cpus` guard any of this (compose notes explain why); watch Grafana panel 12
(one worker's CPU/RSS, not the container's — two-worker caveat) and panel 14 (FDs) plus host
`docker stats` instead.

## 5. Incident checklist (copy into the incident note)

- [ ] Alert name + first-fired time (Prometheus → Alerts, or Grafana panel screenshot).
- [ ] `docker compose ps` output saved (who was Up / restarting / unhealthy).
- [ ] Healthcheck result saved: `bash infrastructure/scripts/healthcheck.sh` (exit code + the
      FAIL lines, not just "red").
- [ ] Scope: one route or all (Grafana panels 2–4), one queue or all (`llen` per queue).
- [ ] Recent change: `git log --oneline -5`, last deploy time, last migration time.
- [ ] Action taken + time (restart / rollback / restore incl. safety-dump path / scale).
- [ ] Verify-out: healthcheck exit 0, `platform:doctor` exit 0, alert resolved in Prometheus.
- [ ] Follow-up: dashboard or rule change needed? Queue-depth watch added to the daily list?

## 6. Routine cadence

- **Daily:** glance at panels 6/13 (5xx), 8 (scrape up), 11 (path-cardinality — a climb without
  traffic growth is the raw-path-label defect, not growth); `llen` on the six engine queues when
  imports ran.
- **Weekly:** `apt upgrade` on the host; confirm the 02:00 backup cron produced a dump +
  manifest (`ls -lh $APP_DIR/backups/`); `--verify-only` the newest dump.
- **Quarterly:** full restore drill on a scratch host (`docs/backup-restore.md` §7) including the
  volume tarballs and the `admin@example.com` login — a green `platform:doctor` proves the
  database restored, not that the files did.
