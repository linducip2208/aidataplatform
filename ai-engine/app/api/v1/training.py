"""Training endpoints."""
from __future__ import annotations

from typing import Any, Dict, List

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_request_id, get_logger
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ml import (
    BatchPredictRequest,
    ExperimentCompareRequest,
    ExperimentCreate,
    ExperimentPromoteRequest,
    PredictRequest,
    TrainRequest,
)

log = get_logger("api.training", "train")

router = APIRouter(tags=["training"])

# The five model types `TrainRequest.model_type` documents, plus the aliases
# `app.ml.training` accepts. Anything else raises there as an unhandled
# ValueError, which is why the check is here rather than left to the fit.
MODEL_TYPES = frozenset({"forecast", "churn", "segmentation", "segment",
                         "anomaly", "recommend", "recommendation"})
# The trainable types that have an inference path in this router. `segmentation`
# and `recommend` are trainable but not predictable here.
PREDICTABLE_TYPES = frozenset({"forecast", "churn", "anomaly"})
MIN_HORIZON, MAX_HORIZON = 1, 365
MIN_SENSITIVITY, MAX_SENSITIVITY = 0.5, 6.0
# The fit is synchronous by contract (docs/architecture.md; `MlController::train`
# returns 202 but reads model_id/version_id straight out of this body), so the
# request thread is held for its duration and the cost has to be bounded here
# rather than by moving the work to a queue. Beyond this the request is refused
# instead of pinning a threadpool worker for an unbounded fit, which would
# starve every other endpoint. Matches the cap style in app/ai/rag.py.
MAX_DATASET_ROWS = 50_000


def _error(status_code: int, operation: str, message: str, *, code: str,
           error_type: str, resolution: str,
           details: Dict[str, Any] | None = None) -> JSONResponse:
    """A refusal as a real status inside the engine envelope.

    A 200 carrying `success: false` is read by `AiEngineClient::unwrap()` as a
    422, so "no model was trained" and "your payload is wrong" both arrived at
    the browser as the same validation error. The status has to be the truth.
    """
    return JSONResponse(
        status_code=status_code,
        content=build_error_response(
            module="training",
            operation=operation,
            error_type=error_type,
            code=code,
            message=message,
            request_id=get_request_id(),
            resolution=resolution,
            details=details or {},
        ),
    )


def _bad_request(operation: str, message: str, code: str, field: str) -> JSONResponse:
    return _error(422, operation, message, code=code, error_type="validation",
                  resolution="Periksa kembali payload, lalu ulangi.",
                  details={"field": field})


@router.post("/training/train")
def train(body: TrainRequest, db: Session = Depends(get_db),
          _: str = Depends(require_service_auth)):
    from app.ml.training import train_model

    model_type = str(body.model_type).strip().lower()
    if model_type not in MODEL_TYPES:
        return _bad_request(
            "train", f"unsupported model_type {model_type!r}; expected one of: "
            + ", ".join(sorted(MODEL_TYPES)),
            "UNSUPPORTED_MODEL_TYPE", "model_type")

    params: Dict[str, Any] = dict(body.params or {})
    dataset: List[Dict[str, Any]] = [dict(r) for r in (body.dataset or [])]
    if len(dataset) > MAX_DATASET_ROWS:
        return _error(413, "train",
                      f"dataset has {len(dataset)} rows; the maximum is {MAX_DATASET_ROWS}",
                      code="DATASET_TOO_LARGE", error_type="validation",
                      resolution="Kirim dataset yang lebih kecil, atau latih lewat worker.",
                      details={"field": "dataset", "max_rows": MAX_DATASET_ROWS})

    try:
        res = train_model(model_type, body.name, params, dataset, db)
    except ValueError as exc:
        # A param the fit itself rejects (e.g. n_clusters out of range).
        return _bad_request("train", str(exc), "INVALID_PARAMS", "params")
    except Exception as exc:  # noqa: BLE001 - the fit is third-party numeric code
        # An unexpected failure inside the fit is a server fault, not a
        # validation error. Log the detail (exc_info) and return the redacted
        # envelope: the caller must never see a traceback or a DSN.
        log.error("training fit failed: %s", type(exc).__name__, exc_info=exc)
        return JSONResponse(status_code=500, content=build_error_response(
            module="training", operation="train", error_type="internal",
            code="TRAINING_FAILED", message="Training failed.",
            request_id=get_request_id(),
            resolution="Periksa log server lalu ulangi.", internal=True))
    return {"success": True, "data": res}


