"""Forecast endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.security import require_service_auth
from app.schemas.ml import ForecastRequest, ForecastResponse

router = APIRouter(tags=["forecast"])


@router.post("/forecast", response_model=dict)
def create_forecast(body: ForecastRequest, _: str = Depends(require_service_auth)) -> dict:
    from app.ml.forecasting import forecast

    res = forecast([dict(r) for r in body.history], body.horizon)
    return {"success": True, "data": res}
