"""Model registry service: create/version/promote/load production model."""
from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Optional

import joblib

from app.core.config import settings

FLOW = ["DRAFT", "TRAINING", "VALIDATED", "PRODUCTION", "ARCHIVED", "FAILED"]


def _session(db_session):
    if db_session is None:
        from app.database.connection import SessionLocal

        db = SessionLocal()
        return db, True
    return db_session, False


def create_model(name: str, model_type: str, db_session=None) -> Dict[str, Any]:
    from app.database.models import MLModel

    db, own = _session(db_session)
    try:
        row = db.query(MLModel).filter_by(name=name).first()
        if not row:
            row = MLModel(name=name, model_type=model_type, status="DRAFT")
            db.add(row)
            db.commit()
            db.refresh(row)
        return {"id": row.id, "name": row.name, "model_type": row.model_type, "status": row.status}
    finally:
        if own:
            db.close()


def create_version(model_id: int, metrics: Dict, artifact: Any, version: Optional[str] = None, db_session=None) -> Dict[str, Any]:
    from app.database.models import ModelVersion

    db, own = _session(db_session)
    try:
        n = db.query(ModelVersion).filter_by(model_id=model_id).count() + 1
        ver = version or f"v{n}"
        settings.model_path.mkdir(parents=True, exist_ok=True)
        apath = settings.model_path / f"model_{model_id}_{ver}.joblib"
        try:
            joblib.dump(artifact, apath)
        except Exception:
            apath.write_text("{}")
        row = ModelVersion(model_id=model_id, version=ver, artifact_path=str(apath),
                           metrics=metrics, status="VALIDATED")
        db.add(row)
        db.commit()
        db.refresh(row)
        return {"id": row.id, "version": row.version, "artifact_path": row.artifact_path,
                "metrics": row.metrics, "status": row.status}
    finally:
        if own:
            db.close()


def record_training_run(model_id, version_id, model_type, params, metrics, status="done", db_session=None):
    from app.database.models import TrainingRun

    db, own = _session(db_session)
    try:
        r = TrainingRun(model_id=model_id, version_id=version_id, model_type=model_type,
                        params=params or {}, metrics=metrics or {}, status=status)
        db.add(r)
        db.commit()
        db.refresh(r)
        return r.id
    finally:
        if own:
            db.close()


def promote(model_id: int, version_id: int, to_status: str, db_session=None) -> Dict[str, Any]:
    from app.database.models import MLModel, ModelVersion

    db, own = _session(db_session)
    try:
        v = db.query(ModelVersion).filter_by(id=version_id, model_id=model_id).first()
        if not v:
            raise ValueError("version not found")
        v.status = to_status
        if to_status == "PRODUCTION":
            m = db.query(MLModel).filter_by(id=model_id).first()
            if m:
                m.status = "PRODUCTION"
                m.production_version_id = version_id
        db.commit()
        return {"model_id": model_id, "version_id": version_id, "status": to_status}
    finally:
        if own:
            db.close()


def load_production(name: str, db_session=None) -> Any:
    from app.database.models import MLModel, ModelVersion

    db, own = _session(db_session)
    try:
        m = db.query(MLModel).filter_by(name=name).first()
        if not m or not m.production_version_id:
            # fallback: latest validated
            if not m:
                return None
            v = db.query(ModelVersion).filter_by(model_id=m.id).order_by(ModelVersion.id.desc()).first()
        else:
            v = db.query(ModelVersion).filter_by(id=m.production_version_id).first()
        if not v:
            return None
        try:
            return joblib.load(v.artifact_path)
        except Exception:
            return None
    finally:
        if own:
            db.close()