@router.post("/training/predict")
def predict(body: PredictRequest, db: Session = Depends(get_db),
            _: str = Depends(require_service_auth)):
    mt = str(body.model_type or "").strip().lower()
    payload = dict(body.payload or {})

    if mt not in PREDICTABLE_TYPES:
        return _bad_request(
            "predict", f"model_type {mt!r} cannot be used for prediction; expected "
            "one of: " + ", ".join(sorted(PREDICTABLE_TYPES)),
            "UNSUPPORTED_MODEL_TYPE", "model_type")

    if mt == "forecast":
        from app.ml.forecasting import forecast

        hist = payload.get("history", [])
        if not isinstance(hist, list):
            return _bad_request("predict", "payload.history must be a list",
                                "INVALID_HISTORY", "history")
        h = _bounded(payload.get("horizon", 30), MIN_HORIZON, MAX_HORIZON,
                     "horizon", default=30)
        if isinstance(h, JSONResponse):
            return h
        return {"success": True, "data": forecast(hist, h)}

    if mt == "churn":
        from app.ml import registry as reg
        from app.ml.churn import predict_churn_proba

        artifact = reg.load_production(body.model_name or "churn-model", db)
        if not artifact:
            # No trained artifact is a missing resource. Left as a 200 with
            # success=false it became a 422 in Laravel, indistinguishable from
            # a malformed payload; this model has simply never been trained.
            return _error(404, "predict",
                          f"no production model for {body.model_name or 'churn-model'}",
                          code="NO_PRODUCTION_MODEL", error_type="not_found",
                          resolution="Train a model, lalu promote versinya ke PRODUCTION.")
        rows = payload.get("customers", [payload])
        if not isinstance(rows, list):
            return _bad_request("predict", "payload.customers must be a list",
                                "INVALID_CUSTOMERS", "customers")
        return {"success": True, "data": predict_churn_proba(
            artifact["model"], artifact["scaler"], rows)}

    from app.ml.anomaly import detect_anomalies

    series = payload.get("series", [])
    if not isinstance(series, list):
        return _bad_request("predict", "payload.series must be a list",
                            "INVALID_SERIES", "series")
    sens = _bounded(payload.get("sensitivity", 2.5), MIN_SENSITIVITY,
                    MAX_SENSITIVITY, "sensitivity", default=2.5)
    if isinstance(sens, JSONResponse):
        return sens
    return {"success": True, "data": detect_anomalies(series, sens)}


def _bounded(value: Any, low: float, high: float, field: str, *, default: float) -> Any:
    """Coerce `value` to a number within [low, high], or return a 422 envelope.

    `int()`/`float()` on an untrusted string raised an unhandled ValueError and
    a 500, and an out-of-range value reached the model unclamped. The bounds are
    the ones `ForecastRequest.horizon` and `AnomalyRequest.sensitivity` already
    declare in `app/schemas/ml.py`; this route reads them from the free-form
    `payload` dict, so the schema cannot enforce them.
    """
    if value is None:
        return default
    if isinstance(value, bool) or not isinstance(value, (int, float, str)):
        return _bad_request("predict", f"{field} must be a number",
                            f"INVALID_{field.upper()}", field)
    try:
        n = int(value) if float(value) == int(float(value)) else float(value)
    except (TypeError, ValueError):
        return _bad_request("predict", f"{field} must be a number, got {value!r}",
                            f"INVALID_{field.upper()}", field)
    if n < low or n > high:
        return _bad_request("predict",
                            f"{field} must be between {low} and {high}, got {n}",
                            f"INVALID_{field.upper()}", field)
    return n


# --------------------------------------------------------------------------
# Experiments + batch prediction (enterprise).
# --------------------------------------------------------------------------

@router.post("/training/experiments")
def create_experiment(body: ExperimentCreate, db: Session = Depends(get_db),
                      _: str = Depends(require_service_auth)):
    from app.ml import experiments as exp

    model_type = str(body.model_type).strip().lower()
    if model_type not in MODEL_TYPES:
        return _bad_request(
            "create_experiment",
            f"unsupported model_type {model_type!r}; expected one of: "
            + ", ".join(sorted(MODEL_TYPES)),
            "UNSUPPORTED_MODEL_TYPE", "model_type")

    dataset: List[Dict[str, Any]] = [dict(r) for r in (body.dataset or [])]
    if len(dataset) > MAX_DATASET_ROWS:
        return _error(413, "create_experiment",
                      f"dataset has {len(dataset)} rows; the maximum is {MAX_DATASET_ROWS}",
                      code="DATASET_TOO_LARGE", error_type="validation",
                      resolution="Kirim dataset yang lebih kecil, atau latih lewat worker.",
                      details={"field": "dataset", "max_rows": MAX_DATASET_ROWS})
    try:
        res = exp.create_experiment(
            model_type, dataset, name=body.name,
            dataset_ref=body.dataset_ref, dataset_version=body.dataset_version,
            feature_list=list(body.feature_list or []),
            params=dict(body.params or {}),
            train_ratio=body.train_ratio, val_ratio=body.val_ratio,
            test_ratio=body.test_ratio, seed=body.seed,
            label_key=body.label_key, date_key=body.date_key,
            run_training=body.run_training, db_session=db)
    except ValueError as exc:
        return _bad_request("create_experiment", str(exc), "INVALID_EXPERIMENT", "dataset")
    except Exception as exc:  # noqa: BLE001 - third-party numeric code
        log.error("experiment failed: %s", type(exc).__name__, exc_info=exc)
        return JSONResponse(status_code=500, content=build_error_response(
            module="training", operation="create_experiment", error_type="internal",
            code="EXPERIMENT_FAILED", message="Experiment failed.",
            request_id=get_request_id(),
            resolution="Periksa log server lalu ulangi.", internal=True))
    return {"success": True, "data": res}


