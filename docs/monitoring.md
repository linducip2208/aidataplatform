# Monitoring

Stack: the engine's Prometheus text at `GET /metrics` → Prometheus on `:9090` → Grafana on
`:3000`. Laravel has no exporter in this build, so the platform-level check is
`php artisan platform:doctor` and the container healthchecks. See `architecture.md` §6.

## 1. What is scraped

`infrastructure/monitoring/prometheus.yml` defines two active jobs:

| Job | Target | Interval | State in this build |
|---|---|---|---|
| `fastapi` | `fastapi:8000/metrics` | 15s | UP — the engine serves `/metrics` |
| `prometheus` | `localhost:9090` | 15s | UP |

The global `scrape_interval` is a literal `15s`: Prometheus does no shell-style
`${VAR:-default}` substitution, and such a string is not a valid `model.Duration`, so the
process would refuse to start. Retention is the one value Compose interpolates, as
`--storage.tsdb.retention.time=${PROMETHEUS_RETENTION:-15d}`.

Three further blocks are present but commented out, so they are not registered as targets at
all rather than sitting DOWN: `laravel` (the application exposes no `/metrics` route),
`redis` (`redis-exporter:9121`) and `postgres` (`postgres-exporter:9187`). To enable the
exporters, uncomment the block and start the container on the compose network:

```bash
docker run -d --network aidata-appnet --name redis-exporter \
  -e REDIS_ADDR=redis://redis:6379 oliver006/redis_exporter

docker run -d --network aidata-appnet --name postgres-exporter \
  -e DATA_SOURCE_NAME="postgresql://aidata:${POSTGRES_PASSWORD}@postgres:5432/aidata?sslmode=disable" \
  prometheuscommunity/postgres-exporter
```

`redis-exporter` needs the password in `REDIS_ADDR` once `REDIS_PASSWORD` is set. To clear
`laravel`, add a Prometheus client library in `application/` and register a `/metrics` route.

