"""Model registry endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db

router = APIRouter(tags=["models"])


@router.get("/models")
def list_models(db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import MLModel

    rows = db.query(MLModel).order_by(MLModel.id.desc()).limit(200).all()
    return {"success": True, "data": [
        {"id": r.id, "name": r.name, "model_type": r.model_type, "status": r.status,
         "production_version_id": r.production_version_id} for r in rows]}


@router.get("/models/{model_id}")
def get_model(model_id: int, db: Session = Depends(get_db), _: str = Depends(require_service_auth)):
    from app.database.models import MLModel, ModelVersion

    m = db.query(MLModel).filter_by(id=model_id).first()
    if not m:
        # A missing resource is a 404. Returning it as 200 with success=false made
        # the Laravel client raise a 422, so "this model does not exist" reached
        # the browser as a validation error. The envelope is kept so
        # AiEngineClient::errorMessage() still finds error.message.
        return JSONResponse(
            status_code=404,
            content={"success": False, "error": {"message": f"model {model_id} not found"}},
        )
    vers = db.query(ModelVersion).filter_by(model_id=model_id).order_by(ModelVersion.id.desc()).all()
    return {"success": True, "data": {
        "id": m.id, "name": m.name, "model_type": m.model_type, "status": m.status,
        "versions": [{"id": v.id, "version": v.version, "status": v.status,
                      "metrics": v.metrics, "artifact_path": v.artifact_path} for v in vers]}}


@router.post("/models/{model_id}/promote")
def promote(model_id: int, body: dict, db: Session = Depends(get_db),
            _: str = Depends(require_service_auth)) -> dict:
    from app.ml import registry as reg

    res = reg.promote(model_id, int(body.get("version_id", 0)), str(body.get("to_status", "PRODUCTION")), db)
    return {"success": True, "data": res}
