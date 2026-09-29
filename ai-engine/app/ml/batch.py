"""Batch prediction: run a registered production model over a dataset in chunks.

The caller hands over row objects (or raw CSV text) plus a model reference; this
module resolves the serving artifact, scores the rows ``chunk_size`` at a time
so a 50k-row payload never materialises one giant frame, persists a
``prediction_runs`` row, and writes the predictions to a JSON artifact under
``settings.model_path``. The returned summary counts what actually happened —
scored rows, error rows, per-chunk notes — and every row that could not be
scored is returned with an ``error`` key rather than dropped or fabricated.

Per-type behaviour (all real inference paths, deterministic seeds where a model
trains internally):

* ``churn`` — needs a production artifact; scores with
  :func:`app.ml.churn.predict_churn_proba`.
* ``anomaly`` — stateless :func:`app.ml.detect_anomalies` per chunk. The
  summary flags ``chunked: True`` because detectors fit per chunk and do not
  share cross-chunk context.
* ``segmentation`` — stateless :func:`app.ml.segmentation.segment` per chunk.
* ``forecast`` — each input row must carry its own ``history`` list (plus
  optional ``horizon`` / ``granularity``); rows without one are error rows.
* ``recommend`` — transactions come from the production artifact when one
  exists, otherwise from the dataset itself; each row may carry
  ``customer_id`` / ``product_id``.
"""
from __future__ import annotations

import csv
import io
import json
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

from app.core.config import settings

#: Same ceiling as the training router: the fit and the batch score share the
#: request thread, so an unbounded payload would pin a worker the same way.
MAX_BATCH_ROWS = 50_000
MIN_CHUNK_SIZE = 1
MAX_CHUNK_SIZE = 5000
DEFAULT_CHUNK_SIZE = 500
#: Predictions kept inside the artifact file; beyond this the file carries the
#: summary plus a truncation flag instead of growing without bound.
MAX_STORED_PREDICTIONS = 10_000

SUPPORTED_TYPES = frozenset({
    "forecast", "churn", "segmentation", "segment",
    "anomaly", "recommend", "recommendation",
})


class BatchError(ValueError):
    """Base class for batch failures (subclasses ``ValueError`` so routers
    render the engine error envelope)."""


class NoProductionModel(BatchError):
    """The referenced model has nothing usable to serve."""


def _session(db_session):
    if db_session is None:
        from app.database.connection import SessionLocal

        db = SessionLocal()
        return db, True
    return db_session, False


def _normalise_model_type(model_type: str) -> str:
    mt = str(model_type or "").strip().lower()
    if mt not in SUPPORTED_TYPES:
        raise BatchError(
            f"unsupported model_type {model_type!r}; expected one of: "
            + ", ".join(sorted(SUPPORTED_TYPES)))
    return mt


def _clamp_chunk_size(chunk_size: Any) -> int:
    try:
        size = int(chunk_size)
    except (TypeError, ValueError):
        return DEFAULT_CHUNK_SIZE
    return max(MIN_CHUNK_SIZE, min(size, MAX_CHUNK_SIZE))


def parse_csv_text(csv_text: str) -> List[Dict[str, Any]]:
    """Parse raw CSV text into row dicts. Raises :class:`BatchError` for empty
    or header-less input rather than producing zero rows silently."""
    text = str(csv_text or "")
    if not text.strip():
        raise BatchError("csv_text is empty; supply dataset rows or CSV text")
    try:
        reader = csv.DictReader(io.StringIO(text))
        if not reader.fieldnames:
            raise BatchError("csv_text has no header row")
        rows = [dict(r) for r in reader if any((v or "").strip() for v in r.values())]
    except BatchError:
        raise
    except Exception as exc:
        raise BatchError(f"csv_text could not be parsed: {type(exc).__name__}") from exc
    if not rows:
        raise BatchError("csv_text holds no data rows")
    return rows


def _resolve_rows(dataset: Any, csv_text: Optional[str]) -> List[Dict[str, Any]]:
    if dataset:
        rows = [dict(r) for r in dataset if isinstance(r, dict)]
        if not rows:
            raise BatchError("dataset holds no row objects")
        return rows
    if csv_text:
        return parse_csv_text(csv_text)
    raise BatchError("batch prediction needs dataset rows or csv_text")