Alert rules live in `infrastructure/monitoring/alert-rules.yml`, wired via `rule_files` in
`prometheus.yml` and mounted read-only by `docker-compose.yml`. Three rules: `EngineErrorRate`
(5xx share > 1 % for 5 min, the same expression as the dashboard's panel 13 and the §7 SLO),
`EngineReadinessDown` (`up{job="fastapi"} == 0` for 2 min — the scrape proxy for readiness, since
true readiness is a response body, not a metric), and `DiskSpaceLow` (root filesystem < 15 % free
for 10 min, pending the `node_exporter` target, which is not in the base stack). After editing the
file, reload without restart: `curl -X POST http://localhost:9090/-/reload` (the compose command
enables `--web.enable-lifecycle`). See `operations.md` for what each alert means on-call.

## 2. The metrics that exist

`ai-engine/app/main.py` declares exactly two, and nothing else in the engine emits metrics:

| Metric | Type | Labels | Use |
|---|---|---|---|
| `http_requests_total` | Counter | `method`, `path`, `status` | Request rate and error ratio |
| `http_request_latency_seconds` | Histogram | `path` | Latency; percentiles from the `_bucket` series |

`/metrics` is gated: `METRICS_ENABLED=false` removes the route entirely, and while
`METRICS_ALLOW_PUBLIC` is `false` (the default) only internal peers are served — a public client
gets `404` rather than `403`, so the scrape path is not advertised to whoever is scanning for it.
The check is on the socket peer address, not a header, so it cannot be spoofed. Prometheus
scrapes over the compose network, which is internal, so the default configuration works — but a
browser or a curl from the host sees the rejection, which is the intended behaviour.

The health, readiness, liveness, metrics and schema routes are also exempt from the engine's
rate limiter (`_UNRATE_LIMITED_PATHS` in `app/main.py`), so a probe can never be answered with
`429` and take a container out of rotation.

Because `http_request_latency_seconds` is a `Histogram`, p95 is
`histogram_quantile(0.95, sum(rate(http_request_latency_seconds_bucket[5m])) by (le, path))`
— that is exactly what the Grafana dashboard queries (panels 1 and 4). The name
`http_request_duration_seconds_bucket` does not exist anywhere in this tree; if a panel ever
references it, the panel is wrong, not the engine.

Practical queries:

```promql
# request rate by status class
sum(rate(http_requests_total[5m])) by (status)

# 5xx ratio — the page when it moves
sum(rate(http_requests_total{status=~"5.."}[5m])) / sum(rate(http_requests_total[5m]))

# p95 latency on the ingestion endpoints
histogram_quantile(0.95, sum(rate(http_request_latency_seconds_bucket{path=~"/api/v1/imports/.*"}[5m])) by (le))

# 401 spike: the key is wrong on one side
sum(rate(http_requests_total{status="401"}[5m])) by (path)
```

The `path` label is the request path as written, so query parameters are not part of the
label. Import jobs, ML jobs, quality scores and RAG queries have no metric in this build;
derive them from the database instead:

```sql
SELECT status, count(*) FROM import_jobs GROUP BY status;
SELECT count(*) FROM datasets WHERE status IN ('importing','quarantined','failed');
SELECT count(*) FROM data_quality_reports WHERE created_at > now() - interval '24 hours';
```

## 3. Container health, the part that actually pages

| Service | Probe | Consequence of failing |
|---|---|---|
| `postgres` | `pg_isready` | Everything depending on it blocks |
| `redis` | `redis-cli ping`, with `-a "$REDIS_PASSWORD"` when one is set | `laravel`, `laravel-queue`, `laravel-schedule`, `fastapi` and the Celery containers block on `service_healthy` |
| `laravel` | `GET /up` **and** `public/build/manifest.json` exists **and** `storage/framework/migrate_failed` does not | `nginx`, `laravel-queue` and `laravel-schedule` block |
| `laravel-queue` | process probe: `ps aux \| grep -q '[q]ueue:work'` (BusyBox `ps` is guaranteed in the PHP image) | nothing blocks on it; unhealthy means the datasets/default consumer is gone |
| `laravel-schedule` | process probe: `ps aux \| grep -q '[s]chedule:work'` | nothing blocks on it; unhealthy means the per-minute schedule loop died |
| `fastapi` | `GET /api/v1/health` | `nginx`, `celery-worker` block |
| `celery-worker` | process probe: `/proc` scan for `celery` via the image's own `python` (`celery inspect ping` rejected — needs the broker on every probe) | `celery-beat` uses `service_started`, so nothing blocks; unhealthy means no queue is consumed |
| `celery-beat` | process probe: `/proc` scan for `beat` (a worker process cannot satisfy it) | nothing blocks; unhealthy means the nightly sync, hourly report and per-minute alert evaluation stop being scheduled |
| `nginx` | `GET /health`, answered locally without touching either upstream | — |
| `prometheus`, `grafana` | none | — (no `healthcheck:` block in `docker-compose.yml`; the `laravel.Dockerfile` `HEALTHCHECK` only applies to the three `laravel*` services, and Compose overrides it) |

The `laravel` probe is deliberately three-part. `/up` alone proves only that the framework
booted; the Vite manifest check catches an image built without a working assets stage, and the
`migrate_failed` marker — written by `laravel-entrypoint.sh` when `artisan migrate --force`
fails — keeps a broken schema from reporting healthy. A failed migration leaves the container
up so the logs can be read; it reports `unhealthy` instead.

Setting `REDIS_PASSWORD` in the root `.env` is safe: the Compose healthcheck authenticates when
the variable is non-empty, and so does `infrastructure/scripts/healthcheck.sh`.

## 4. Platform doctor

`php artisan platform:doctor` is the platform-level health report — read-only, no migrations
and no writes, so it is safe to run on demand and safe to schedule.

```bash
docker compose exec laravel php artisan platform:doctor
docker compose exec laravel php artisan platform:doctor --json     # for a monitor
```

It groups seventeen checks into configuration, ai engine, database and filesystem, prints a
remedy line for every non-pass, and exits non-zero if anything failed:

- **configuration** (7) — `app_key`, `app_env`, `app_debug`, `engine_url`, `service_key`,
  `max_upload_mb` (cross-checked against PHP's `post_max_size` and `upload_max_filesize`),
  `quality_threshold`.
- **ai engine** (3) — `engine_health` (`GET /api/v1/health`), `engine_readiness`
  (`GET /api/v1/readiness`, which must be read as a body because it answers `200` even when a
  dependency is down) and `engine_auth`, an authenticated round trip to `GET /api/v1/models`.
  A `401` or `403` from any of them is reported explicitly as a `SERVICE_API_KEY` mismatch with
  the exact remedy, and the key is redacted from the message. `engine_auth` exists because the
  engine's own health and readiness routes carry no auth dependency, so a key mismatch is
  invisible there.
- **database** (5) — `database`, `database_extensions` (`vector` and `pg_trgm`), `engine_tables`
  (the 27 Alembic tables), `laravel_tables` (the 12 Laravel tables) and `records` (row counts for
  `users` and `datasets`).
- **filesystem** (2) — `storage`, that `storage/`, `storage/framework`, `storage/logs` and
  `storage/app/private` exist and are writable by the current user, and `datasets_disk`, that
  every disk named in `datasets.disk` plus the default disk exists, is writable, and is defined
  in `config/filesystems.php`.

The extension and engine-table checks are skipped with a `warn` on a non-Postgres connection,
because the engine schema only ever lives in Postgres. Overall status is the worst component
status. Run it after every deploy and after any `.env` change; it catches the majority of
misconfigurations before a user does.

## 5. Grafana

Login with `GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD` at `http://localhost:3000`. Compose
provisions two things inline as `configs:` entries, so nothing has to be imported by hand: the
Prometheus datasource (named `Prometheus`, marked default) and a file-based dashboard provider
pointing at `/etc/grafana/provisioning/dashboards`. The dashboard JSON itself is a normal read-only
bind mount, so it can be edited without touching `docker-compose.yml`. It appears on first start
under the **AIDataPlatform** folder and refreshes every 30 s; use Grafana → Dashboards →
AIDataPlatform to open it.

The dashboard's fourteen panels are all written against metrics this build actually emits —
the inventory in the dashboard description is the contract (`http_requests_total`,
`http_request_latency_seconds_bucket/_sum/_count`, the `prometheus_client` default registry,
`up`). Panels 13–14 were added with the alert rules: 13 is the 5xx share the `EngineErrorRate`
alert fires on (the §7 SLO as a live tile, with 1 %/5 % thresholds), 14 is open/max file
descriptors from the same default registry as the CPU/RSS panel. Per-endpoint latency p95 is
panel 4, error rate is panels 6 (rate) and 13 (share).

Queue depth has no panel and must never gain one until the metric exists: Celery exposes no
Prometheus metric by default and no exporter in this stack publishes Redis `llen`. Watch it with
`docker compose exec redis redis-cli llen <queue>` for the queues you care about —
`imports`, `quality`, `ml`, `agent` and `rag` are the ones the engine routes to, and
`default` carries the nightly sync. The Laravel queue depth is the same command on the
`datasets` and `default` Redis keys.

## 6. Logs

```bash
docker compose logs -f <laravel|laravel-queue|laravel-schedule|fastapi|celery-worker|celery-beat|postgres|redis|nginx>
make logs                    # tails all services, last 200 lines each
```

Nginx writes to the `nginx-logs` volume (`aidata-access.log`, `aidata-error.log`).

Correlation: the engine's middleware reads or generates a request id and returns it as
`X-Request-ID` on every response, including failures. Send `X-Request-ID` yourself to pin it.
Laravel logs the operation name (`imports.upload`, `analytics.kpi`, …) on engine failures as
`ai_engine.unreachable`, `ai_engine.http_error` or `ai_engine.failed`. A Laravel `502` with
`code: ai_engine_error` plus an engine `401` on the same path is the service-key mismatch
signature.

Laravel's own log channel is `single` at `LOG_LEVEL=info` in `application/.env.example`, so
validation failures are not recorded. Raise `LOG_LEVEL` to `debug` on a staging host when
chasing a request, not in production.

## 7. Starting SLOs

Not instrumented anywhere — track them from the metrics and the SQL above.

| Signal | Target | Source |
|---|---|---|
| Laravel `/up` and engine `/api/v1/health` success | > 99.5% over 5 min | container healthchecks; `http_requests_total` for the engine |
| Engine 5xx ratio | < 0.5% | `sum(rate(http_requests_total{status=~"5.."}[5m])) / sum(rate(http_requests_total[5m]))` |
| Ingestion p95 latency | < 5 min for a CSV under 100 MB | `http_request_latency_seconds_bucket` on `/api/v1/imports/*` |
| Import jobs stuck in `queued` | 0 sustained | `SELECT count(*) FROM import_jobs WHERE status = 'queued'` |
| Datasets not `committed` | reviewed weekly | `SELECT status, count(*) FROM datasets GROUP BY status` |
| RAG answer quality | spot-check weekly | `POST /api/rag/query`; no metric exists |
| Open alerts | reviewed daily | `SELECT severity, count(*) FROM alerts WHERE status IN ('open','acknowledged') GROUP BY severity` |

Queue depth, model training duration and RAG query volume have no instrumentation in this
build. If you add metrics, emit them from `ai-engine/app/main.py` next to the existing
`Counter` and `Histogram` declarations, and update the Grafana dashboard JSON in the same PR.

## 8. Alerting (two systems — do not confuse them)

Prometheus alert rules (`infrastructure/monitoring/alert-rules.yml`) watch the PLATFORM:
`EngineErrorRate`, `EngineReadinessDown`, and the pending `DiskSpaceLow`. They page on-call
(see `operations.md`). What follows is the ENGINE's own business-metric alerting, which pages
nobody — it opens rows in the database.

`ai-engine/app/alerts/` implements threshold alerting on the engine, and it is the only
subscriber to the `alert-evaluation` beat entry. `celery-beat` runs it every minute
(`crontab(minute="*")` in `app/workers/celery_app.py`); the task is
`app.alerts.service.evaluate_alerts`, registered through
`include=["app.workers.tasks", "app.alerts.service"]`. It has no `TASK_ROUTES` entry, so it
lands on `task_default_queue`, which is `default` — inside the worker's `-Q` list.

A **rule** (`alert_rules`) is a name, a metric, an operator, a threshold and an `is_active`
flag. A **metric** is one of the thirteen in `app/alerts/rules.py`: `sales.revenue`,
`sales.orders`, `sales.units`, `sales.aov`, `sales.growth_pct`, `branch.revenue_max`,
`branch.count`, `inventory.stockout_count`, `inventory.dead_stock_count`,
`inventory.min_days_of_stock`, `finance.net_profit`, `finance.margin_pct`. `GET
/api/v1/alerts/metrics` returns the registry with each metric's unit, default severity and
the operators it accepts, so a client never has to hardcode the list.

Severity is a property of the metric (`low`/`medium`/`high`/`critical`) because
`alert_rules` has no severity column; it is copied onto `alerts.severity` when the alert
opens. The evaluation window is one day, also a constant rather than a column — `fact_sales`
is re-aggregated over the trailing day on every pass, which is idempotent for a daily-grain
metric.

The state machine keeps one row per rule: an open rule that keeps firing updates the same
`alerts` row instead of opening a second one, an acknowledgement does not resolve it, and a
rule that stops firing moves the row to `resolved`. Every transition appends a row to
`alert_events` (`fired`, `acknowledged`, `resolved`, `notified`). "One un-resolved alert per
rule" is an application invariant — there is no unique index for it — so the service also
takes a per-rule process lock before evaluating.

Delivery is a single optional webhook, `ALERT_WEBHOOK_URL`, off by default and with a 5 s
timeout. A failed delivery is recorded on the `alert_events` row as `notified` with a failure
detail and never aborts the evaluation, so an unreachable webhook cannot stop alerting.

The read surface is the engine's own API — `docs/api.md` §"Alerting" lists the routes.
`GET /api/v1/alerts` (also declared as `/api/v1/alerts/alerts`) is the list. Laravel proxies
none of them, so an operator works from Swagger or curl; there is no `/alerts` page in the
Blade UI, and `alert_events` is not surfaced in `platform:doctor` or the Grafana dashboard.
