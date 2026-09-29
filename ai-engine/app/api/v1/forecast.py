"""Forecast endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

from app.core.errors import build_error_response
from app.core.logging import get_request_id
from app.core.security import require_service_auth
from app.schemas.ml import ForecastRequest, ForecastResponse

router = APIRouter(tags=["forecast"])


@router.post("/forecast", response_model=dict)
def create_forecast(body: ForecastRequest, _: str = Depends(require_service_auth)) -> dict:
    from app.ml.forecasting import forecast

    res = forecast([dict(r) for r in body.history], body.horizon)
    return {"success": True, "data": res}


@router.get("/forecast/domains")
def forecast_domains(_: str = Depends(require_service_auth)) -> dict:
    """Coverage map: every forecastable domain and the value columns it reads.

    All six domains run the same seasonal-naive+GBM forecaster; the domain
    only selects which history column is forecast. Kept as data (not code
    branches) so the list cannot drift from ``FORECAST_DOMAINS``.
    """
    from app.ml.forecasting import FORECAST_DOMAINS

    return {"success": True, "data": {
        "domains": sorted(FORECAST_DOMAINS),
        "value_columns": {domain: list(cols)
                          for domain, cols in sorted(FORECAST_DOMAINS.items())}}}


@router.post("/forecast/{domain}", response_model=dict)
def create_domain_forecast(domain: str, body: ForecastRequest,
                           _: str = Depends(require_service_auth)) -> dict:
    """Forecast one coverage domain: revenue, sales, demand, inventory,
    customers or operational.

    The envelope matches ``POST /forecast``. A history that carries none of
    the domain's columns answers ``method="unsupported_shape"`` with a reason
    naming the expected columns — an explicit non-result, never fabricated
    points.
    """
    from app.ml.forecasting import forecast_for_domain

    try:
        res = forecast_for_domain([dict(r) for r in body.history], body.horizon,
                                  body.granularity, domain)
    except ValueError as exc:
        return JSONResponse(
            status_code=422,
            content=build_error_response(
                module="forecast", operation="create_domain_forecast",
                error_type="validation", code="UNSUPPORTED_DOMAIN",
                message=str(exc), request_id=get_request_id(),
                resolution="Gunakan salah satu domain pada GET /forecast/domains.",
                details={"field": "domain"}),
        )
    return {"success": True, "data": res}
