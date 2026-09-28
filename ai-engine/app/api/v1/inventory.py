"""Inventory endpoints."""
from __future__ import annotations

import pandas as pd
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db

router = APIRouter(tags=["inventory"])


@router.post("/inventory/health")
def health(body: dict | None = None, db: Session = Depends(get_db),
            _: str = Depends(require_service_auth)) -> dict:
    from app.ai.tools import _load_frame
    from app.analytics import inventory as ia

    body = body or {}
    if "items" in body:
        stock = pd.DataFrame(body["items"])
        sales = pd.DataFrame(body.get("sales", []))
        sales = sales if not sales.empty else None
    else:
        stock = _load_frame("inventory", db)
        sales = _load_frame("sales", db)
        sales = sales if not sales.empty else None
    data = ia.inventory_health(stock, sales) if not stock.empty else []
    return {"success": True, "data": data}
