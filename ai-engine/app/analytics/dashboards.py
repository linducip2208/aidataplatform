"""Dashboard / widget config catalog + resolver.

A dashboard is config only: each widget names an ``endpoint`` (an existing
analytics function), ``params`` for it, and a ``chart_type`` for the UI.
``resolve_*`` executes the widgets against real DataFrames — never fixtures.
"""
from __future__ import annotations

from typing import Any, Dict, List, Optional

import pandas as pd

CHART_TYPES = ("line", "bar", "pie", "table", "stat", "area", "donut")

ENDPOINTS = (
    "kpi", "trend", "rfm", "abc", "cohort", "branches", "finance",
    "compare", "drilldown", "pareto", "profitability",
)


def _widget(dashboard: str, wid: str, title: str, endpoint: str,
            params: Optional[Dict[str, Any]] = None,
            chart_type: str = "table") -> Dict[str, Any]:
    return {"id": f"{dashboard}.{wid}", "title": title, "endpoint": endpoint,
            "params": dict(params or {}), "chart_type": chart_type}


DASHBOARDS: Dict[str, Dict[str, Any]] = {
    "executive": {
        "id": "executive", "title": "Executive",
        "description": "KPIs, trend, branches and finance for leadership.",
        "widgets": [
            _widget("executive", "kpi", "KPI periode", "kpi", {}, "stat"),
            _widget("executive", "trend", "Tren penjualan", "trend", {"granularity": "daily"}, "line"),
            _widget("executive", "branches", "Kinerja cabang", "branches", {}, "bar"),
            _widget("executive", "finance", "Ringkasan keuangan", "finance", {}, "table"),
        ],
    },
    "sales": {
        "id": "sales", "title": "Sales",
        "description": "Revenue KPIs, trend and branch drill-down.",
        "widgets": [
            _widget("sales", "kpi", "KPI penjualan", "kpi", {}, "stat"),
            _widget("sales", "trend", "Tren penjualan", "trend", {"granularity": "daily"}, "area"),
            _widget("sales", "by_branch", "Penjualan per cabang", "drilldown", {"dimension": "branch"}, "bar"),
            _widget("sales", "by_product", "Penjualan per produk", "drilldown", {"dimension": "product"}, "table"),
        ],
    },
    "finance": {
        "id": "finance", "title": "Finance",
        "description": "Profitability and finance summary.",
        "widgets": [
            _widget("finance", "summary", "Ringkasan keuangan", "finance", {}, "table"),
            _widget("finance", "margin_branch", "Margin per cabang", "profitability", {"by": "branch"}, "bar"),
            _widget("finance", "margin_product", "Margin per produk", "profitability", {"by": "product"}, "table"),
            _widget("finance", "kpi", "KPI keuangan", "kpi", {}, "stat"),
        ],
    },
    "customer": {
        "id": "customer", "title": "Customer",
        "description": "RFM segments, cohort retention and customer drill-down.",
        "widgets": [
            _widget("customer", "rfm", "Segmentasi RFM", "rfm", {}, "table"),
            _widget("customer", "cohort", "Retensi cohort", "cohort", {}, "table"),
            _widget("customer", "by_customer", "Penjualan per pelanggan", "drilldown", {"dimension": "customer"}, "bar"),
        ],
    },
    "inventory": {
        "id": "inventory", "title": "Inventory",
        "description": "ABC classification and Pareto of revenue.",
        "widgets": [
            _widget("inventory", "abc", "Klasifikasi ABC", "abc", {}, "table"),
            _widget("inventory", "pareto", "Pareto produk", "pareto", {"group": "product"}, "bar"),
            _widget("inventory", "trend", "Tren penjualan", "trend", {"granularity": "weekly"}, "line"),
        ],
    },
    "operations": {
        "id": "operations", "title": "Operations",
        "description": "Branches, trend and period comparison for ops review.",
        "widgets": [
            _widget("operations", "branches", "Kinerja cabang", "branches", {}, "bar"),
            _widget("operations", "trend", "Tren penjualan", "trend", {"granularity": "weekly"}, "line"),
            _widget("operations", "compare", "Perbandingan periode", "compare", {}, "table"),
        ],
    },
    "marketing": {
        "id": "marketing", "title": "Marketing",
        "description": "Customers, cohorts and product Pareto for campaigns.",
        "widgets": [
            _widget("marketing", "rfm", "Segmentasi RFM", "rfm", {}, "table"),
            _widget("marketing", "pareto", "Pareto produk", "pareto", {"group": "product"}, "pie"),
            _widget("marketing", "cohort", "Retensi cohort", "cohort", {}, "table"),
        ],
    },
    "management": {
        "id": "management", "title": "Management",
        "description": "Board view: KPIs, finance, branches and comparison.",
        "widgets": [
            _widget("management", "kpi", "KPI utama", "kpi", {}, "stat"),
            _widget("management", "finance", "Keuangan", "finance", {}, "table"),
            _widget("management", "branches", "Cabang", "branches", {}, "bar"),
            _widget("management", "compare", "Perbandingan periode", "compare", {}, "table"),
        ],
    },
}


