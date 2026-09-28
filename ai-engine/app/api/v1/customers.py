"""Customer endpoints: churn + segmentation."""
from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.security import require_service_auth
from app.schemas.ml import ChurnRequest, SegmentRequest

router = APIRouter(tags=["customers"])


@router.post("/customers/churn")
def churn(body: ChurnRequest, _: str = Depends(require_service_auth)) -> dict:
    from app.ml.churn import predict_churn_proba, train_churn

    res = train_churn([dict(c) for c in body.customers])
    preds = predict_churn_proba(res["model"], res["scaler"], [dict(c) for c in body.customers])
    return {"success": True, "data": {"predictions": preds, "metrics": res["metrics"]}}


@router.post("/customers/segment")
def segment(body: SegmentRequest, _: str = Depends(require_service_auth)) -> dict:
    from app.ml.segmentation import segment as _seg

    res = _seg([dict(c) for c in body.customers], body.n_clusters)
    res.pop("model", None)
    res.pop("scaler", None)
    return {"success": True, "data": res}
