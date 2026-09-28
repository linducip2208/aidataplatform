"""Branch analytics."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd


def branch_kpi(df: pd.DataFrame) -> List[Dict[str, Any]]:
    d = df.copy()
    bcol = next((c for c in d.columns if str(c).lower() in ("branch_name", "nm cabang", "nama cabang", "cabang", "branch")), None)
    if bcol is None:
        return []
    d = d.rename(columns={bcol: "branch_name"})
    rcol = next((c for c in d.columns if str(c).lower() in ("revenue", "total", "omzet")), "revenue")
    if rcol not in d.columns:
        d[rcol] = 0
    d[rcol] = pd.to_numeric(d[rcol], errors="coerce").fillna(0)
    g = d.groupby("branch_name").agg(revenue=(rcol, "sum"), orders=(rcol, "size"))
    total = float(g["revenue"].sum()) or 1.0
    out = []
    for br, row in g.iterrows():
        out.append({"branch": str(br), "revenue": round(float(row["revenue"]), 2),
                    "orders": int(row["orders"]),
                    "share_pct": round(float(row["revenue"]) / total * 100, 2)})
    return sorted(out, key=lambda x: x["revenue"], reverse=True)
