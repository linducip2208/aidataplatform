"""Sales analytics: KPIs, trends from DataFrame or SQL."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd


def _norm_sales(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    # unify column names
    rename = {}
    for c in list(d.columns):
        lc = str(c).lower()
        if lc in ("tanggal transaksi", "tgl", "date", "order date"):
            rename[c] = "transaction_date"
        if lc in ("total", "omzet"):
            rename[c] = "revenue"
        if lc in ("qty", "jml", "jumlah"):
            rename[c] = "quantity"
        if lc in ("harga jual", "price"):
            rename[c] = "selling_price"
    d = d.rename(columns=rename)
    if "transaction_date" in d.columns:
        d["transaction_date"] = pd.to_datetime(d["transaction_date"], errors="coerce")
    for col in ("revenue", "quantity", "selling_price", "discount"):
        if col in d.columns:
            d[col] = pd.to_numeric(d[col], errors="coerce").fillna(0)
    if "revenue" not in d.columns:
        if "quantity" in d.columns and "selling_price" in d.columns:
            d["revenue"] = d["quantity"] * d["selling_price"] - d.get("discount", 0)
        else:
            d["revenue"] = 0.0
    return d


def _bound(value, side: str):
    """Parse a date filter bound. Returns None when unparseable so a bad
    string never silently empties the result set."""
    ts = pd.to_datetime(value, errors="coerce")
    if pd.isna(ts):
        return None
    return ts if side == "from" else ts + pd.Timedelta(days=1) - pd.Timedelta(nanoseconds=1)


def apply_filters(df: pd.DataFrame, date_from=None, date_to=None, branch=None, category=None) -> pd.DataFrame:
    """Filter a sales frame. Returns the filtered DataFrame; unparseable date
    bounds are ignored rather than raising or dropping every row."""
    d = _norm_sales(df)
    if "transaction_date" in d.columns:
        lo = _bound(date_from, "from") if date_from else None
        hi = _bound(date_to, "to") if date_to else None
        if lo is not None:
            d = d[d["transaction_date"] >= lo]
        if hi is not None:
            d = d[d["transaction_date"] <= hi]
    if branch and "branch_name" in d.columns:
        d = d[d["branch_name"].astype(str) == str(branch)]
    if category and "category" in d.columns:
        d = d[d["category"].astype(str) == str(category)]
    return d


def sales_kpi(df: pd.DataFrame) -> Dict[str, Any]:
    """Return the KPI envelope {revenue, orders, units, aov, growth_pct, margin_pct}.
    An empty frame yields all-zero values, never None."""
    d = _norm_sales(df)
    revenue = float(d["revenue"].sum()) if "revenue" in d.columns else 0.0
    orders = int(len(d))
    units = float(d["quantity"].sum()) if "quantity" in d.columns else 0.0
    aov = revenue / orders if orders else 0.0
    # growth: second half vs first half
    growth = 0.0
    if "transaction_date" in d.columns and len(d) > 1:
        s = d.sort_values("transaction_date")
        mid = len(s) // 2
        r1 = float(s.iloc[:mid]["revenue"].sum())
        r2 = float(s.iloc[mid:]["revenue"].sum())
        growth = ((r2 - r1) / r1 * 100) if r1 else 0.0
    return {"revenue": round(revenue, 2), "orders": orders, "units": round(units, 2),
            "aov": round(aov, 2), "growth_pct": round(growth, 2), "margin_pct": 0.0}


def sales_trend(df: pd.DataFrame, granularity: str = "daily") -> List[Dict[str, Any]]:
    """Return a list of {period, revenue, orders, units} rows bucketed by
    granularity ("daily"|"weekly"|"monthly"). Empty input yields []."""
    d = _norm_sales(df)
    if "transaction_date" not in d.columns or d.empty:
        return []
    d = d.dropna(subset=["transaction_date"]).copy()
    period_freq = {"daily": "D", "weekly": "W", "monthly": "M"}.get(granularity, "D")
    d["period"] = d["transaction_date"].dt.to_period(period_freq)
    g = d.groupby("period").agg(revenue=("revenue", "sum"),
                                orders=("revenue", "size"),
                                units=("quantity", "sum") if "quantity" in d.columns else ("revenue", "size"))
    out = []
    for period, row in g.iterrows():
        out.append({"period": str(period), "revenue": round(float(row["revenue"]), 2),
                    "orders": int(row["orders"]), "units": round(float(row["units"]), 2)})
    return out
