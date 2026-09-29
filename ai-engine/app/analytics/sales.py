"""Sales analytics: KPIs, trends from DataFrame or SQL."""
from __future__ import annotations

import math
from typing import Any, Dict, List

import pandas as pd

# pandas 3 still spells the monthly period frequency "M"; "ME" is the rejected alias.
_PERIOD_FREQ = {"daily": "D", "weekly": "W", "monthly": "M"}


def _finite(value, default: float = 0.0) -> float:
    """Coerce ``value`` to a finite float. Returns ``default`` for None, NaN,
    infinity and anything not castable, so a non-float never reaches a field the
    response schema declares as a float."""
    try:
        out = float(value)
    except (TypeError, ValueError):
        return default
    return out if math.isfinite(out) else default


def _total(series, default: float = 0.0) -> float:
    """Sum a column to a finite float. Returns ``default`` when the column is
    absent or empty, which is the zero-row warehouse state."""
    if series is None or len(series) == 0:
        return default
    return _finite(series.sum(), default)


def _norm_sales(df: pd.DataFrame) -> pd.DataFrame:
    """Return a copy of ``df`` carrying the canonical column names the callers
    below read. Numeric columns are coerced to non-null floats and
    ``transaction_date`` to a timezone-naive datetime column (a timezone-aware
    input is normalized to UTC first, the warehouse convention), so the date
    bounds stay comparable. ``revenue`` is derived from
    ``quantity * selling_price - discount`` when it is missing.

    Renaming can map two source columns onto one canonical name; the first wins
    and the rest are dropped, because a duplicated name turns ``d["revenue"]``
    into a DataFrame and every aggregation below into a TypeError. A frame with
    no usable columns comes back carrying only ``revenue``.
    """
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
    d = d.loc[:, ~d.columns.duplicated()]
    if "transaction_date" in d.columns:
        ts = pd.to_datetime(d["transaction_date"], errors="coerce", utc=True)
        d["transaction_date"] = ts.dt.tz_localize(None)
    for col in ("revenue", "quantity", "selling_price", "discount"):
        if col in d.columns:
            d[col] = pd.to_numeric(d[col], errors="coerce").fillna(0)
    if "revenue" not in d.columns:
        if "quantity" in d.columns and "selling_price" in d.columns:
            d["revenue"] = d["quantity"] * d["selling_price"] - d["discount"] \
                if "discount" in d.columns else d["quantity"] * d["selling_price"]
        else:
            d["revenue"] = 0.0
    return d


def _bound(value, side: str) -> "pd.Timestamp | None":
    """Parse one end of a date range into a midnight timestamp.

    Returns None when ``value`` is empty, non-scalar or unparseable, so a bad
    filter never raises and never silently empties the result set — the caller
    leaves that side of the range open instead. ``side`` is ``"from"`` for the
    inclusive lower bound, or ``"to"`` for the exclusive start of the following
    day, which :func:`apply_filters` compares with ``<``. Deriving that as a
    whole day rather than as ``end_of_day`` keeps a ``date_to`` at the far edge
    of the representable range from overflowing."""
    if value is None or (isinstance(value, str) and not value.strip()):
        return None
    try:
        ts = pd.to_datetime(value, errors="coerce", utc=True)
    except (TypeError, ValueError, OverflowError):
        return None
    if not isinstance(ts, pd.Timestamp) or pd.isna(ts):
        return None
    ts = ts.tz_localize(None)
    if side == "from":
        return ts.normalize()
    try:
        end = ts.normalize() + pd.Timedelta(days=1)
    except (OverflowError, ValueError):
        return pd.Timestamp.max
    return end if end <= pd.Timestamp.max else pd.Timestamp.max


def apply_filters(df: pd.DataFrame, date_from=None, date_to=None, branch=None, category=None) -> pd.DataFrame:
    """Filter a sales frame. Returns the filtered DataFrame with the input
    columns; a branch or category naming an absent column, and an unparseable
    date bound, leave the frame unfiltered on that axis rather than raising or
    dropping every row."""
    d = _norm_sales(df)
    if "transaction_date" in d.columns:
        lo = _bound(date_from, "from")
        hi = _bound(date_to, "to")
        if lo is not None:
            d = d[d["transaction_date"] >= lo]
        if hi is not None:
            d = d[d["transaction_date"] < hi]
    if branch and "branch_name" in d.columns:
        d = d[d["branch_name"].astype(str) == str(branch)]
    if category and "category" in d.columns:
        d = d[d["category"].astype(str) == str(category)]
    return d


def sales_kpi(df: pd.DataFrame) -> Dict[str, Any]:
    """Return the KPI envelope {revenue, orders, units, aov, growth_pct, margin_pct}.

    ``orders`` counts fact rows: ``FactSales`` holds one row per line item and no
    order key, so a line count is the closest available order count.
    ``growth_pct`` compares the revenue of the second half of the frame, ordered
    by date, against the first, and is 0.0 when the first half has no revenue.
    ``margin_pct`` is 0.0 by construction — the sales frame carries no cost
    column. An empty frame yields all-zero values, never None and never NaN."""
    d = _norm_sales(df)
    revenue = _total(d.get("revenue"))
    units = _total(d.get("quantity"))
    orders = int(len(d))
    aov = _finite(revenue / orders) if orders else 0.0
    # growth: second half vs first half
    growth = 0.0
    if "transaction_date" in d.columns and orders > 1:
        s = d.sort_values("transaction_date", na_position="last")
        mid = orders // 2
        r1 = _total(s["revenue"].iloc[:mid])
        r2 = _total(s["revenue"].iloc[mid:])
        growth = ((r2 - r1) / r1 * 100) if r1 else 0.0
    return {"revenue": round(revenue, 2), "orders": orders, "units": round(units, 2),
            "aov": round(aov, 2), "growth_pct": round(growth, 2), "margin_pct": 0.0}


def sales_trend(df: pd.DataFrame, granularity: str = "daily") -> List[Dict[str, Any]]:
    """Return a list of {period, revenue, orders, units} rows bucketed by
    granularity ("daily"|"weekly"|"monthly"; any other value falls back to
    "daily"). ``period`` is the string form of the pandas period and the rows
    come back ordered by it. Rows with an unusable transaction_date are dropped.
    ``units`` sums ``quantity``, or is 0.0 when the frame has no such column.
    Empty input yields []."""
    d = _norm_sales(df)
    if "transaction_date" not in d.columns or d.empty:
        return []
    d = d.dropna(subset=["transaction_date"])
    if d.empty:
        return []
    freq = _PERIOD_FREQ.get(str(granularity).lower(), "D")
    d = d.assign(period=d["transaction_date"].dt.to_period(freq),
                 units=d["quantity"] if "quantity" in d.columns else 0.0)
    g = d.groupby("period").agg(revenue=("revenue", "sum"),
                                orders=("revenue", "size"),
                                units=("units", "sum"))
    out = []
    for period, row in g.iterrows():
        out.append({"period": str(period), "revenue": round(_finite(row["revenue"]), 2),
                    "orders": int(row["orders"]), "units": round(_finite(row["units"]), 2)})
    return out