@router.get("/training/experiments")
def list_experiments(model_type: str | None = None,
                     db: Session = Depends(get_db),
                     _: str = Depends(require_service_auth)) -> dict:
    from app.ml import experiments as exp

    try:
        rows = exp.list_experiments(model_type, db_session=db)
    except ValueError as exc:
        return _bad_request("list_experiments", str(exc),
                            "UNSUPPORTED_MODEL_TYPE", "model_type")
    return {"success": True, "data": rows}


@router.get("/training/experiments/{experiment_id}")
def get_experiment(experiment_id: int, db: Session = Depends(get_db),
                   _: str = Depends(require_service_auth)):
    from app.ml import experiments as exp

    try:
        return {"success": True, "data": exp.get_experiment(int(experiment_id), db)}
    except ValueError:
        return _error(404, "get_experiment",
                      f"experiment {experiment_id} not found",
                      code="NOT_FOUND", error_type="not_found",
                      resolution="Pastikan ID eksperimen benar.")


@router.post("/training/experiments/{experiment_id}/compare")
def compare_experiment(experiment_id: int, body: ExperimentCompareRequest,
                       db: Session = Depends(get_db),
                       _: str = Depends(require_service_auth)):
    from app.ml import experiments as exp

    ids = [int(i) for i in (body.experiment_ids or [])]
    if int(experiment_id) not in ids:
        ids = [int(experiment_id)] + ids
    try:
        res = exp.compare_experiments(ids, body.metric, body.split,
                                      body.higher_is_better, db)
    except ValueError as exc:
        msg = str(exc)
        if "not found" in msg:
            return _error(404, "compare_experiment", msg,
                          code="NOT_FOUND", error_type="not_found",
                          resolution="Pastikan ID eksperimen benar.")
        return _bad_request("compare_experiment", msg, "INVALID_COMPARE", "experiment_ids")
    return {"success": True, "data": res}


@router.post("/training/experiments/{experiment_id}/promote")
def promote_experiment(experiment_id: int, body: ExperimentPromoteRequest,
                       db: Session = Depends(get_db),
                       _: str = Depends(require_service_auth)):
    from app.ml import experiments as exp

    try:
        res = exp.promote_experiment(int(experiment_id), body.version_id, db)
    except ValueError as exc:
        msg = str(exc)
        if "not found" in msg:
            return _error(404, "promote_experiment", msg,
                          code="NOT_FOUND", error_type="not_found",
                          resolution="Pastikan ID eksperimen benar.")
        return _bad_request("promote_experiment", msg,
                            "INVALID_PROMOTION", "experiment_id")
    return {"success": True, "data": res}


@router.post("/training/batch-predict")
def batch_predict(body: BatchPredictRequest, db: Session = Depends(get_db),
                  _: str = Depends(require_service_auth)):
    from app.ml import batch as batch_mod

    mt = str(body.model_type or "").strip().lower()
    if mt not in MODEL_TYPES:
        return _bad_request(
            "batch_predict",
            f"unsupported model_type {mt!r}; expected one of: "
            + ", ".join(sorted(MODEL_TYPES)),
            "UNSUPPORTED_MODEL_TYPE", "model_type")
    dataset: List[Dict[str, Any]] = [dict(r) for r in (body.dataset or [])]
    if len(dataset) > MAX_DATASET_ROWS:
        return _error(413, "batch_predict",
                      f"dataset has {len(dataset)} rows; the maximum is {MAX_DATASET_ROWS}",
                      code="DATASET_TOO_LARGE", error_type="validation",
                      resolution="Kirim dataset yang lebih kecil.",
                      details={"field": "dataset", "max_rows": MAX_DATASET_ROWS})
    try:
        res = batch_mod.run_batch_predict(
            mt, dataset, csv_text=body.csv_text, model_name=body.model_name,
            model_id=body.model_id, version_id=body.version_id,
            chunk_size=body.chunk_size, params=dict(body.params or {}),
            db_session=db)
    except ValueError as exc:
        msg = str(exc)
        if "no production model" in msg or "not found" in msg:
            return _error(404, "batch_predict", msg,
                          code="NO_PRODUCTION_MODEL", error_type="not_found",
                          resolution="Train a model, lalu promote versinya ke PRODUCTION.")
        return _bad_request("batch_predict", msg, "INVALID_BATCH", "dataset")
    except Exception as exc:  # noqa: BLE001 - third-party numeric code
        log.error("batch predict failed: %s", type(exc).__name__, exc_info=exc)
        return JSONResponse(status_code=500, content=build_error_response(
            module="training", operation="batch_predict", error_type="internal",
            code="BATCH_FAILED", message="Batch prediction failed.",
            request_id=get_request_id(),
            resolution="Periksa log server lalu ulangi.", internal=True))
    return {"success": True, "data": res}
