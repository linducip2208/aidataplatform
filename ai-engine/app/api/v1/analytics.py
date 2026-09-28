"""Analytics endpoints."""
from __future__ import annotations

import pandas as pd
from fastapi import APIRouter, Depends
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
