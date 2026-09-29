"""Analytics endpoints.

The first seven routes (kpi/trend/rfm/abc/cohort/branches/finance) are the
original contract and are byte-identical in behaviour. Everything below the
``ENTERPRISE BI`` marker is additive.
"""
from __future__ import annotations

from typing import Any, Dict, List, Optional

import pandas as pd
from fastapi import APIRouter, Depends, Query, Response
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.analytics import AnalyticsFilter

router = APIRouter(tags=["analytics"])


def _sales_df(db: Session) -> pd.DataFrame:
    from app.ai.tools import _load_frame

    return _load_frame("sales", db)


@router.post("/analytics/kpi")
def kpi(f: AnalyticsFilter, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import sales as sa

    df = _sales_df(db)
    df = sa.apply_filters(df, f.date_from, f.date_to, f.branch, f.category) if not df.empty else df
    return {"success": True, "data": sa.sales_kpi(df) if not df.empty else
            {"revenue": 0.0, "orders": 0, "units": 0.0, "aov": 0.0, "growth_pct": 0.0, "margin_pct": 0.0}}


@router.post("/analytics/trend")
def trend(f: AnalyticsFilter, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import sales as sa

    df = _sales_df(db)
    df = sa.apply_filters(df, f.date_from, f.date_to, f.branch, f.category) if not df.empty else df
    return {"success": True, "data": sa.sales_trend(df, f.granularity) if not df.empty else []}


@router.post("/analytics/rfm")
def rfm(f: AnalyticsFilter, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import customers as ca
    from app.analytics import sales as sa

    df = _sales_df(db)
    df = sa.apply_filters(df, f.date_from, f.date_to, f.branch, f.category) if not df.empty else df
    return {"success": True, "data": ca.rfm(df) if not df.empty else []}


@router.post("/analytics/abc")
def abc(f: AnalyticsFilter, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import products as pa
    from app.analytics import sales as sa

    df = _sales_df(db)
    df = sa.apply_filters(df, f.date_from, f.date_to, f.branch, f.category) if not df.empty else df
    return {"success": True, "data": pa.abc_analysis(df) if not df.empty else []}


@router.post("/analytics/cohort")
def cohort(f: AnalyticsFilter, db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import customers as ca

    df = _sales_df(db)
    return {"success": True, "data": ca.cohort_retention(df) if not df.empty else []}


@router.get("/analytics/branches")
def branches(db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import branches as ba

    df = _sales_df(db)
    return {"success": True, "data": ba.branch_kpi(df) if not df.empty else []}


@router.get("/analytics/finance")
def finance(db: Session = Depends(get_db), _: str = Depends(require_service_auth)) -> dict:
    from app.analytics import finance as fa

    df = _sales_df(db)
    return {"success": True, "data": fa.finance_summary(df)}


# ------------------------------------------------------------------
# ENTERPRISE BI (additive; existing routes above are untouched)
# ------------------------------------------------------------------

class KpiDefinitionIn(BaseModel):
    name: str = Field(default="")
    description: str = Field(default="")
    formula: str = Field(default="")
    unit: str = Field(default="")
    target: Optional[float] = None
    warn_threshold: Optional[float] = None
    crit_threshold: Optional[float] = None
    higher_is_better: bool = True
    is_active: bool = True


class KpiComputeIn(BaseModel):
    period: str = Field(default="daily")
    filter: Dict[str, Any] = Field(default_factory=dict)


class CompareIn(BaseModel):
    current: Dict[str, Any] = Field(default_factory=dict)
    previous: Dict[str, Any] = Field(default_factory=dict)


class DrilldownIn(BaseModel):
    dimension: str = Field(default="branch")
    metric: str = Field(default="revenue")
    filter: Dict[str, Any] = Field(default_factory=dict)
    limit: int = Field(default=50)


class DashboardResolveIn(BaseModel):
    dashboard: str = Field(default="executive")
    filter: Dict[str, Any] = Field(default_factory=dict)


class ExportIn(BaseModel):
    format: str = Field(default="csv")
    dataset: str = Field(default="")
    filter: Dict[str, Any] = Field(default_factory=dict)
    rows: Optional[List[Dict[str, Any]]] = None
    columns: Optional[List[str]] = None
    filename: str = Field(default="export")


def _filtered_sales(db: Session, filt: Dict[str, Any]) -> pd.DataFrame:
    from app.analytics import sales as sa

    df = _sales_df(db)
    if df.empty:
        return df
    return sa.apply_filters(df, filt.get("date_from"), filt.get("date_to"),
                            filt.get("branch"), filt.get("category"))


@router.post("/analytics/kpi/definitions")
def kpi_register_definition(
    body: KpiDefinitionIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from fastapi import HTTPException

    from app.analytics import kpi as k

    try:
        saved = k.register_definition(db, body.model_dump())
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    db.commit()
    return {"success": True, "data": saved}


@router.get("/analytics/kpi/definitions")
def kpi_list_definitions(
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from app.analytics import kpi as k

    return {"success": True, "data": k.list_definitions(db)}


@router.post("/analytics/kpi/compute")
def kpi_compute(
    body: KpiComputeIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from app.analytics import kpi as k

    filt = dict(body.filter or {})
    df = _filtered_sales(db, filt)
    rows = k.compute_and_store(
        df if not df.empty else None, None, None,
        db_session=db, period=str(body.period or "daily"), filters=filt,
    )
    db.commit()
    return {"success": True, "data": rows}


@router.get("/analytics/kpi/history")
def kpi_history(
    kpi_name: Optional[str] = Query(default=None),
    period: Optional[str] = Query(default=None),
    limit: int = Query(default=100, ge=1, le=1000),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from app.analytics import kpi as k

    return {"success": True, "data": k.get_history(db, kpi_name, period, limit)}


@router.post("/analytics/compare")
def compare(
    body: CompareIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from app.analytics import kpi as k

    df = _sales_df(db)
    return {"success": True, "data": k.compare_by_filter(
        df, dict(body.current or {}), dict(body.previous or {}))}


@router.post("/analytics/drilldown")
def drilldown(
    body: DrilldownIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from fastapi import HTTPException

    from app.analytics import kpi as k

    filt = dict(body.filter or {})
    df = _filtered_sales(db, filt)
    try:
        rows = k.drilldown(df, body.dimension, body.metric, body.limit)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    return {"success": True, "data": rows}


@router.post("/analytics/dashboards/resolve")
def dashboards_resolve(
    body: DashboardResolveIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> dict:
    from fastapi import HTTPException

    from app.analytics import dashboards as d

    filt = dict(body.filter or {})
    df = _filtered_sales(db, filt)
    try:
        resolved = d.resolve_dashboard(
            body.dashboard, df if not df.empty else None,
            None, None, None, db_session=db, filt=filt,
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    return {"success": True, "data": resolved}


@router.post("/analytics/export")
def export_dataset(
    body: ExportIn,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Response:
    from fastapi import HTTPException

    from app.analytics import exports as x

    kind = str(body.format or "").strip().lower()
    if kind not in ("csv", "xlsx"):
        raise HTTPException(status_code=422, detail="format must be one of: csv, xlsx")
    rows: Optional[List[Dict[str, Any]]] = body.rows
    if rows is None:
        dataset = str(body.dataset or "").strip().lower()
        filt = dict(body.filter or {})
        df = _filtered_sales(db, filt)
        if dataset in ("", "kpi"):
            from app.analytics import kpi as k

            values = k.compute_kpi_values(df if not df.empty else None)
            rows = [{"kpi": name, "value": values[name]} for name in sorted(values)]
        elif dataset == "trend":
            from app.analytics import sales as sa

            rows = sa.sales_trend(df, str(filt.get("granularity") or "daily")) if not df.empty else []
        elif dataset == "rfm":
            from app.analytics import customers as ca

            rows = ca.rfm(df) if not df.empty else []
        elif dataset == "abc":
            from app.analytics import products as pa

            rows = pa.abc_analysis(df) if not df.empty else []
        elif dataset == "cohort":
            from app.analytics import customers as ca

            rows = ca.cohort_retention(df) if not df.empty else []
        elif dataset == "branches":
            from app.analytics import branches as ba

            rows = ba.branch_kpi(df) if not df.empty else []
        elif dataset == "finance":
            from app.analytics import finance as fa

            fin = fa.finance_summary(df if not df.empty else None)
            rows = [{"metric": name, "value": fin[name]} for name in sorted(fin)]
        elif dataset == "drilldown":
            from app.analytics import kpi as k

            rows = k.drilldown(df, str(filt.get("dimension") or "branch"))
        else:
            raise HTTPException(status_code=422, detail=f"unknown dataset: {body.dataset}")
    try:
        content, media_type, filename = x.export_rows(rows or [], kind, body.columns, body.filename)
    except ValueError as exc:
        from fastapi import HTTPException as _H

        raise _H(status_code=422, detail=str(exc))
    headers = {"Content-Disposition": f'attachment; filename="{filename}"'}
    return Response(content=content, media_type=media_type, headers=headers)
