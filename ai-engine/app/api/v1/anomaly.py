"""Anomaly endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.security import require_service_auth
from app.schemas.ml import AnomalyRequest

router = APIRouter(tags=["anomaly"])


@router.post("/anomaly/detect")
def detect(body: AnomalyRequest, _: str = Depends(require_service_auth)) -> dict:
    from app.ml.anomaly import detect_anomalies

    return {"success": True, "data": detect_anomalies([dict(r) for r in body.series], body.sensitivity)}
