"""Branch analytics."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd

_BRANCH_ALIASES = ("branch_name", "nm cabang", "nama cabang", "cabang", "branch")
_REVENUE_ALIASES = ("revenue", "total", "omzet")


def _first_alias(columns: Any, aliases) -> Any:
    """Return the first column matching ``aliases``, or None.

    A rename onto ``branch_name`` is skipped when the frame already carries that
    exact column: renaming anyway would leave two columns with the same name and
    every later ``frame["branch_name"]`` would be a DataFrame, not a Series.
    The already-present column is the canonical one and wins.
    """
    for c in columns:
        if str(c).lower() in aliases and not (c != "branch_name" and "branch_name" in columns):
            return c
    return None


def branch_kpi(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Return a list of {branch, revenue, orders, share_pct} rows sorted by
    revenue desc. A frame with no recognisable branch column yields [].

    ``share_pct`` is each branch's share of the revenue in the frame it was
    given, so the column sums to 100 within rounding, and a single branch is
    always 100.0. Revenue is not re-filtered here: the denominator is the total
    of the whole frame, never of a subset.
    """
    if df is None or df.empty:
        return []
    d = df.copy()
    bcol = _first_alias(d.columns, _BRANCH_ALIASES)
    if bcol is None:
        return []
    if bcol != "branch_name":
        d = d.rename(columns={bcol: "branch_name"})
    rcol = next((c for c in d.columns if str(c).lower() in _REVENUE_ALIASES), "revenue")
    if rcol not in d.columns:
        d[rcol] = 0.0
    d[rcol] = pd.to_numeric(d[rcol], errors="coerce").fillna(0)
    g = d.groupby("branch_name").agg(revenue=(rcol, "sum"), orders=(rcol, "size"))
    # A zero-revenue frame has no share to divide: every branch reports 0.0
    # rather than a division by an arbitrary stand-in for the total.
    total = float(g["revenue"].sum())
    out = []
    for br, row in g.iterrows():
        out.append({"branch": str(br), "revenue": round(float(row["revenue"]), 2),
                    "orders": int(row["orders"]),
                    "share_pct": round(float(row["revenue"]) / total * 100, 2) if total else 0.0})
    return sorted(out, key=lambda x: x["revenue"], reverse=True)