def validate_widget(widget: Dict[str, Any]) -> Dict[str, Any]:
    """Validate one widget mapping. Returns it unchanged or raises ValueError."""
    if not isinstance(widget, dict):
        raise ValueError("widget must be an object")
    for key in ("id", "title", "endpoint", "chart_type"):
        if not str(widget.get(key) or "").strip():
            raise ValueError(f"widget.{key} is required")
    if str(widget["endpoint"]) not in ENDPOINTS:
        raise ValueError(f"unknown endpoint: {widget['endpoint']}")
    if str(widget["chart_type"]) not in CHART_TYPES:
        raise ValueError(f"unknown chart_type: {widget['chart_type']}")
    params = widget.get("params", {})
    if params is None:
        params = {}
    if not isinstance(params, dict):
        raise ValueError("widget.params must be an object")
    return widget


def validate_dashboard(config: Dict[str, Any]) -> Dict[str, Any]:
    """Validate a dashboard config mapping. Returns it or raises ValueError."""
    if not isinstance(config, dict):
        raise ValueError("dashboard must be an object")
    if not str(config.get("id") or "").strip():
        raise ValueError("dashboard.id is required")
    if not str(config.get("title") or "").strip():
        raise ValueError("dashboard.title is required")
    widgets = config.get("widgets")
    if not isinstance(widgets, list) or not widgets:
        raise ValueError("dashboard.widgets must be a non-empty list")
    seen = set()
    for widget in widgets:
        clean = validate_widget(widget)
        if clean["id"] in seen:
            raise ValueError(f"duplicate widget id: {clean['id']}")
        seen.add(clean["id"])
    return config


def get_dashboard(dashboard_id: Any) -> Dict[str, Any]:
    """Return the catalog entry for ``dashboard_id`` or raise ValueError."""
    key = str(dashboard_id or "").strip().lower()
    if key not in DASHBOARDS:
        raise ValueError(f"unknown dashboard: {dashboard_id}")
    return DASHBOARDS[key]


def list_dashboards() -> List[Dict[str, Any]]:
    """Catalog summary without widget detail."""
    return [{"id": d["id"], "title": d["title"], "description": d.get("description", ""),
             "widgets": len(d.get("widgets", []))} for d in DASHBOARDS.values()]


def _apply_filter(df: pd.DataFrame, filt: Optional[Dict[str, Any]]) -> pd.DataFrame:
    if df.empty or not filt:
        return df
    from app.analytics import sales as sa

    return sa.apply_filters(df, filt.get("date_from"), filt.get("date_to"),
                            filt.get("branch"), filt.get("category"))


