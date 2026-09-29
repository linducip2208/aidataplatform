"""Model registry endpoints."""
from __future__ import annotations

from typing import Any, Dict, List

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.ml.registry import FLOW

router = APIRouter(tags=["models"])

# `promote` writes `to_status` straight into `model_versions.status`, so an
# unvalidated string would store a state the registry flow has no meaning for.
# The engine's own states are FLOW; STAGED is accepted because
# `Api\MlController` still offers it (see docs/troubleshooting.md), but it
# stays outside FLOW and is only ever a label.
PROMOTABLE_STATUSES = frozenset(FLOW) | {"STAGED"}


class PromoteRequest(BaseModel):
    """Body of `POST /models/{model_id}/promote`.

    Declared here rather than as a bare `dict` so a non-integer or out-of-range
    `version_id` is rejected by validation as a 422 instead of reaching
    `registry.promote` and raising `ValueError` as an unhandled 500.
    """

    version_id: int = Field(ge=1)
    to_status: str = Field(default="PRODUCTION")


def _error(status_code: int, operation: str, message: str, *, code: str,
           error_type: str, resolution: str) -> JSONResponse:
    """A not-found / bad-request as a real status inside the engine envelope.

    `AiEngineClient::errorMessage()` reads `error.message` first, so the
    envelope has to be the body itself; a bare `HTTPException` would serialise
    as `{"detail": ...}` and the Laravel client would fall back to a generic
    "rejected the request payload" string. Returning a 404 rather than a
    200-with-`success: false` is what makes "this model does not exist" reach
    the browser as a not-found instead of a 422.
    """
    return JSONResponse(
        status_code=status_code,
        content=build_error_response(
            module="models",
            operation=operation,
            error_type=error_type,
            code=code,
            message=message,
            request_id=get_request_id(),
            resolution=resolution,
        ),
    )


@router.get("/models")
def list_models(db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.database.models import MLModel

    rows = db.query(MLModel).order_by(MLModel.id.desc()).limit(200).all()
    return {"success": True, "data": [
        {"id": r.id, "name": r.name, "model_type": r.model_type, "status": r.status,
         "production_version_id": r.production_version_id} for r in rows]}


@router.get("/models/{model_id}")
def get_model(model_id: int, db: Session = Depends(get_db),
              _: str = Depends(require_service_auth)):
    from app.database.models import MLModel, ModelVersion

    m = db.query(MLModel).filter_by(id=model_id).first()
    if not m:
        return _error(404, "get_model", f"model {model_id} not found",
                      code="NOT_FOUND", error_type="not_found",
                      resolution="Pastikan ID model benar.")
    vers: List[Any] = db.query(ModelVersion).filter_by(model_id=model_id).order_by(
        ModelVersion.id.desc()).all()
    return {"success": True, "data": {
        "id": m.id, "name": m.name, "model_type": m.model_type, "status": m.status,
        "versions": [{"id": v.id, "version": v.version, "status": v.status,
                      "metrics": v.metrics, "artifact_path": v.artifact_path} for v in vers]}}


@router.post("/models/{model_id}/promote")
def promote(model_id: int, body: PromoteRequest, db: Session = Depends(get_db),
            _: str = Depends(require_service_auth)):
    from app.ml import registry as reg

    to_status = str(body.to_status).strip().upper()
    if to_status not in PROMOTABLE_STATUSES:
        return _error(422, "promote",
                      f"unsupported to_status {to_status!r}; expected one of: "
                      + ", ".join(sorted(PROMOTABLE_STATUSES)),
                      code="INVALID_STATUS", error_type="validation",
                      resolution="Gunakan status dari alur registry.")
    try:
        res = reg.promote(model_id, int(body.version_id), to_status, db)
    except ValueError:
        # registry.promote raises this when the version does not belong to the
        # model. Unhandled it became a 500; it is a missing resource, so it has
        # to be a 404 in the same envelope `GET /models/{id}` uses.
        return _error(404, "promote",
                      f"version {body.version_id} not found for model {model_id}",
                      code="NOT_FOUND", error_type="not_found",
                      resolution="Pastikan ID versi milik model tersebut.")
    return {"success": True, "data": res}
