# Monitoring

Stack: FastAPI `/metrics` + (optional) Laravel `/metrics` → Prometheus `:9090` →
Grafana `:3000` with shipped dashboard.

## 1. What is scraped

`infrastructure/monitoring/prometheus.yml`: `fastapi:8000/metrics` (15 s),
`laravel:8000/metrics` (30 s — DOWN until exporter lib added in `application/`),
`redis-exporter:9121` + `postgres-exporter:9187` (both OPTIONAL, DOWN by default —
enable commands are commented in the yml). `prometheus` self-scrape included.
Retention `PROMETHEUS_RETENTION=15d`.

## 2. FastAPI metrics (names the dashboard expects)

`http_request_duration_seconds_bucket{route}` (latency p95 panel),
`celery_queue_length{queue}` (queue depth), `import_jobs_total{status}`,
`ml_jobs_total{status}`, `quality_score`, `rag_queries_total`. If a panel shows
"No data", the corresponding code path hasn't emitted yet — run an import/train/query.

## 3. Grafana

Login `admin` / `$GRAFANA_ADMIN_PASSWORD` at http://localhost:3000. Dashboard
`AIDataPlatform Overview` auto-provisioned from
`infrastructure/monitoring/grafana-dashboard.json` (panels: latency p95, queue depth,
import jobs, ML jobs, quality avg, RAG rate). Add alerts in Grafana (e.g. queue depth
> 100 for 10 min → webhook) or Prometheus rules file (mount next to yml).

## 4. Logs

`docker compose logs -f <laravel|fastapi|celery-worker|celery-beat|postgres|nginx>`
(`make logs` tails all). Request correlation via `X-Request-Id` header (logged both
sides). Nginx access/error in `nginx-logs` volume.

## 5. Minimal SLOs (starting point)

- `/up` + `/api/v1/health` success > 99.5% (5 min window).
- Ingest p95 < 5 min for ≤ 100 MB CSV; ML train bounded by `CELERY_TASK_TIME_LIMIT`.
- Queue depth `imports` < 50 sustained; alert otherwise (worker scale signal:
  `docker compose up -d --scale celery-worker=3`).

## 6. Laravel exporter (to clear the DOWN target)

Install `promphp/prometheus_client_php` (or equivalent) in `application/`, expose
`GET /metrics` (internal-only middleware), redeploy. Until then, ignore the red
`laravel` target — `fastapi` + container healthchecks cover paging.