def resolve_widget(
    widget: Dict[str, Any],
    sales_df: Any = None,
    purchases_df: Any = None,
    expenses_df: Any = None,
    stock_df: Any = None,
    *,
    db_session: Any = None,
    filt: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """Execute one widget against real frames. Validates config first."""
    clean = validate_widget(widget)
    endpoint = str(clean["endpoint"])
    params = dict(clean.get("params") or {})
    filt = dict(filt or {})
    # widget params win over the request filter for granularity/dimension
    granularity = str(params.get("granularity") or filt.get("granularity") or "daily")
    sales = sales_df if isinstance(sales_df, pd.DataFrame) else pd.DataFrame()
    sales = _apply_filter(sales, filt) if not sales.empty else sales

    if endpoint == "kpi":
        from app.analytics import kpi as k

        data = k.compute_kpi_values(sales if not sales.empty else None)
    elif endpoint == "trend":
        from app.analytics import sales as sa

        data = sa.sales_trend(sales, granularity) if not sales.empty else []
    elif endpoint == "rfm":
        from app.analytics import customers as ca

        data = ca.rfm(sales) if not sales.empty else []
    elif endpoint == "abc":
        from app.analytics import products as pa

        data = pa.abc_analysis(sales) if not sales.empty else []
    elif endpoint == "cohort":
        from app.analytics import customers as ca

        data = ca.cohort_retention(sales) if not sales.empty else []
    elif endpoint == "branches":
        from app.analytics import branches as ba

        data = ba.branch_kpi(sales) if not sales.empty else []
    elif endpoint == "finance":
        from app.analytics import finance as fa

        data = fa.finance_summary(sales if not sales.empty else None,
                                  purchases_df if isinstance(purchases_df, pd.DataFrame) else None,
                                  expenses_df if isinstance(expenses_df, pd.DataFrame) else None,
                                  db_session=db_session)
    elif endpoint == "compare":
        from app.analytics import kpi as k

        data = k.compare_kpis(
            k.compute_kpi_values(sales if not sales.empty else None),
            k.compute_kpi_values(None, None, None),
        )
    elif endpoint == "drilldown":
        from app.analytics import kpi as k

        dimension = str(params.get("dimension") or "branch")
        data = k.drilldown(sales, dimension, limit=int(params.get("limit", 50) or 50))
    elif endpoint == "pareto":
        from app.analytics import kpi as k
        from app.analytics import products as pa

        group = str(params.get("group") or "product")
        if group == "product":
            base = pa.abc_analysis(sales) if not sales.empty else []
            data = k.pareto_analysis([{**r, "revenue": r.get("revenue", 0)} for r in base])
        else:
            base = k.drilldown(sales, "branch" if group == "branch" else "customer")
            data = k.pareto_analysis([{**r} for r in base])
    elif endpoint == "profitability":
        from app.analytics import kpi as k

        data = k.profitability(sales if not sales.empty else None, by=str(params.get("by") or "product"))
    else:  # pragma: no cover - validate_widget guards this
        raise ValueError(f"unknown endpoint: {endpoint}")
    _ = stock_df
    return {"id": str(clean["id"]), "title": str(clean["title"]),
            "endpoint": endpoint, "chart_type": str(clean["chart_type"]), "data": data}


def resolve_dashboard(
    dashboard_id: Any,
    sales_df: Any = None,
    purchases_df: Any = None,
    expenses_df: Any = None,
    stock_df: Any = None,
    *,
    db_session: Any = None,
    filt: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """Resolve every widget of a catalog dashboard. Validates config first."""
    entry = get_dashboard(dashboard_id)
    validate_dashboard(entry)
    widgets = []
    for widget in entry["widgets"]:
        widgets.append(resolve_widget(widget, sales_df, purchases_df, expenses_df, stock_df,
                                      db_session=db_session, filt=filt))
    return {"dashboard": entry["id"], "title": entry["title"],
            "description": entry.get("description", ""), "widgets": widgets}
