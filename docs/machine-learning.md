# Machine Learning

Training is async on the `ml` queue; registry + governance in `ml.*` schema.
Entry: `POST /api/v1/ml/train`.

## 1. Train

```bash
curl -X POST http://fastapi:8000/api/v1/ml/train -H "X-Service-Key: $SERVICE_API_KEY" \
 -H 'Content-Type: application/json' \
 -d '{"dataset_id":"<uuid>","target":"churn","task":"classification","model":"auto","n_splits":5}'
# -> 202 {job_id, experiment_id, queue: ml}
curl http://fastapi:8000/api/v1/ml/jobs/<job_id> -H "X-Service-Key: $SERVICE_API_KEY"
# running -> succeeded {metrics:{accuracy|rmse,f1}, artifact_uri, model_id}
```

Guards: dataset must have quality verdict `pass` (quarantined → 422 unless admin
override); rows cap `ML_MAX_TRAIN_ROWS=500000` (sampled, seed logged);
`CELERY_TASK_TIME_LIMIT=1800`.

## 2. Pipeline (per experiment)

1. Feature snapshot from `warehouse` → `ml.features(dataset_id, snapshot_sql, created_at)`.
2. Train/validation split (`ML_DEFAULT_N_SPLITS=5` CV); `auto` tries lr/rf/xgb, picks best.
3. Metrics + params → `ml.experiments`; artifact (`.joblib`) → `ML_ARTIFACT_DIR`
   (`models-cache` volume) + row in `ml.registry(model_id, version, stage=draft)`.
4. Prometheus `ml_jobs_total{status}` increments (Grafana panel).

## 3. Registry + promotion

Stages: `draft → staged → production → archived`. Analyst trains (draft); admin
approves via `POST /api/v1/ml/models/{id}/approve` (see `model-governance.md`).
Only `production` models serve predictions; Laravel `POST /api/predict` proxies with
service key. Artifacts immutable per version; retrain creates new version, never mutates.

## 4. Inference

`POST /api/v1/ml/predict` `{"model_id","rows":[{...}]}` → `{"predictions":[...]}`.
Latency SLO p95 < 500 ms (single rows); batch via Celery for > 10 k rows.

## 5. Ops

- Queue depth: Grafana "Celery Queue Depth" (`ml` series); stuck `running` > 30 min →
  check `celery-worker` logs, revoke + retry (`CELERY_TASK_TIME_LIMIT` kills).
- Repro: experiment row stores seed, code hash, `EMBED`/feature snapshot id.
- GPU: default CPU; mount GPU by overriding worker with `--gpus` + XGBoost `tree_method=gpu_hist`.
