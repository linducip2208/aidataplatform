"""Inventory analytics: turnover, stockout risk, dead stock, reorder point."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd

# Trailing demand window, in days, and the lead time the reorder point covers.
DEMAND_WINDOW_DAYS = 30
LEAD_TIME_DAYS = 7
# Reported when a product has stock but no demand at all in the window: the
# cover is not demand-limited, so no finite number of days is meaningful.
NO_DEMAND_DAYS = 999.0
# Stockout-risk bands, in days of cover, checked high to low (upper bound
# inclusive). ``app.alerts.rules`` counts "critical" and "high" rows, so these
# four strings are a shared vocabulary and must not be renamed.
RISK_BANDS = ((3.0, "critical"), (7.0, "high"), (21.0, "medium"))

_PRODUCT_ALIASES = ("nm brg", "nama barang", "nama produk", "product name")
_STOCK_ALIASES = ("stok", "stock")
_QUANTITY_ALIASES = ("quantity", "qty", "jml", "jumlah")


def _first_alias(columns: Any, aliases, target: str) -> Any:
    """Return the first column matching ``aliases``, or None.

    A rename onto ``target`` is skipped when the frame already carries that
    exact column: renaming anyway would produce two columns of the same name,
    and every later ``frame[target]`` would then be a DataFrame instead of a
    Series. The already-present column is the canonical one and wins.
    """
    for c in columns:
        if str(c).lower() in aliases:
            return None if (target in columns and target != c) else c
    return None


def _daily_rate(d: pd.DataFrame, qcol: str) -> pd.Series:
    """Units per day over the trailing demand window.

    The window is the last ``DEMAND_WINDOW_DAYS`` days of the frame, so a
    product's rate reflects current demand rather than its whole history. A
    frame that only spans fewer days than the window is divided by the span it
    actually has; dividing a young store's first week by 30 would understate
    every rate by the same factor and make real stockouts look comfortable.
    """
    dcol = next((c for c in d.columns if "date" in str(c).lower()), None)
    span = float(DEMAND_WINDOW_DAYS)
    if dcol:
        ts = pd.to_datetime(d[dcol], errors="coerce")
        keep = ts.notna()
        if not bool(keep.any()):
            return pd.Series(dtype="float64")
        d = d[keep].copy()
        d[dcol] = ts[keep]
        latest = d[dcol].max()
        earliest = d[dcol].min()
        cutoff = latest - pd.Timedelta(days=DEMAND_WINDOW_DAYS)
        d = d[d[dcol] > cutoff]
        if d.empty:  # pragma: no cover - the latest row always survives its own cutoff
            return pd.Series(dtype="float64")
        span = min(float(DEMAND_WINDOW_DAYS),
                   max(1.0, float((latest - earliest).days) + 1.0))
    return d.groupby("product_name")[qcol].sum() / span


def _avg_daily_sales(sales_df: pd.DataFrame) -> Dict[str, float]:
    """Return {product_name: units per day} over the trailing 30-day window.

    A frame with no recognisable product or quantity column yields {}.
    """
    d = sales_df.copy()
    pcol = _first_alias(d.columns, _PRODUCT_ALIASES, "product_name")
    if pcol is not None:
        d = d.rename(columns={pcol: "product_name"})
    if "product_name" not in d.columns:
        return {}
    qcol = next((c for c in d.columns if str(c).lower() in _QUANTITY_ALIASES), None)
    if not qcol:
        return {}
    d[qcol] = pd.to_numeric(d[qcol], errors="coerce").fillna(0)
    return _daily_rate(d, qcol).to_dict()


def _latest_snapshot(s: pd.DataFrame) -> pd.DataFrame:
    """Reduce a stock frame to the newest snapshot of each product.

    ``fact_inventory`` is a snapshot fact: one row per product, warehouse and
    day. Summing every row a frame happens to contain adds up the whole history
    and inflates the cover by the number of snapshots. When the frame carries a
    snapshot date only the newest one counts, summed across the warehouses that
    reported on that date. Without a date column the rows are already taken as
    one current snapshot per product/warehouse pair and are left untouched.
    """
    dcol = next((c for c in s.columns if "date" in str(c).lower()), None)
    if dcol is None or s.empty:
        return s
    ts = pd.to_datetime(s[dcol], errors="coerce")
    dated = s[ts.notna()].copy()
    if dated.empty:
        return s
    dated[dcol] = ts[ts.notna()]
    latest = dated.groupby("product_name")[dcol].transform("max")
    return dated[dated[dcol] == latest]


def _risk_band(days: float) -> str:
    """Return the stockout risk for a number of days of cover."""
    for limit, label in RISK_BANDS:
        if days <= limit:
            return label
    return "low"


def inventory_health(stock_df: pd.DataFrame, sales_df=None) -> List[Dict[str, Any]]:
    """Return a list of {product, stock_qty, avg_daily_sales, days_of_stock,
    turnover, stockout_risk, reorder_point, dead_stock} rows sorted by
    days_of_stock asc. Empty stock input yields [].

    ``days_of_stock`` is stock divided by trailing average daily sales, so it is
    0.0 when there is no stock at all and ``NO_DEMAND_DAYS`` when stock is held
    for a product that did not sell inside the window. Products that have sold
    but are missing from the stock frame cannot appear here: this report is
    driven by the snapshot, not by the sales history.
    """
    if stock_df is None or stock_df.empty:
        return []
    s = stock_df.copy()
    for target, aliases in (("product_name", _PRODUCT_ALIASES), ("stock_qty", _STOCK_ALIASES)):
        col = _first_alias(s.columns, aliases, target)
        if col is not None:
            s = s.rename(columns={col: target})
    if "product_name" not in s.columns:
        s["product_name"] = s.get("product_code", "UNKNOWN").astype(str) if "product_code" in s.columns else "UNKNOWN"
    if "stock_qty" not in s.columns:
        s["stock_qty"] = 0.0
    else:
        s["stock_qty"] = pd.to_numeric(s["stock_qty"], errors="coerce").fillna(0)
    s = _latest_snapshot(s)

    avg_sales: Dict[str, float] = {}
    if sales_df is not None and not sales_df.empty:
        avg_sales = _avg_daily_sales(sales_df)

    out = []
    for prod, grp in s.groupby("product_name"):
        stock = float(grp["stock_qty"].sum())
        ads = float(avg_sales.get(str(prod), 0))
        # No stock is zero days of cover whatever the demand is; a product with
        # no demand in the window is not demand-limited, so it reports the
        # sentinel instead of a division it cannot make.
        if stock <= 0:
            days = 0.0
        elif ads > 0:
            days = stock / ads
        else:
            days = NO_DEMAND_DAYS
        turnover = (ads * DEMAND_WINDOW_DAYS / stock) if stock > 0 else 0.0
        rop = round(ads * LEAD_TIME_DAYS, 2)  # lead-time demand
        out.append({"product": str(prod), "stock_qty": round(stock, 2),
                    "avg_daily_sales": round(ads, 2), "days_of_stock": round(days, 2),
                    "turnover": round(turnover, 2),
                    "stockout_risk": "critical" if stock <= 0 else _risk_band(days),
                    "reorder_point": rop, "dead_stock": bool(ads == 0 and stock > 0)})
    return sorted(out, key=lambda x: x["days_of_stock"])
