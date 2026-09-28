# Monitoring

Stack: the engine's Prometheus text at `GET /metrics` → Prometheus on `:9090` → Grafana on
`:3000`. Laravel has no exporter in this build, so the platform-level check is
`php artisan platform:doctor` and the container healthchecks. See `architecture.md` §6.

## 1. What is scraped

`infrastructure/monitoring/prometheus.yml` defines five jobs:

| Job | Target | Interval | State in this build |
|---|---|---|---|
| `fastapi` | `fastapi:8000/metrics` | 15s | UP — the engine serves `/metrics` |
| `laravel` | `laravel:8000/metrics` | 30s | **DOWN by design** — Laravel exposes no `/metrics` route |
| `redis` | `redis-exporter:9121` | 15s | DOWN — the exporter is not in the compose file |
| `postgres` | `postgres-exporter:9187` | 30s | DOWN — the exporter is not in the compose file |
| `prometheus` | `localhost:9090` | default | UP |

Global `scrape_interval` is `${PROMETHEUS_SCRAPE_INTERVAL:-15s}` and retention is
`${PROMETHEUS_RETENTION:-15d}`, both from the root `.env`.

The three DOWN targets are expected and safe to ignore. To clear `laravel`, install a
Prometheus client library in `application/` and add a `/metrics` route; to clear the other two,
start the exporters on the compose network:

```bash
docker run -d --network aidata-appnet --name redis-exporter \
  -e REDIS_ADDR=redis://redis:6379 oliver006/redis_exporter

docker run -d --network aidata-appnet --name postgres-exporter \
  -e DATA_SOURCE_NAME="postgresql://aidata:${POSTGRES_PASSWORD}@postgres:5432/aidata?sslmode=disable" \
  prometheuscommunity/postgres-exporter
```

`redis-exporter` needs `REDIS_PASSWORD` in its address once that is set.

## 2. The metrics that exist

`ai-engine/app/main.py` declares exactly two, and nothing else in the engine emits metrics:

| Metric | Type | Labels | Use |
|---|---|---|---|
| `http_requests_total` | Counter | `method`, `path`, `status` | Request rate and error ratio |
| `http_request_latency_seconds` | Histogram | `path` | Latency; percentiles from the `_bucket` series |

`/metrics` is gated: `METRICS_ENABLED=false` removes the route, and while
`METRICS_ALLOW_PUBLIC` is `false` (the default) only internal peers are served. The check is
on the socket peer address, not a header, so it cannot be spoofed. Prometheus scrapes over the
compose network, which is internal, so the default configuration works — but a browser or a
curl from the host sees a rejection, which is the intended behaviour.

Because `http_request_latency_seconds` is a `Histogram`, p95 is
`histogram_quantile(0.95, sum(rate(http_request_latency_seconds_bucket[5m])) by (le, path))`
— the name `http_request_duration_seconds_bucket`, which the shipped Grafana dashboard JSON
was written against, does not exist.

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

Every service in `docker-compose.yml` has a healthcheck, and that is the primary signal:

| Service | Probe | Consequence of failing |
|---|---|---|
| `postgres` | `pg_isready` | Everything depending on it blocks |
| `redis` | `redis-cli ping` | `laravel`, `fastapi` and the Celery containers block on `service_healthy` |
| `laravel` | `GET /up`, falling back to `/health` | `nginx` blocks |
| `fastapi` | `GET /api/v1/health` | `nginx`, `celery-worker` block |
| `nginx` | `GET /health` | — |
| `prometheus`, `grafana` | their own HTTP probes | — |

Note the Redis trap: setting `REDIS_PASSWORD` in the root `.env` breaks its healthcheck,
because the probe runs `redis-cli ping` without `-a`. Redis then stays unhealthy and the stack
never finishes starting. Add `-a $REDIS_PASSWORD` to that healthcheck if you must set one.

## 4. Platform doctor

`php artisan platform:doctor` is the platform-level health report — read-only, no migrations
and no writes, so it is safe to run on demand and safe to schedule.

```bash
docker compose exec laravel php artisan platform:doctor
docker compose exec laravel php artisan platform:doctor --json     # for a monitor
```

It groups sixteen checks into configuration, ai engine, database and filesystem, prints a
remedy line for every non-pass, and exits non-zero if anything failed:

- **configuration** — `app_key`, `app_env`, `app_debug`, `engine_url`, `service_key`,
  `max_upload_mb` (cross-checked against PHP's `post_max_size` and `upload_max_filesize`),
  `quality_threshold`.
- **ai engine** — `engine_health` (`GET /api/v1/health`) and `engine_readiness`
  (`GET /api/v1/readiness`). A `401` or `403` from either is reported explicitly as a
  `SERVICE_API_KEY` mismatch with the exact remedy, and the key is redacted from the message.
- **database** — the connection, the `vector` and `pg_trgm` extensions, the 27 engine tables
  (Alembic's) and the 12 Laravel tables, and row counts for `users` and `datasets`.
- **filesystem** — that `storage/`, `storage/framework`, `storage/logs`,
  `storage/app/private` and the dataset disk roots exist and are writable by the current user.

Overall status is the worst component status. Run it after every deploy and after any `.env`
change; it catches the majority of misconfigurations before a user does.

## 5. Grafana

Login with `GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD` at `http://localhost:3000`. Compose
mounts `infrastructure/monitoring/grafana-dashboard.json` into
`/etc/grafana/provisioning/dashboards/`, but no dashboard *provider* is provisioned, so import
it by hand on first run: Dashboards → New → Import → upload the JSON → datasource Prometheus.

The dashboard's panels are written against metric names this build does not emit (it expects
`http_request_duration_seconds_bucket`, `celery_queue_length{queue}`, `import_jobs_total`,
`ml_jobs_total`, `quality_score`, `rag_queries_total`). Every one of those panels will show
"No data" until the corresponding metric exists. Two panels are worth editing to match reality:

- latency p95 → `histogram_quantile(0.95, sum(rate(http_request_latency_seconds_bucket[5m])) by (le, path))`
- request rate → `sum(rate(http_requests_total[5m])) by (path)`

Queue depth has no metric at all. Watch it with
`docker compose exec redis redis-cli llen <queue>` for the queues you care about —
`imports`, `quality`, `ml`, `agent` and `rag` are the ones the engine routes to, and
`default` carries the nightly sync.

## 6. Logs

```bash
docker compose logs -f <laravel|fastapi|celery-worker|celery-beat|postgres|redis|nginx>
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

Queue depth, model training duration and RAG query volume have no instrumentation in this
build. If you add metrics, emit them from `ai-engine/app/main.py` next to the existing
`Counter` and `Histogram` declarations, and update the Grafana dashboard JSON in the same PR.
