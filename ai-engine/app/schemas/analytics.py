"""Analytics schemas."""
from __future__ import annotations

from typing import List, Optional

from pydantic import BaseModel, Field


class AnalyticsFilter(BaseModel):
    date_from: Optional[str] = None
    date_to: Optional[str] = None
    branch: Optional[str] = None
    category: Optional[str] = None
    granularity: str = Field(default="daily")


class KpiResponse(BaseModel):
    revenue: float = 0.0
    orders: int = 0
    units: float = 0.0
    aov: float = 0.0
    growth_pct: float = 0.0
    margin_pct: float = 0.0


class TrendPoint(BaseModel):
    period: str
    revenue: float = 0.0
    orders: int = 0
    units: float = 0.0


class RfmRow(BaseModel):
    customer: str
    recency_days: int = 0
    frequency: int = 0
    monetary: float = 0.0
    r_score: int = 0
    f_score: int = 0
    m_score: int = 0
    segment: str = ""


class AbcRow(BaseModel):
    product: str
    revenue: float = 0.0
    share_pct: float = 0.0
    cumulative_pct: float = 0.0
    grade: str = "C"


class CohortCell(BaseModel):
    cohort: str
    period_offset: int
    retention_pct: float = 0.0
    active_customers: int = 0


class InventoryHealth(BaseModel):
    product: str
    stock_qty: float = 0.0
    avg_daily_sales: float = 0.0
    days_of_stock: float = 0.0
    turnover: float = 0.0
    stockout_risk: str = "low"
    reorder_point: float = 0.0
    dead_stock: bool = False


class BranchKpi(BaseModel):
    branch: str
    revenue: float = 0.0
    orders: int = 0
    share_pct: float = 0.0


class FinanceSummary(BaseModel):
    total_revenue: float = 0.0
    total_cogs: float = 0.0
    total_expenses: float = 0.0
    gross_profit: float = 0.0
    net_profit: float = 0.0
    margin_pct: float = 0.0
