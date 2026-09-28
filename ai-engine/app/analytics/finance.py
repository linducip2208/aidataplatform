"""Finance analytics: revenue, COGS, expenses, margins."""
from __future__ import annotations

from typing import Any, Dict

import pandas as pd


def finance_summary(sales_df=None, purchases_df=None, expenses_df=None) -> Dict[str, Any]:
    """Return {total_revenue, total_cogs, total_expenses, gross_profit,
    net_profit, margin_pct}. Any argument may be None or empty, in which case
    that component contributes 0.0; the envelope is always fully populated."""
    revenue = 0.0
    if sales_df is not None and not sales_df.empty:
        d = sales_df.copy()
        rcol = next((c for c in d.columns if str(c).lower() in ("revenue", "total", "omzet")), None)
        if rcol is None and "quantity" in d.columns and "selling_price" in d.columns:
            d["revenue"] = pd.to_numeric(d["quantity"], errors="coerce").fillna(0) * pd.to_numeric(d["selling_price"], errors="coerce").fillna(0)
            rcol = "revenue"
        if rcol:
            revenue = float(pd.to_numeric(d[rcol], errors="coerce").fillna(0).sum())
    cogs = 0.0
    if purchases_df is not None and not purchases_df.empty:
        d = purchases_df.copy()
        ccol = next((c for c in d.columns if str(c).lower() in ("cost", "harga beli", "harga pokok")), None)
        if ccol:
            cogs = float(pd.to_numeric(d[ccol], errors="coerce").fillna(0).sum())
        elif "quantity" in d.columns:
            cogs = float(pd.to_numeric(d["quantity"], errors="coerce").fillna(0).sum())
    expenses = 0.0
    if expenses_df is not None and not expenses_df.empty:
        d = expenses_df.copy()
        acol = next((c for c in d.columns if str(c).lower() in ("amount", "nominal", "biaya")), None)
        if acol:
            expenses = float(pd.to_numeric(d[acol], errors="coerce").fillna(0).sum())
    gross = revenue - cogs
    net = gross - expenses
    margin = (net / revenue * 100) if revenue else 0.0
    return {"total_revenue": round(revenue, 2), "total_cogs": round(cogs, 2),
            "total_expenses": round(expenses, 2), "gross_profit": round(gross, 2),
            "net_profit": round(net, 2), "margin_pct": round(margin, 2)}
