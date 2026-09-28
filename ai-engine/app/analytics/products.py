"""Product analytics: ABC analysis, top products."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd


def _prep(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    ren = {}
    for c in list(d.columns):
        lc = str(c).lower()
        if lc in ("nm brg", "nama barang", "nama produk", "barang", "product name"):
            ren[c] = "product_name"
        if lc in ("total", "omzet"):
            ren[c] = "revenue"
        if lc in ("qty", "jml", "jumlah"):
            ren[c] = "quantity"
    d = d.rename(columns=ren)
    if "product_name" not in d.columns:
        d["product_name"] = d.get("product_code", "UNKNOWN").astype(str) if "product_code" in d.columns else "UNKNOWN"
    if "revenue" in d.columns:
        d["revenue"] = pd.to_numeric(d["revenue"], errors="coerce").fillna(0)
    elif "quantity" in d.columns and "selling_price" in d.columns:
        d["revenue"] = pd.to_numeric(d["quantity"], errors="coerce").fillna(0) * pd.to_numeric(d["selling_price"], errors="coerce").fillna(0)
    else:
        d["revenue"] = 0.0
    return d


def abc_analysis(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Return a list of {product, revenue, share_pct, cumulative_pct, grade}
    rows ordered by revenue desc. Empty input yields []."""
    d = _prep(df)
    if d.empty:
        return []
    g = d.groupby("product_name")["revenue"].sum().sort_values(ascending=False)
    total = float(g.sum()) or 1.0
    cum = 0.0
    out = []
    for prod, rev in g.items():
        share = float(rev) / total * 100
        cum += share
        grade = "A" if cum <= 80 else ("B" if cum <= 95 else "C")
        out.append({"product": str(prod), "revenue": round(float(rev), 2),
                    "share_pct": round(share, 2), "cumulative_pct": round(cum, 2), "grade": grade})
    return out


def top_products(df: pd.DataFrame, k: int = 10) -> List[Dict[str, Any]]:
    """Return the first k rows of abc_analysis() in the same shape. Empty input
    yields []."""
    return abc_analysis(df)[: max(0, int(k))]
