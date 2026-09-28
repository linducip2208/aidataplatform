# Machine Learning

Training runs in the request handler, not on a queue: `POST /api/v1/training/train` fits the
model, writes a joblib artifact and returns. Laravel proxies it as `POST /api/ml/train` with
`202`. Registry tables are `ml_models`, `model_versions`, `training_runs`, `prediction_runs`
(see `data-dictionary.md`).

## 1. Train

Through Laravel, admin or analyst:

```bash
curl -s -X POST http://localhost:8080/api/ml/train \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"model_type":"churn","name":"churn-model","params":"{\"n_clusters\":4}"}'
```

`params` is a **JSON-encoded string**, not a nested object — `Api\MlController` validates it
as `string|max:4000` and `json_decode`s it, returning `422` with `errors.params` when it is
not valid JSON. The engine's `TrainRequest.params` is a real object; the string is a Laravel
API quirk. `POST /api/v1/training/train` takes the object directly.

```json
{"data": {"model_id": 3, "version_id": 7, "version": "v1",
          "metrics": {"accuracy": 0.91, "f1": 0.88}, "status": "VALIDATED"}}
```

`model_type` is one of `forecast`, `churn`, `segmentation`, `anomaly`, `recommend`. The
engine also accepts `segment` and `recommendation` as aliases, but Laravel's allowlist does
not, so use the canonical names through the platform.

`params` honoured per type, all optional:

| `model_type` | Params | What it does |
|---|---|---|
| `forecast` | `horizon` (default 30) | Fits `SalesForecaster`; reports `{n_obs, resid_std, method}` |
| `churn` | — | Trains and predicts in one pass; `metrics` is the classification report |
| `segmentation` | `n_clusters` (default 4) | KMeans; `metrics` from `segment()` |
| `anomaly` | `sensitivity` (default 2.5) | Runs `detect_anomalies` over the rows you pass |
| `recommend` | — | Stores up to the first 5000 rows as the artifact; `metrics: {n_transactions}` |

There is no `dataset_id` on the training contract, and no row cap, no cross-validation
default and no `ML_MAX_TRAIN_ROWS`. The only row source is the request body, and Laravel's
`POST /api/ml/train` never sends one — so training through the platform works on the
`recommend` and `anomaly` shapes and trains against empty input for the others. To train on
warehouse data, call the engine directly with a `dataset` array of row objects.

## 2. Registry and promotion

`ml_models` is keyed by a unique `name`; training an existing name adds a version to it
rather than creating a second model. `model_versions.version` is `v1`, `v2`, … per model, and
the artifact is written to `MODEL_PATH` as `model_{model_id}_{version}.joblib`.

Status values in the engine follow `DRAFT → TRAINING → VALIDATED → PRODUCTION → ARCHIVED`
(with `FAILED` as an extra terminal). A newly trained version is `VALIDATED`. Note that
Laravel's `Api\MlController` allowlists `to_status` of `PRODUCTION`, `STAGED`, `ARCHIVED` —
`STAGED` is not one of the engine's states, and `VALIDATED` is not one of the values Laravel
will accept. Promotion between the two sets only works for `PRODUCTION` and `ARCHIVED`.

Promotion is admin-only, so use an admin token:

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8080/api/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"Admin123!","device_name":"cli"}' | jq -r .data.token)

curl -s -X POST "http://localhost:8080/api/ml/models/3/promote" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"version_id":7,"to_status":"PRODUCTION"}'
```

Setting `to_status: "PRODUCTION"` also flips `ml_models.status` to `PRODUCTION` and stores
`version_id` in `ml_models.production_version_id`, which is what the churn prediction path
loads. Analyst and viewer get `403` with `code: forbidden`.

Read the registry with `GET /api/ml/models` (list) and `GET /api/ml/models/{modelId}`
(the model plus its `versions[]`).

## 3. Inference

Churn inference loads the model named `production_version_id` points at, via
`POST /api/v1/training/predict`:

```bash
curl -s -X POST http://localhost:8001/api/v1/training/predict \
  -H "X-Service-Key: $SERVICE_API_KEY" -H 'Content-Type: application/json' \
  -d '{"model_type":"churn","model_name":"churn-model","payload":{"customers":[{"tenure":12,"monthly":45}]}}'
```

`model_name` defaults to `churn-model`, and a missing production version returns
`{"success": false, "error": {"message": "no production churn model"}}`, which Laravel
surfaces as `422`. `model_type` also dispatches `forecast` (reads `payload.history` and
`payload.horizon`) and `anomaly` (reads `payload.series` and `payload.sensitivity`); anything
else returns an `unsupported model_type` error.

The stateless, single-call alternatives are the dedicated endpoints, each of which also
accepts inline data rather than reading the registry:

| Endpoint | Body | Response `data` |
|---|---|---|
| `POST /api/v1/forecast` | `{history:[{date,y}], horizon, granularity}` | `{forecast:[{date,yhat,yhat_lower,yhat_upper}], method, metrics}` |
| `POST /api/v1/customers/churn` | `{customers:[…]}` | `{predictions:[…], metrics}` |
| `POST /api/v1/customers/segment` | `{customers:[…], n_clusters}` | cluster assignment plus `metrics`; `n_clusters` is 2–10 |
| `POST /api/v1/anomaly/detect` | `{series:[…], sensitivity}` | flagged points; `sensitivity` is 0.5–6.0 |
| `POST /api/v1/recommend` | `{customer_id?, product_id?, top_k}` | scored items; `top_k` is 1–50 |

`prediction_runs` exists in the schema but nothing writes to it, so there is no inference
history to query.

## 4. Ops

- Artifacts live on the engine's `MODEL_PATH`. Compose sets `MODEL_PATH=/code/data/models`,
  which is exactly where the `models-cache` volume is mounted, so artifacts survive a
  container rebuild with no extra configuration. The engine's own default is `./models`
  relative to its `/code` working directory, so running it outside Compose without setting
  `MODEL_PATH` loses every artefact on a rebuild.
- A model that vanishes from disk still shows in the registry; `load_production` raises
  `OSError` out of `joblib.load` on the missing `artifact_path` rather than returning `None`.
  The `no production churn model` answer is for a model row with no version, not a missing
  file.
- Retraining a name never mutates an existing version. It appends, so the previous
  `PRODUCTION` pointer stays valid until you promote the new `version_id`.
- There is no GPU path, no `CELERY_TASK_TIME_LIMIT` and no training timeout beyond the
  request: Laravel gives the call `AI_ENGINE_LLM_TIMEOUT` (120 s) because `train()` reuses
  the LLM budget. Large inline datasets will hit that first.
- Long-running or recurring training belongs in a Celery task
  (`app/workers/tasks.py` already has `train_model` routed to the `ml` queue); the HTTP
  handler is a thin wrapper over the same `app/ml/training.py` function.
