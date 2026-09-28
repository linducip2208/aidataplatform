"""Recommendation endpoints."""
from __future__ import annotations

import pandas as pd
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ml import RecommendRequest

router = APIRouter(tags=["recommendation"])


@router.post("/recommend")
def recommend(body: RecommendRequest, db: Session = Depends(get_db),
              _: str = Depends(require_service_auth)) -> dict:
    from app.ai.tools import _load_frame
    from app.ml.recommendation import recommend as _rec

    df = _load_frame("sales", db)
    base = df if not df.empty else pd.DataFrame([{"customer": "x", "product": "y"}])
    recs = _rec(base, body.customer_id, body.product_id, body.top_k)
    return {"success": True, "data": recs}
