"""Celery tasks: import/validate/transform/train/forecast/features/anomaly/report/embeddings/sync.

Secrets rule: no task logs a credential, and any exception text that is persisted
to a job row or the result backend is redacted first.
"""
from __future__ import annotations

import functools
import re
from typing import Any, Callable, Dict

from app.workers.celery_app import celery_app

# A filesystem path. The lookbehind keeps `redis://host:6379/1` and `12/34`
# intact: a match may only start at a `/`, `\` or `.` that is not preceded by a
# word character, a colon or a slash. Uploads are stored as
# `<uuid>_<original filename>` (see api/v1/imports.py::_save_upload), so the
# path carries the uploader's own file name.
_PATH_RE = re.compile(r"(?<![\w:/])(?:[A-Za-z]:)?[./\\][^\s'\"]*")


def _safe_error(exc: BaseException) -> str:
    """Render an exception without leaking credentials, DSNs or upload paths.

    `app.core.errors.redact_secrets` is the redactor the rest of the engine
    uses. It has to run first: a naive key/value rule rewrites the word "Bearer"
    in `Authorization: Bearer <token>` and leaves the token itself in clear
    text, which is the leak this function used to have.
    """
    from app.core.errors import redact_secrets

    return _PATH_RE.sub("[path]", redact_secrets(f"{type(exc).__name__}: {exc}"))


def _redacted_failure(fn: Callable[..., Any]) -> Callable[..., Any]:
    """Redact an escaping exception in place before it reaches the result backend.

    Celery serialises `str(exc)` for a FAILURE state and logs the traceback, so
    an unredacted message publishes a DSN or an upload path to both. Rewriting
    `args` keeps the exception type and traceback while scrubbing the text.
    """

    @functools.wraps(fn)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        try:
            return fn(*args, **kwargs)
        except Exception as exc:
            try:
                exc.args = (_safe_error(exc),)
            except Exception:
                pass
            raise

    return wrapper


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


def _mark_failed(job_id: int, reason: str) -> None:
    """Mark the job failed, appending the reason to the ETL's error log.

    `_set_job` assigns fields verbatim, so a blanket `error_log=[...]` here
    would discard the up-to-200 per-chunk entries `run_etl` has already
    persisted, which are the only record of which chunks failed and why.
    """
    if not job_id:
        return
    db = None
    try:
        from app.database.models import ImportJob

        db = _db()
        job = db.query(ImportJob).filter_by(id=job_id).first()
        if job is None:
            return
        entries = list(job.error_log or [])
        entries.append({"error": reason})
        job.status = "failed"
        job.error_log = entries[:200]
        db.commit()
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
        # `run_etl` owns the rich terminal states ("done" / "done_with_errors"),
        # which it only writes when it was handed a session and a job id. A
        # clean run is additionally marked "succeeded" here so the task, not
        # only the ETL, discharges the success half of the contract: that token
        # is the one the Laravel client treats as a finished import. A run with
        # row-level errors keeps the ETL's "done_with_errors" so the failure
        # detail is not laundered into a clean success.
        if import_job_id and not res.get("error_log"):
            _set_job(import_job_id, status="succeeded", progress=1.0)
        return res
    except Exception as exc:
        if import_job_id:
            _mark_failed(import_job_id, _safe_error(exc))
        # self.retry() raises Retry when a retry is scheduled and re-raises the
        # original error once the budget is spent. Both MUST propagate: swallowing
        # them here would report the task as successful and silently drop retries.
        # The reason is scrubbed first because the re-raised exception is what
        # Celery writes to the result backend and to the worker log.
        try:
            exc.args = (_safe_error(exc),)
        except Exception:
            pass
        self.retry(exc=exc)
        raise
    finally:
        if db is not None:
            _close(db)


@celery_app.task(name="app.workers.tasks.validate_dataset")
@_redacted_failure
def validate_dataset(file_path: str) -> Dict[str, Any]:
    from app.ingestion.validator import validate_file

    return validate_file(file_path)


@celery_app.task(name="app.workers.tasks.transform_dataset")
@_redacted_failure
def transform_dataset(file_path: str, dataset_type: str = "sales", mappings: Dict[str, Any] | None = None) -> Dict[str, Any]:
    """Map, clean and quality-check a file *without* writing to the warehouse.

    This is a dry run by construction, and it has to stay one. `run_etl` keys
    its idempotency on `import_job_id` (purge-then-insert under a Postgres
    advisory lock) and skips the purge entirely when the id is None, so passing
    a session here without a job id would let a redelivered message append a
    second copy of every fact row. Persisting an import is `import_file`.
    """
    from app.ingestion.etl import run_etl

    return run_etl(file_path, dataset_type, mappings or {}, None, None)


@celery_app.task(name="app.workers.tasks.train_model")
@_redacted_failure
def train_model(model_type: str, name: str = "model", params: Dict[str, Any] | None = None,
                dataset: Any | None = None) -> Dict[str, Any]:
    from app.ml.training import train_model as _train

    return _train(model_type, name, params or {}, dataset or [])


@celery_app.task(name="app.workers.tasks.generate_forecast")
@_redacted_failure
def generate_forecast(history: Any, horizon: int = 30) -> Dict[str, Any]:
    from app.ml.forecasting import forecast

    return forecast(list(history or []), int(horizon))


