"""Training endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ml import PredictRequest, TrainRequest

router = APIRouter(tags=["training"])


@router.post("/training/train")
def train(body: TrainRequest, db: Session = Depends(get_db),
          _: str = Depends(require_service_auth)) -> dict:
    from app.ml.training import train_model

    res = train_model(body.model_type, body.name, dict(body.params or {}),
                      [dict(r) for r in (body.dataset or [])], db)
    return {"success": True, "data": res}


@router.post("/training/predict")
def predict(body: PredictRequest, db: Session = Depends(get_db),
            _: str = Depends(require_service_auth)) -> dict:
    mt = body.model_type.lower()
    payload = dict(body.payload or {})
    if mt == "forecast":
        from app.ml.forecasting import forecast

        hist = payload.get("history", [])
        h = int(payload.get("horizon", 30))
        return {"success": True, "data": forecast(hist, h)}
    if mt == "churn":
        from app.ml import registry as reg
        from app.ml.churn import predict_churn_proba

        artifact = reg.load_production(body.model_name or "churn-model", db)
        if not artifact:
            return {"success": False, "error": {"message": "no production churn model"}}
        rows = payload.get("customers", [payload])
        return {"success": True, "data": predict_churn_proba(artifact["model"], artifact["scaler"], rows)}
    if mt == "anomaly":
        from app.ml.anomaly import detect_anomalies

        return {"success": True, "data": detect_anomalies(payload.get("series", []),
                                                          float(payload.get("sensitivity", 2.5)))}
    return {"success": False, "error": {"message": f"unsupported model_type {mt}"}}