def _resolve_artifact(model_type: str, model_name: Optional[str],
                      model_id: Optional[int], version_id: Optional[int],
                      db) -> Tuple[Any, Optional[int], Optional[int]]:
    """Return ``(artifact, model_id, version_id)`` for the reference.

    ``model_id`` + ``version_id`` pin an exact version; otherwise the named
    model's production artifact is loaded. Types that never read an artifact
    (anomaly, segmentation, forecast) get ``(None, ids...)`` without touching
    the registry; types that need one raise :class:`NoProductionModel` when
    there is nothing usable — the same contract as the churn predict path.
    """
    from app.ml import registry as reg

    if model_type in ("anomaly", "segmentation", "segment", "forecast"):
        return None, model_id, version_id
    if model_id and version_id:
        from pathlib import Path

        import joblib

        from app.database.models import ModelVersion

        v = db.query(ModelVersion).filter_by(
            id=int(version_id), model_id=int(model_id)).first()
        if not v:
            raise NoProductionModel(
                f"version {version_id} not found for model {model_id}")
        path = Path(v.artifact_path or "")
        if not path.is_file():
            raise NoProductionModel(
                f"version {version_id} artifact is missing at {path}")
        try:
            return joblib.load(path), int(model_id), int(version_id)
        except Exception as exc:
            raise NoProductionModel(
                f"version {version_id} artifact could not be loaded: "
                f"{type(exc).__name__}") from exc
    name = str(model_name or f"{model_type}-model")
    artifact = reg.load_production(name, db)
    if artifact is None:
        # Resolve ids for the prediction_runs row when the model row exists.
        from app.database.models import MLModel

        m = db.query(MLModel).filter_by(name=name).first()
        raise NoProductionModel(
            f"no production model for {name!r}; train it and promote a "
            f"version to PRODUCTION first")
    from app.database.models import MLModel

    m = db.query(MLModel).filter_by(name=name).first()
    return artifact, (m.id if m else None), (m.production_version_id if m else None)


def _chunks(rows: List[Dict[str, Any]], size: int):
    for i in range(0, len(rows), size):
        yield i // size, rows[i:i + size]


def _score_chunk(model_type: str, chunk: List[Dict[str, Any]],
                 artifact: Any, params: Dict[str, Any]) -> List[Dict[str, Any]]:
    """Score one chunk, returning one output dict per input row in order."""
    if model_type == "churn":
        from app.ml.churn import predict_churn_proba

        out = predict_churn_proba(artifact["model"], artifact["scaler"], chunk)
        return [{"row": i, **r} for i, r in enumerate(out)]

    if model_type == "anomaly":
        from app.ml.anomaly import detect_anomalies

        res = detect_anomalies(chunk, float(params.get("sensitivity", 2.5)))
        return [{"row": p["index"], "date": p["date"], "value": p["value"],
                 "anomaly": p["anomaly"], "score": p["score"],
                 "severity": p["severity"]} for p in res.get("all", [])]

    if model_type in ("segmentation", "segment"):
        from app.ml.segmentation import segment

        res = segment(chunk, n_clusters=int(params.get("n_clusters", 4)))
        return [{"row": i, **s} for i, s in enumerate(res.get("segments", []))]

    if model_type == "forecast":
        from app.ml.forecasting import forecast

        out = []
        for i, row in enumerate(chunk):
            hist = row.get("history", [])
            if not isinstance(hist, list) or not hist:
                out.append({"row": i, "forecast": [], "method": "unsupported_shape",
                            "error": "batch forecast rows need a non-empty 'history' list"})
                continue
            try:
                horizon = int(row.get("horizon", params.get("horizon", 30)))
            except (TypeError, ValueError):
                horizon = 30
            horizon = max(1, min(horizon, 365))
            granularity = str(row.get("granularity", params.get("granularity", "daily")))
            try:
                res = forecast([dict(r) for r in hist if isinstance(r, dict)],
                               horizon, granularity)
            except ValueError as exc:
                out.append({"row": i, "forecast": [], "method": "unsupported_shape",
                            "error": str(exc)})
                continue
            out.append({"row": i, "forecast": res["forecast"],
                        "method": res["method"], "metrics": res["metrics"]})
        return out

    # recommend / recommendation
    from app.ml.recommendation import recommend as _rec

    if isinstance(artifact, dict) and isinstance(artifact.get("transactions"), list) \
            and artifact["transactions"]:
        transactions = artifact["transactions"]
    else:
        transactions = chunk
    out = []
    for i, row in enumerate(chunk):
        recs = _rec(transactions, row.get("customer_id"), row.get("product_id"),
                    int(params.get("top_k", row.get("top_k", 5)) or 5))
        out.append({"row": i, "recommendations": recs})
    return out