@celery_app.task(name="app.workers.tasks.calculate_customer_features")
@_redacted_failure
def calculate_customer_features(sales_rows: Any) -> Any:
    import pandas as pd

    from app.ml.features import customer_features

    df = pd.DataFrame(list(sales_rows or []))
    if df.empty:
        return []
    return customer_features(df).to_dict("records")


@celery_app.task(name="app.workers.tasks.anomaly_detection")
@_redacted_failure
def anomaly_detection(series: Any, sensitivity: float = 2.5) -> Dict[str, Any]:
    from app.ml.anomaly import detect_anomalies

    return detect_anomalies(list(series or []), float(sensitivity))


@celery_app.task(name="app.workers.tasks.generate_ai_report")
@_redacted_failure
def generate_ai_report(period: str = "weekly") -> Dict[str, Any]:
    from app.ai.reporting import executive_summary

    db = _db()
    try:
        return executive_summary(db, period)
    finally:
        _close(db)


@celery_app.task(name="app.workers.tasks.generate_embeddings")
@_redacted_failure
def generate_embeddings(title: str, content: str, source: str = "api") -> Dict[str, Any]:
    """Chunk, embed and store one document.

    `ingest_text` swallows a failed write and returns
    `{"status": "failed", "document_id": None}` instead of raising, so the
    return value is inspected here: without the check a rolled-back ingest is
    recorded in the result backend as a successful task and nothing is stored.
    An identical redelivery is safe -- `ingest_text` hashes the body and
    returns the existing document with status "unchanged".
    """
    from app.ai.rag import ingest_text

    db = _db()
    try:
        res = ingest_text(title, content, source, db_session=db)
    finally:
        _close(db)
    if res.get("status") == "failed":
        raise RuntimeError(
            f"rag ingest failed for doc_type={res.get('doc_type', 'txt')!r} "
            f"truncated={res.get('truncated')}"
        )
    return res


@celery_app.task(name="app.workers.tasks.scheduled_data_sync")
@_redacted_failure
def scheduled_data_sync() -> Dict[str, Any]:
    """Nightly reconciliation of the import pipeline (read-only).

    Beat entry ``nightly-data-sync`` (01:15 Asia/Jakarta) runs this. There is
    no external source to pull from in this deployment — uploads arrive via
    ``POST /api/v1/imports/upload`` and the ETL owns all state transitions —
    so the honest nightly work is reconciliation, not mutation:

    Schedule → Acquire Lock → Count by status → Detect stuck jobs
    (non-terminal + untouched for 24h) → Summarise the last 24h → Metrics.

    The task never writes: stuck jobs are *reported* (ids included) for the
    Laravel ``sync:import-status`` command / operators to act on. A concurrent
    beat is skipped via a MySQL named lock; on SQLite (tests) or when the
    lock query itself fails the task proceeds without the lock rather than
    failing the whole night.
    """
    from datetime import datetime, timedelta, timezone

    NON_TERMINAL = ("uploaded", "queued")
    now = datetime.now(timezone.utc)
    cutoff = now - timedelta(hours=24)

    db = _db()
    locked = False
    dialect = ""
    try:
        try:
            dialect = db.get_bind().dialect.name
        except Exception:
            dialect = ""
        if dialect == "mysql":
            try:
                from sqlalchemy import text

                acquired = db.execute(
                    text("SELECT GET_LOCK(:key, 0)"),
                    {"key": "aidata_scheduled_sync"},
                ).scalar()
                if acquired != 1:
                    return {
                        "status": "skipped",
                        "reason": "lock_held",
                        "checked_at": now.isoformat(),
                    }
                locked = True
            except Exception:
                locked = False

        from app.database.models import ImportJob

        totals: Dict[str, int] = {}
        try:
            from sqlalchemy import func

            for status, count in (
                db.query(ImportJob.status, func.count(ImportJob.id))
                .group_by(ImportJob.status)
                .all()
            ):
                totals[str(status)] = int(count)
        except Exception:
            for job in db.query(ImportJob).all():
                totals[str(job.status)] = totals.get(str(job.status), 0) + 1

        stuck_ids: list[int] = []
        try:
            stuck = (
                db.query(ImportJob)
                .filter(
                    ImportJob.status.in_(NON_TERMINAL),
                    ImportJob.updated_at < cutoff,
                )
                .order_by(ImportJob.id)
                .limit(100)
                .all()
            )
            stuck_ids = [int(j.id) for j in stuck]
        except Exception:
            stuck_ids = []

        last_24h: Dict[str, int] = {}
        try:
            recent = (
                db.query(ImportJob).filter(ImportJob.updated_at >= cutoff).all()
            )
            for job in recent:
                last_24h[str(job.status)] = last_24h.get(str(job.status), 0) + 1
        except Exception:
            pass

        return {
            "status": "ok",
            "checked_at": now.isoformat(),
            "totals": totals,
            "stuck": {"count": len(stuck_ids), "job_ids": stuck_ids},
            "last_24h": last_24h,
        }
    finally:
        if locked:
            try:
                from sqlalchemy import text

                db.execute(
                    text("SELECT RELEASE_LOCK(:key)"),
                    {"key": "aidata_scheduled_sync"},
                )
                db.commit()
            except Exception:
                pass
        _close(db)
