"""Inventory analytics: turnover, stockout risk, dead stock, reorder point."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd


def _avg_daily_sales(sales_df: pd.DataFrame) -> Dict[str, float]:
    """Return {product_name: units per day}. Uses the trailing 30 days of the
    supplied history when a date column is present; otherwise falls back to
    total-units/30 and says so by returning a flat rate."""
    d = sales_df.copy()
    for c in list(d.columns):
        if str(c).lower() in ("nm brg", "nama barang", "nama produk", "product name"):
            d = d.rename(columns={c: "product_name"})
            break
    if "product_name" not in d.columns:
        return {}
    qcol = next((c for c in d.columns if str(c).lower() in ("quantity", "qty", "jml", "jumlah")), None)
    if not qcol:
        return {}
    d[qcol] = pd.to_numeric(d[qcol], errors="coerce").fillna(0)
    dcol = next((c for c in d.columns if "date" in str(c).lower()), None)
    if dcol:
        ts = pd.to_datetime(d[dcol], errors="coerce")
        d = d[ts.notna()].copy()
        if d.empty:
            return {}
        d[dcol] = ts[ts.notna()]
        cutoff = d[dcol].max() - pd.Timedelta(days=30)
        windowed = d[d[dcol] > cutoff]
        # Fewer than 30 days of history: use everything rather than a partial window.
        if len(windowed) > 0:
            d = windowed
    return (d.groupby("product_name")[qcol].sum() / 30.0).to_dict()


def inventory_health(stock_df: pd.DataFrame, sales_df=None) -> List[Dict[str, Any]]:
    """Return a list of {product, stock_qty, avg_daily_sales, days_of_stock,
    turnover, stockout_risk, reorder_point, dead_stock} rows sorted by
    days_of_stock asc. Empty stock input yields []."""
    if stock_df is None or stock_df.empty:
        return []
    s = stock_df.copy()
    ren = {}
    for c in list(s.columns):
        lc = str(c).lower()
        if lc in ("nm brg", "nama barang", "nama produk", "product name"):
            ren[c] = "product_name"
        if lc in ("stok", "stock"):
            ren[c] = "stock_qty"
    s = s.rename(columns=ren)
    if "product_name" not in s.columns:
        s["product_name"] = s.get("product_code", "UNKNOWN").astype(str) if "product_code" in s.columns else "UNKNOWN"
    if "stock_qty" not in s.columns:
        s["stock_qty"] = 0.0
    else:
        s["stock_qty"] = pd.to_numeric(s["stock_qty"], errors="coerce").fillna(0)

    avg_sales: Dict[str, float] = {}
    if sales_df is not None and not sales_df.empty:
        avg_sales = _avg_daily_sales(sales_df)

    out = []
    for prod, grp in s.groupby("product_name"):
        stock = float(grp["stock_qty"].sum())
        ads = float(avg_sales.get(str(prod), 0))
        days = stock / ads if ads > 0 else 999.0
        turnover = (ads * 30 / stock) if stock > 0 else 0.0
        if stock <= 0 or days <= 3:
            risk = "critical"
        elif days <= 7:
            risk = "high"
        elif days <= 21:
            risk = "medium"
        else:
            risk = "low"
        rop = round(ads * 7, 2)  # 7-day lead time demand
        out.append({"product": str(prod), "stock_qty": round(stock, 2),
                    "avg_daily_sales": round(ads, 2), "days_of_stock": round(days, 2),
                    "turnover": round(turnover, 2), "stockout_risk": risk,
                    "reorder_point": rop, "dead_stock": bool(ads == 0 and stock > 0)})
    return sorted(out, key=lambda x: x["days_of_stock"])