def _summarise(model_type: str, predictions: List[Dict[str, Any]],
               n_chunks: int, chunk_size: int) -> Dict[str, Any]:
    summary: Dict[str, Any] = {"n_rows": len(predictions), "n_chunks": n_chunks,
                               "chunk_size": chunk_size, "model_type": model_type}
    errors = sum(1 for p in predictions if p.get("error"))
    summary["n_errors"] = errors
    if model_type == "churn":
        scored = [p for p in predictions
                  if isinstance(p.get("churn_proba"), (int, float))]
        summary["n_scored"] = len(scored)
        if scored:
            summary["mean_proba"] = round(
                sum(float(p["churn_proba"]) for p in scored) / len(scored), 4)
            summary["n_flagged"] = sum(1 for p in scored if p.get("churn"))
    elif model_type == "anomaly":
        summary["n_anomalies"] = sum(1 for p in predictions if p.get("anomaly"))
        summary["chunked"] = True
        summary["note"] = ("series scored per chunk; detectors fit within each "
                           "chunk and do not share cross-chunk context")
    elif model_type in ("segmentation", "segment"):
        dist: Dict[str, int] = {}
        for p in predictions:
            dist[str(p.get("segment", "unknown"))] = \
                dist.get(str(p.get("segment", "unknown")), 0) + 1
        summary["segment_distribution"] = dist
    elif model_type == "forecast":
        summary["n_forecasts"] = sum(1 for p in predictions if p.get("forecast"))
        methods: Dict[str, int] = {}
        for p in predictions:
            methods[str(p.get("method", "unknown"))] = \
                methods.get(str(p.get("method", "unknown")), 0) + 1
        summary["methods"] = methods
    else:
        summary["n_scored"] = len(predictions) - errors
    return summary


def _json_safe(value: Any) -> Any:
    """Coerce numpy scalars out of predictions so the artifact is real JSON."""
    try:
        import numpy as np

        if isinstance(value, (np.integer,)):
            return int(value)
        if isinstance(value, (np.floating,)):
            return float(value)
        if isinstance(value, (np.bool_,)):
            return bool(value)
        if isinstance(value, np.ndarray):
            return value.tolist()
    except ImportError:
        pass
    if isinstance(value, dict):
        return {str(k): _json_safe(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(v) for v in value]
    if isinstance(value, float) and (value != value or value in (float("inf"), float("-inf"))):
        return None
    return value


def run_batch_predict(model_type: str, dataset: Any = None, *,
                      csv_text: Optional[str] = None,
                      model_name: Optional[str] = None,
                      model_id: Optional[int] = None,
                      version_id: Optional[int] = None,
                      chunk_size: int = DEFAULT_CHUNK_SIZE,
                      params: Dict[str, Any] | None = None,
                      db_session=None) -> Dict[str, Any]:
    """Score ``dataset`` in chunks and persist the run.

    Returns ``{"run_id", "model_type", "model_id", "version_id", "n_rows",
    "n_chunks", "chunk_size", "summary", "artifact_path"}``. The
    ``prediction_runs`` row carries ``input_summary`` (provenance) and
    ``output_summary`` (counts + artifact path), so inference history is
    queryable — the gap ``docs/model-governance.md`` used to document.
    """
    from app.database.models import PredictionRun

    mt = _normalise_model_type(model_type)
    params = dict(params or {})
    rows = _resolve_rows(dataset, csv_text)
    if len(rows) > MAX_BATCH_ROWS:
        raise BatchError(
            f"dataset has {len(rows)} rows; the maximum is {MAX_BATCH_ROWS}")
    size = _clamp_chunk_size(chunk_size)

    db, own = _session(db_session)
    try:
        artifact, resolved_model_id, resolved_version_id = _resolve_artifact(
            mt, model_name, model_id, version_id, db)

        run = PredictionRun(
            model_id=resolved_model_id, model_type=mt,
            input_summary={"n_rows": len(rows), "chunk_size": size,
                           "model_name": model_name,
                           "model_id": resolved_model_id,
                           "version_id": resolved_version_id},
            output_summary={})
        db.add(run)
        db.flush()

        predictions: List[Dict[str, Any]] = []
        n_chunks = 0
        for _, chunk in _chunks(rows, size):
            n_chunks += 1
            scored = _score_chunk(mt, chunk, artifact, params)
            base = len(predictions)
            for offset, entry in enumerate(scored):
                entry = dict(entry)
                entry["row"] = base + offset
                predictions.append(_json_safe(entry))

        summary = _summarise(mt, predictions, n_chunks, size)

        settings.model_path.mkdir(parents=True, exist_ok=True)
        apath = settings.model_path / f"batch_{run.id}.json"
        stored = predictions[:MAX_STORED_PREDICTIONS]
        payload = {"run_id": run.id, "model_type": mt,
                   "model_id": resolved_model_id,
                   "version_id": resolved_version_id,
                   "summary": summary, "predictions": stored,
                   "truncated": len(predictions) > len(stored),
                   "created_at": datetime.now(timezone.utc).isoformat()}
        try:
            apath.write_text(json.dumps(payload), encoding="utf-8")
        except Exception as exc:
            raise BatchError(
                f"could not write batch artifact: {type(exc).__name__}") from exc
        summary["artifact_path"] = str(apath)
        summary["truncated"] = payload["truncated"]

        run.output_summary = dict(summary)
        db.commit()
        db.refresh(run)
        return {"run_id": run.id, "model_type": mt,
                "model_id": resolved_model_id, "version_id": resolved_version_id,
                "n_rows": len(rows), "n_chunks": n_chunks, "chunk_size": size,
                "summary": summary, "artifact_path": str(apath)}
    finally:
        if own:
            db.close()
