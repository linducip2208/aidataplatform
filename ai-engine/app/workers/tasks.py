"""Celery tasks: import/validate/transform/train/forecast/features/anomaly/report/embeddings/sync.

Secrets rule: no task logs a credential, and any exception text that is persisted
to a job row or the result backend is redacted first.
"""
from __future__ import annotations

import re
from typing import Any, Dict

from app.workers.celery_app import celery_app

# "scheme://user:password@host" -> "scheme://user:***@host"
_URL_CREDENTIALS_RE = re.compile(r"(://[^:/@\s]+:)([^@/\s]+)(@)")
# "api_key=sk-live-...", "password: hunter2", "token=abc"
_SECRET_ASSIGNMENT_RE = re.compile(
    r"(?i)\b(api[_-]?key|secret|password|passwd|token|authorization)\b(\s*[=:]\s*)(\S+)"
)


def _safe_error(exc: BaseException) -> str:
    """Render an exception without leaking credentials from DSNs or messages."""
    text = f"{type(exc).__name__}: {exc}"
    text = _URL_CREDENTIALS_RE.sub(r"\1***\3", text)
    return _SECRET_ASSIGNMENT_RE.sub(r"\1\2***", text)


def _db() -> Any:
    from app.database.connection import SessionLocal

    return SessionLocal()


def _close(db: Any) -> None:
    """Always end the transaction before returning the connection to the pool."""
    try:
        db.rollback()
    except Exception:
        pass
    try:
        db.close()
    except Exception:
        pass


def _set_job(job_id: int, **fields: Any) -> None:
    if not job_id:
        return
    db = None
    try:
        from app.database.models import ImportJob

        db = _db()
        job = db.query(ImportJob).filter_by(id=job_id).first()
        if job:
            for k, v in fields.items():
                setattr(job, k, v)
            db.commit()
        else:
            db.rollback()
    except Exception:
        if db is not None:
            try:
                db.rollback()
            except Exception:
                pass
    finally:
        if db is not None:
            _close(db)


def _progress(self: Any, progress: float, msg: str = "") -> None:
    if not hasattr(self, "update_state"):
        return
    try:
        meta: Dict[str, Any] = {"progress": float(progress)}
        if msg:
            meta["msg"] = msg
        self.update_state(state="PROGRESS", meta=meta)
    except Exception:
        pass


@celery_app.task(bind=True, name="app.workers.tasks.import_file", max_retries=3)
def import_file(self: Any, file_path: str, dataset_type: str = "sales",
                mappings: Dict[str, Any] | None = None, import_job_id: int | None = None) -> Dict[str, Any]:
    db = None
    try:
        _progress(self, 0.1)
        from app.ingestion.etl import run_etl

        db = _db()

        def cb(p: float, msg: str) -> None:
            if import_job_id:
                _set_job(import_job_id, progress=float(p))
            _progress(self, p, msg)

        res = run_etl(file_path, dataset_type, mappings or {}, import_job_id, db, progress=cb)
        return res
    except Exception as exc:
        if import_job_id:
            _set_job(import_job_id, status="failed", error_log=[{"error": _safe_error(exc)}])
        # self.retry() raises Retry when a retry is scheduled and re-raises the
        # original error once the budget is spent. Both MUST propagate: swallowing
        # them here would report the task as successful and silently drop retries.
        self.retry(exc=exc)
        raise
    finally:
        if db is not None:
            _close(db)


@celery_app.task(name="app.workers.tasks.validate_dataset")
def validate_dataset(file_path: str) -> Dict[str, Any]:
    from app.ingestion.validator import validate_file

    return validate_file(file_path)


@celery_app.task(name="app.workers.tasks.transform_dataset")
def transform_dataset(file_path: str, dataset_type: str = "sales", mappings: Dict[str, Any] | None = None) -> Dict[str, Any]:
    from app.ingestion.etl import run_etl

    return run_etl(file_path, dataset_type, mappings or {}, None, None)


@celery_app.task(name="app.workers.tasks.train_model")
def train_model(model_type: str, name: str = "model", params: Dict[str, Any] | None = None,
                dataset: Any | None = None) -> Dict[str, Any]:
    from app.ml.training import train_model as _train

    return _train(model_type, name, params or {}, dataset or [])


@celery_app.task(name="app.workers.tasks.generate_forecast")
def generate_forecast(history: Any, horizon: int = 30) -> Dict[str, Any]:
    from app.ml.forecasting import forecast

    return forecast(list(history or []), int(horizon))


@celery_app.task(name="app.workers.tasks.calculate_customer_features")
def calculate_customer_features(sales_rows: Any) -> Any:
    import pandas as pd

    from app.ml.features import customer_features

    df = pd.DataFrame(list(sales_rows or []))
    if df.empty:
        return []
    return customer_features(df).to_dict("records")


@celery_app.task(name="app.workers.tasks.anomaly_detection")
def anomaly_detection(series: Any, sensitivity: float = 2.5) -> Dict[str, Any]:
    from app.ml.anomaly import detect_anomalies

    return detect_anomalies(list(series or []), float(sensitivity))


@celery_app.task(name="app.workers.tasks.generate_ai_report")
def generate_ai_report(period: str = "weekly") -> Dict[str, Any]:
    from app.ai.reporting import executive_summary

    db = _db()
    try:
        return executive_summary(db, period)
    finally:
        _close(db)


@celery_app.task(name="app.workers.tasks.generate_embeddings")
def generate_embeddings(title: str, content: str, source: str = "api") -> Dict[str, Any]:
    from app.ai.rag import ingest_text

    db = _db()
    try:
        return ingest_text(title, content, source, db_session=db)
    finally:
        _close(db)


@celery_app.task(name="app.workers.tasks.scheduled_data_sync")
def scheduled_data_sync() -> Dict[str, Any]:
    return {"status": "ok", "message": "sync placeholder executed"}
