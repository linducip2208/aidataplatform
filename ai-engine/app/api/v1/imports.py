"""Import endpoints: upload/init, preview, mapping, quality, commit."""
from __future__ import annotations

import shutil
import uuid
from pathlib import Path
from typing import Any

from fastapi import APIRouter, Depends, File, Form, UploadFile
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.imports import MappingRequest

router = APIRouter(tags=["imports"])


def _save_upload(up: UploadFile) -> Path:
    settings.storage_path.mkdir(parents=True, exist_ok=True)
    dest = settings.storage_path / f"{uuid.uuid4().hex}_{up.filename}"
    with open(dest, "wb") as out:
        shutil.copyfileobj(up.file, out)
    return dest


@router.post("/imports/upload")
def upload_file(file: UploadFile = File(...), dataset_type: str = Form(default="sales"),
                db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob, RawUpload
    from app.ingestion.validator import validate_file

    path = _save_upload(file)
    v = validate_file(path)
    meta = v.get("meta", {})
    raw = RawUpload(filename=file.filename or path.name, stored_path=str(path),
                    size_bytes=meta.get("size_bytes", 0), mime=meta.get("mime", ""),
                    checksum_sha256=meta.get("checksum_sha256", ""),
                    status="received" if v["ok"] else "rejected")
    db.add(raw)
    db.commit()
    db.refresh(raw)
    job = ImportJob(upload_id=raw.id, dataset_type=dataset_type,
                    status="uploaded" if v["ok"] else "failed", report=v)
    db.add(job)
    db.commit()
    db.refresh(job)
    return {"success": True, "data": {"upload_id": raw.id, "import_job_id": job.id,
                                     "validation": v, "stored_path": str(path)}}


@router.get("/imports/preview/{job_id}")
def preview(job_id: int, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob, RawUpload
    from app.ingestion.preview import preview_file

    job = db.query(ImportJob).filter_by(id=job_id).first()
    if not job or not job.upload_id:
        return {"success": False, "error": {"message": "import job not found"}}
    raw = db.query(RawUpload).filter_by(id=job.upload_id).first()
    prof = preview_file(raw.stored_path)
    return {"success": True, "data": prof}


@router.post("/imports/mapping/suggest")
def mapping_suggest(payload: dict, _: str = Depends(require_service_auth)) -> dict:
    from app.ingestion.mapper import suggest_mapping

    cols = payload.get("columns", [])
    ds = payload.get("dataset_type", "sales")
    return {"success": True, "data": suggest_mapping(cols, ds)}


@router.post("/imports/mapping")
def mapping_apply(body: MappingRequest, db: Session = Depends(get_db),
                  _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob
    from app.ingestion.mapper import save_template

    if body.import_job_id:
        job = db.query(ImportJob).filter_by(id=body.import_job_id).first()
        if job:
            job.mapping = body.mappings
            db.commit()
    if body.save_as_template:
        save_template(body.save_as_template, body.dataset_type, body.mappings, db)
    return {"success": True, "data": {"mappings": body.mappings}}


@router.get("/imports/quality/{job_id}")
def quality(job_id: int, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob, RawUpload
    from app.ingestion.preview import preview_file
    from app.ingestion.quality import persist_report, run_quality_checks
    from app.ingestion.reader import read_full

    job = db.query(ImportJob).filter_by(id=job_id).first()
    if not job:
        return {"success": False, "error": {"message": "job not found"}}
    raw = db.query(RawUpload).filter_by(id=job.upload_id).first() if job.upload_id else None
    if not raw:
        return {"success": False, "error": {"message": "upload not found"}}
    df = read_full(raw.stored_path)
    res = run_quality_checks(df, job.dataset_type)
    persist_report(db, job_id, res)
    return {"success": True, "data": res}


@router.post("/imports/commit")
def commit(body: dict, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob, RawUpload

    job_id = int(body.get("import_job_id", 0))
    mappings = body.get("mappings", {})
    run_async = bool(body.get("run_async", False))
    job = db.query(ImportJob).filter_by(id=job_id).first()
    if not job:
        return {"success": False, "error": {"message": "job not found"}}
    raw = db.query(RawUpload).filter_by(id=job.upload_id).first() if job.upload_id else None
    if not raw:
        return {"success": False, "error": {"message": "upload not found"}}
    if run_async:
        try:
            from app.workers.tasks import import_file as _task

            _task.delay(raw.stored_path, job.dataset_type, mappings, job_id)
            job.status = "queued"
            db.commit()
            return {"success": True, "data": {"import_job_id": job_id, "status": "queued"}}
        except Exception:
            pass
    from app.ingestion.etl import run_etl

    res = run_etl(raw.stored_path, job.dataset_type, mappings, job_id, db)
    return {"success": True, "data": res}


@router.get("/imports/jobs/{job_id}")
def job_status(job_id: int, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import ImportJob

    job = db.query(ImportJob).filter_by(id=job_id).first()
    if not job:
        return {"success": False, "error": {"message": "job not found"}}
    return {"success": True, "data": {"id": job.id, "status": job.status, "progress": job.progress,
                                      "total_rows": job.total_rows, "processed_rows": job.processed_rows,
                                      "error_rows": job.error_rows, "report": job.report}}
