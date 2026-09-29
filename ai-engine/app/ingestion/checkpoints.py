"""Checkpoint store helpers — pure functions over a session.

All helpers take an open SQLAlchemy session and never commit on their own;
the caller (``run_etl``) owns the transaction. Every helper degrades to a
no-op returning a neutral value when ``session`` is ``None`` so the ETL stays
runnable without a database (dry runs, unit tests over files only).
"""
from __future__ import annotations

from typing import Any, Dict, List, Optional


def _ensure(session: Any) -> bool:
    if session is None:
        return False
    try:
        from app.ingestion.models import ensure_enterprise_tables

        ensure_enterprise_tables(session)
    except Exception:
        pass
    return True


def save_checkpoint(session: Any, job_id: int, chunk_index: int,
                    rows_done: int, state: str = "done", file_hash: str = "") -> None:
    """Upsert the checkpoint for (job_id, chunk_index). Flushes, never commits."""
    if not _ensure(session) or job_id is None:
        return
    from app.ingestion.models import ImportCheckpoint

    try:
        row = session.query(ImportCheckpoint).filter_by(job_id=job_id, chunk_index=chunk_index).first()
        if row is None:
            row = ImportCheckpoint(job_id=job_id, chunk_index=chunk_index,
                                   rows_done=int(rows_done), state=state, file_hash=file_hash or "")
            session.add(row)
        else:
            row.rows_done = int(rows_done)
            row.state = state
            if file_hash:
                row.file_hash = file_hash
        session.flush()
    except Exception:
        try:
            session.rollback()
        except Exception:
            pass


def completed_chunks(session: Any, job_id: int, file_hash: str = "") -> set:
    """Zero-based chunk indexes already in ``done`` state for this job.

    When ``file_hash`` is given, checkpoints recorded for different bytes are
    ignored so a changed file never resumes stale progress.
    """
    if session is None or job_id is None:
        return set()
    if not _ensure(session):
        return set()
    try:
        from app.ingestion.models import ImportCheckpoint

        q = session.query(ImportCheckpoint).filter_by(job_id=job_id, state="done")
        if file_hash:
            q = q.filter_by(file_hash=file_hash)
        return {int(r.chunk_index) for r in q.all()}
    except Exception:
        return set()


def last_rows_done(session: Any, job_id: int, file_hash: str = "") -> int:
    """Cumulative ``rows_done`` of the highest completed checkpoint, else 0."""
    if session is None or job_id is None:
        return 0
    if not _ensure(session):
        return 0
    try:
        from app.ingestion.models import ImportCheckpoint

        q = session.query(ImportCheckpoint).filter_by(job_id=job_id, state="done")
        if file_hash:
            q = q.filter_by(file_hash=file_hash)
        row = q.order_by(ImportCheckpoint.chunk_index.desc()).first()
        return int(row.rows_done) if row is not None else 0
    except Exception:
        return 0


def list_checkpoints(session: Any, job_id: int) -> List[Dict[str, Any]]:
    if session is None or job_id is None:
        return []
    if not _ensure(session):
        return []
    try:
        from app.ingestion.models import ImportCheckpoint

        rows = session.query(ImportCheckpoint).filter_by(job_id=job_id)\
            .order_by(ImportCheckpoint.chunk_index.asc()).all()
        return [{"job_id": r.job_id, "chunk_index": r.chunk_index, "rows_done": r.rows_done,
                 "state": r.state, "file_hash": r.file_hash} for r in rows]
    except Exception:
        return []


def clear_checkpoints(session: Any, job_id: int, file_hash: str = "") -> int:
    """Delete checkpoints for a job (fresh restart). Returns rows deleted."""
    if session is None or job_id is None:
        return 0
    if not _ensure(session):
        return 0
    try:
        from app.ingestion.models import ImportCheckpoint

        q = session.query(ImportCheckpoint).filter_by(job_id=job_id)
        if file_hash:
            q = q.filter_by(file_hash=file_hash)
        n = int(q.delete() or 0)
        session.flush()
        return n
    except Exception:
        return 0


def add_dead_letter(session: Any, job_id: int, row_index: int, raw: Any,
                    reason: str, chunk_index: int = 0) -> None:
    """Persist one failed row. ``raw`` is JSON-normalised; flushes, never commits."""
    if not _ensure(session) or job_id is None:
        return
    from app.ingestion.models import DeadLetterRecord

    try:
        payload = _jsonable(raw)
        session.add(DeadLetterRecord(job_id=job_id, row_index=int(row_index),
                                     chunk_index=int(chunk_index), raw=payload,
                                     reason=str(reason)[:1024]))
        session.flush()
    except Exception:
        try:
            session.rollback()
        except Exception:
            pass


def list_dead_letters(session: Any, job_id: int, limit: int = 200, offset: int = 0) -> List[Dict[str, Any]]:
    if session is None or job_id is None:
        return []
    if not _ensure(session):
        return []
    try:
        from app.ingestion.models import DeadLetterRecord

        rows = session.query(DeadLetterRecord).filter_by(job_id=job_id)\
            .order_by(DeadLetterRecord.id.asc()).offset(int(offset)).limit(int(limit)).all()
        return [{"id": r.id, "job_id": r.job_id, "row_index": r.row_index,
                 "chunk_index": r.chunk_index, "raw": r.raw, "reason": r.reason} for r in rows]
    except Exception:
        return []


def count_dead_letters(session: Any, job_id: int) -> int:
    if session is None or job_id is None:
        return 0
    if not _ensure(session):
        return 0
    try:
        from app.ingestion.models import DeadLetterRecord

        return int(session.query(DeadLetterRecord).filter_by(job_id=job_id).count() or 0)
    except Exception:
        return 0


def _jsonable(raw: Any) -> Any:
    if isinstance(raw, dict):
        out = {}
        for k, v in raw.items():
            try:
                import math

                if v is None or isinstance(v, (str, int, float, bool)):
                    if isinstance(v, float) and (math.isnan(v) or math.isinf(v)):
                        out[str(k)] = None
                    else:
                        out[str(k)] = v
                else:
                    out[str(k)] = str(v)
            except Exception:
                out[str(k)] = ""
        return out
    try:
        import pandas as pd

        if pd.isna(raw):
            return {}
    except Exception:
        pass
    return {"value": str(raw)}


def is_cancelled(session: Any, job_id: Optional[int]) -> bool:
    """The cancel flag is the job row itself: ``status == 'cancelled'``.

    No schema change needed — the cancel endpoint writes that status and the
    ETL checks it at every chunk boundary.
    """
    if session is None or job_id is None:
        return False
    try:
        from app.database.models import ImportJob

        job = session.query(ImportJob).filter_by(id=job_id).first()
        return bool(job is not None and str(job.status).lower() in ("cancelled", "canceled"))
    except Exception:
        return False


def request_cancel(session: Any, job_id: int) -> bool:
    """Set the cancel flag. Returns False when the job does not exist."""
    if session is None or job_id is None:
        return False
    try:
        from app.database.models import ImportJob

        job = session.query(ImportJob).filter_by(id=job_id).first()
        if job is None:
            return False
        if str(job.status).lower() not in ("done", "succeeded", "failed"):
            job.status = "cancelled"
            session.commit()
        return True
    except Exception:
        try:
            session.rollback()
        except Exception:
            pass
        return False
