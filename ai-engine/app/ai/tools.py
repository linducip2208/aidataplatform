"""Tool definitions + implementations. Every tool returns evidence, never hallucinates numbers."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd

TOOL_DEFS = [
    {"type": "function", "function": {"name": "get_kpi", "description": "Get sales KPIs with optional filters",
                                      "parameters": {"type": "object", "properties": {"date_from": {"type": "string"}, "date_to": {"type": "string"}, "branch": {"type": "string"}}}}},
    {"type": "function", "function": {"name": "query_sales", "description": "Query sales trend",
                                      "parameters": {"type": "object", "properties": {"granularity": {"type": "string"}}}}},
    {"type": "function", "function": {"name": "query_inventory", "description": "Inventory health",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_customer", "description": "Customer RFM segments",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_product", "description": "Product ABC analysis",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_finance", "description": "Finance summary",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "get_forecast", "description": "Sales forecast",
                                      "parameters": {"type": "object", "properties": {"horizon": {"type": "integer"}}}}},
    {"type": "function", "function": {"name": "get_anomaly", "description": "Anomaly detection on revenue series",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "get_customer_segment", "description": "Customer segmentation",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "generate_report", "description": "Executive summary report",
                                      "parameters": {"type": "object", "properties": {"period": {"type": "string"}}}}},
]


def _load_frame(table: str, db_session) -> pd.DataFrame:
    if db_session is None:
        return pd.DataFrame()
    try:
        if table == "sales":
            from app.database.models import DimBranch, DimCustomer, DimProduct, FactSales

            q = db_session.query(FactSales.revenue, FactSales.quantity, FactSales.transaction_date,
                                 DimCustomer.customer_name, DimProduct.product_name,
                                 DimBranch.branch_name, DimProduct.category).outerjoin(
                DimCustomer, FactSales.customer_id == DimCustomer.id).outerjoin(
                DimProduct, FactSales.product_id == DimProduct.id).outerjoin(
                DimBranch, FactSales.branch_id == DimBranch.id).limit(5000)
            rows = [{"revenue": r[0] or 0, "quantity": r[1] or 0, "transaction_date": r[2],
                     "customer_name": r[3] or "", "product_name": r[4] or "",
                     "branch_name": r[5] or "", "category": r[6] or ""} for r in q.all()]
            return pd.DataFrame(rows)
        if table == "inventory":
            from app.database.models import DimProduct, FactInventory

            q = db_session.query(FactInventory.stock_qty, DimProduct.product_name).outerjoin(
                DimProduct, FactInventory.product_id == DimProduct.id).limit(5000)
            return pd.DataFrame([{"product_name": r[1] or "", "stock_qty": r[0] or 0} for r in q.all()])
    except Exception:
        return pd.DataFrame()
    return pd.DataFrame()


def execute_tool(name: str, args: Dict[str, Any], db_session=None) -> Dict[str, Any]:
    from app.analytics import sales as sa
    from app.analytics import customers as ca
    from app.analytics import products as pa
    from app.analytics import inventory as ia
    from app.analytics import finance as fa
    from app.analytics import branches as ba

    if name == "get_kpi":
        df = _load_frame("sales", db_session)
        data = sa.sales_kpi(df) if not df.empty else {"revenue": 0.0, "orders": 0, "units": 0.0, "aov": 0.0, "growth_pct": 0.0, "margin_pct": 0.0}
        return {"source": "fact_sales", "data": data}
    if name == "query_sales":
        df = _load_frame("sales", db_session)
        gran = (args or {}).get("granularity", "daily")
        data = sa.sales_trend(df, gran) if not df.empty else []
        return {"source": "fact_sales", "data": data[:60]}
    if name == "query_inventory":
        df = _load_frame("inventory", db_session)
        sdf = _load_frame("sales", db_session)
        data = ia.inventory_health(df, sdf) if not df.empty else []
        return {"source": "fact_inventory", "data": data[:50]}
    if name == "query_customer":
        df = _load_frame("sales", db_session)
        data = ca.rfm(df) if not df.empty else []
        return {"source": "fact_sales:rfm", "data": data[:50]}
    if name == "query_product":
        df = _load_frame("sales", db_session)
        data = pa.abc_analysis(df) if not df.empty else []
        return {"source": "fact_sales:abc", "data": data[:50]}
    if name == "query_finance":
        df = _load_frame("sales", db_session)
        return {"source": "finance", "data": fa.finance_summary(df)}
    if name == "get_forecast":
        from app.ml.forecasting import forecast as _fc

        df = _load_frame("sales", db_session)
        hist = []
        if not df.empty and "transaction_date" in df.columns:
            g = df.groupby(pd.to_datetime(df["transaction_date"]).dt.date)["revenue"].sum()
            hist = [{"date": str(k), "y": float(v)} for k, v in g.items()]
        h = int((args or {}).get("horizon", 30))
        return {"source": "ml.forecast", "data": _fc(hist, h)}
    if name == "get_anomaly":
        from app.ml.anomaly import detect_anomalies

        df = _load_frame("sales", db_session)
        series = []
        if not df.empty and "transaction_date" in df.columns:
            g = df.groupby(pd.to_datetime(df["transaction_date"]).dt.date)["revenue"].sum()
            series = [{"date": str(k), "value": float(v)} for k, v in g.items()]
        return {"source": "ml.anomaly", "data": detect_anomalies(series)}
    if name == "get_customer_segment":
        from app.ml.features import customer_features
        from app.ml.segmentation import segment

        df = _load_frame("sales", db_session)
        if df.empty:
            return {"source": "ml.segment", "data": {}}
        feats = customer_features(df).to_dict("records")
        return {"source": "ml.segment", "data": segment(feats)}
    if name == "generate_report":
        from app.ai.reporting import executive_summary

        return {"source": "reporting", "data": executive_summary(db_session, period=(args or {}).get("period", "weekly"))}
    return {"source": name, "data": {}, "warning": "unknown tool"}
