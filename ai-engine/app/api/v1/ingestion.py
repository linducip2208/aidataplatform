"""Enterprise ingestion endpoints: cancel / resume / checkpoints / dead-letter."""
from __future__ import annotations

from typing import Any, Dict

from fastapi import APIRouter, Depends, Query
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_logger, get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db

log = get_logger("api.ingestion", "ingestion")

router = APIRouter(tags=["ingestion-enterprise"])


def _error(status_code: int, operation: str, message: str, *, code: str,
           error_type: str = "validation", resolution: str = "",
           details: Dict[str, Any] | None = None) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content=build_error_response(
            module="ingestion", operation=operation, error_type=error_type,
            code=code, message=message, request_id=get_request_id(),
            resolution=resolution or "Periksa kembali payload, lalu ulangi.",
            details=details or {}),
    )


def _job_or_404(db: Session, job_id: int, operation: str):
    from app.database.models import ImportJob

    job = db.query(ImportJob).filter_by(id=job_id).first()
    if job is None:
        return None, _error(404, operation, f"import job {job_id} not found",
                            code="JOB_NOT_FOUND", error_type="not_found",
                            resolution="Pastikan import_job_id benar.",
                            details={"job_id": job_id})
    return job, None


@router.post("/imports/{job_id}/cancel")
def cancel_import(job_id: int, db: Session = Depends(get_db),
                  _: str = Depends(require_service_auth)) -> Any:
    """Set the cancel flag (``import_jobs.status = 'cancelled'``).

    A running ``run_etl`` observes the flag at the next chunk boundary and
    stops; an idle job simply stays cancelled so a late worker start aborts
    immediately instead of loading rows nobody wants.
    """
    from app.ingestion import checkpoints as cp

    job, err = _job_or_404(db, job_id, "cancel")
    if err is not None:
        return err
    if str(job.status).lower() in ("done", "succeeded"):
        return {"success": True, "data": {"import_job_id": job_id, "status": job.status,
                                          "cancelled": False,
                                          "note": "job already completed; nothing to cancel"}}
    ok = cp.request_cancel(db, job_id)
    if not ok:
        return _error(409, "cancel", f"import job {job_id} cannot be cancelled "
                      f"from status {job.status!r}", code="CANCEL_REJECTED",
                      error_type="conflict", details={"job_id": job_id, "status": job.status})
    return {"success": True, "data": {"import_job_id": job_id, "status": "cancelled",
                                      "cancelled": True}}


@router.post("/imports/{job_id}/resume")
def resume_import(job_id: int, body: Dict[str, Any] | None = None,
                  db: Session = Depends(get_db),
                  _: str = Depends(require_service_auth)) -> Any:
    """Resume a crashed/interrupted job from its checkpoints.

    The file is located via the job's ``raw_uploads`` row (or an explicit
    ``stored_path`` in the body); completed chunks for the current file bytes
    are skipped. An optional ``chunksize`` overrides the default; mappings and
    dataset_type come from the job row unless overridden in the body.
    """
    from app.database.models import ImportJob, RawUpload
    from app.ingestion import checkpoints as cp
    from app.ingestion.etl import run_etl

    body = dict(body or {})
    job, err = _job_or_404(db, job_id, "resume")
    if err is not None:
        return err
    if cp.is_cancelled(db, job_id) and not body.get("clear_cancel"):
        return _error(409, "resume", f"import job {job_id} is cancelled; "
                      "pass {\"clear_cancel\": true} to run it anyway",
                      code="JOB_CANCELLED", error_type="conflict",
                      details={"job_id": job_id})
    stored_path = body.get("stored_path")
    if not stored_path and job.upload_id:
        raw = db.query(RawUpload).filter_by(id=job.upload_id).first()
        stored_path = raw.stored_path if raw else None
    if not stored_path:
        return _error(422, "resume", f"import job {job_id} has no stored file; "
                      "pass {\"stored_path\": \"...\"}", code="NO_FILE",
                      details={"job_id": job_id})
    if cp.is_cancelled(db, job_id) and body.get("clear_cancel"):
        job.status = "queued"
        db.commit()
    dataset_type = str(body.get("dataset_type") or job.dataset_type or "sales")
    mappings = body.get("mappings", None)
    if mappings is None:
        mappings = dict(job.mapping or {})
    chunksize = int(body.get("chunksize") or 20000)

    def _cb(p: float, _msg: str) -> None:
        try:
            db.query(ImportJob).filter_by(id=job_id).update({"progress": float(p)})
            db.commit()
        except Exception:
            try:
                db.rollback()
            except Exception:
                pass

    try:
        res = run_etl(stored_path, dataset_type, mappings, job_id, db, progress=_cb,
                      chunksize=chunksize, resume=True)
    except Exception as exc:
        log.error("resume import_job %s failed: %s", job_id, type(exc).__name__, exc_info=exc)
        return JSONResponse(status_code=500, content=build_error_response(
            module="ingestion", operation="resume", error_type="internal",
            code="RESUME_FAILED", message="Resume failed.",
            request_id=get_request_id(),
            resolution="Periksa log server lalu ulangi.", internal=True))
    return {"success": True, "data": res}


@router.get("/imports/{job_id}/checkpoints")
def get_checkpoints(job_id: int, db: Session = Depends(get_db),
                    _: str = Depends(require_service_auth)) -> Any:
    from app.ingestion import checkpoints as cp

    job, err = _job_or_404(db, job_id, "checkpoints")
    if err is not None:
        return err
    rows = cp.list_checkpoints(db, job_id)
    done = sum(1 for r in rows if r.get("state") == "done")
    return {"success": True, "data": {"import_job_id": job_id, "checkpoints": rows,
                                      "done_chunks": done, "total_chunks": len(rows)}}


@router.get("/imports/{job_id}/dead-letter")
def get_dead_letter(job_id: int, db: Session = Depends(get_db),
                    limit: int = Query(default=200, ge=1, le=1000),
                    offset: int = Query(default=0, ge=0),
                    _: str = Depends(require_service_auth)) -> Any:
    from app.ingestion import checkpoints as cp

    job, err = _job_or_404(db, job_id, "dead_letter")
    if err is not None:
        return err
    rows = cp.list_dead_letters(db, job_id, limit=limit, offset=offset)
    return {"success": True, "data": {"import_job_id": job_id, "records": rows,
                                      "total": cp.count_dead_letters(db, job_id),
                                      "limit": limit, "offset": offset}}
