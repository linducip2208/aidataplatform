"""Inventory analytics: turnover, stockout risk, dead stock, reorder point."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd


def inventory_health(stock_df: pd.DataFrame, sales_df=None) -> List[Dict[str, Any]]:
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
        s["stock_qty"] = 0
    s["stock_qty"] = pd.to_numeric(s["stock_qty"], errors="coerce").fillna(0)

    avg_sales: Dict[str, float] = {}
    if sales_df is not None and not sales_df.empty:
        d = sales_df.copy()
        for c in list(d.columns):
            lc = str(c).lower()
            if lc in ("nm brg", "nama barang", "nama produk", "product name"):
                d = d.rename(columns={c: "product_name"})
                break
        if "product_name" in d.columns:
            qcol = next((c for c in d.columns if str(c).lower() in ("quantity", "qty", "jml", "jumlah")), None)
            if qcol:
                d[qcol] = pd.to_numeric(d[qcol], errors="coerce").fillna(0)
                # assume 30-day window for daily avg
                avg_sales = (d.groupby("product_name")[qcol].sum() / 30).to_dict()

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
