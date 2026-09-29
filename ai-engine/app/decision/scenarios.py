"""Scenario / what-if engine.

Supported types: ``price_change_pct``, ``inventory_change_pct``,
``churn_rise_pp``. Every scenario is computed from AVAILABLE models/data:

* ``price_change_pct`` needs an empirically computable price elasticity from
  the sales frame (quantity + selling_price with variance, >= 8 rows). The
  elasticity is the OLS slope of quantity on price scaled by means. Without
  it the result is explicit ``supported=False`` with reasons — never a
  textbook -1.5 plugged in silently.
* ``inventory_change_pct`` needs a non-empty sales frame with revenue and
  quantity; the delta assumes demand is sufficient (stated in
  ``assumptions``) and scales fulfillable units, priced at observed AOV.
* ``churn_rise_pp`` needs >= 5 distinct customers; the delta assumes churned
  customers stop buying entirely (stated in ``assumptions``).

Impact simulation returns ``deltas`` with ``confidence_interval`` where
computable, else the unsupported shape ``{supported: False, reasons: [...]}``
with NO numeric deltas at all.
"""
from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Tuple

SCENARIO_TYPES = ("price_change_pct", "inventory_change_pct", "churn_rise_pp")

_PARAM_BOUNDS = {
    "price_change_pct": (-50.0, 100.0),
    "inventory_change_pct": (-100.0, 200.0),
    "churn_rise_pp": (0.0, 50.0),
}


def _finite(value: Any, default: float = 0.0) -> float:
    try:
        out = float(value)
    except (TypeError, ValueError):
        return default
    return out if math.isfinite(out) else default


def _unsupported(scenario_type: str, params: Dict[str, Any],
                 reasons: List[str], assumptions: List[str] | None = None) -> Dict[str, Any]:
    return {
        "supported": False,
        "type": scenario_type,
        "params": dict(params or {}),
        "reasons": list(reasons),
        "assumptions": list(assumptions or []),
    }


def _frame_columns(df: Any) -> Dict[str, str]:
    lower = {str(c).strip().lower(): c for c in df.columns}
    def pick(aliases: Tuple[str, ...]) -> Optional[str]:
        for a in aliases:
            if a in lower:
                return lower[a]
        return None
    return {
        "quantity": pick(("quantity", "qty", "jumlah", "jml")) or "",
        "price": pick(("selling_price", "harga jual", "price")) or "",
        "revenue": pick(("revenue", "total", "omzet")) or "",
        "customer": pick(("customer_name", "customer", "nama customer",
                          "pelanggan", "nm customer", "customer_code")) or "",
        "date": pick(("transaction_date", "tanggal transaksi", "tgl",
                      "date", "order date", "tanggal")) or "",
    }


def compute_elasticity(sales_df: Any) -> Tuple[float | None, str]:
    """Estimate price elasticity from the sales frame.

    Returns ``(elasticity, reason)``: elasticity is a finite negative float
    when computable, else ``(None, reason)`` naming why. OLS slope of
    quantity on price, scaled by ``mean_price / mean_qty``. Requires >= 8
    rows, variance in both columns, and a negative slope (a positive slope
    is not a demand curve and is refused rather than used).
    """
    import pandas as pd

    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return None, "empty sales frame"
    if len(sales_df) < 8:
        return None, f"only {len(sales_df)} rows; need >= 8 to estimate elasticity"
    cols = _frame_columns(sales_df)
    if not cols["quantity"] or not cols["price"]:
        return None, ("no price-quantity basis in the sales frame "
                      "(need quantity + selling_price/price columns)")
    q = pd.to_numeric(sales_df[cols["quantity"]], errors="coerce").dropna()
    p = pd.to_numeric(sales_df[cols["price"]], errors="coerce").dropna()
    idx = q.index.intersection(p.index)
    q, p = q.loc[idx].astype(float), p.loc[idx].astype(float)
    if len(q) < 8:
        return None, f"only {len(q)} usable price-quantity pairs; need >= 8"
    mean_q, mean_p = float(q.mean()), float(p.mean())
    if mean_q <= 0 or mean_p <= 0:
        return None, "non-positive mean price or quantity; elasticity undefined"
    if float(q.std()) == 0.0 or float(p.std()) == 0.0:
        return None, "no variance in price or quantity; elasticity undefined"
    var_p = float(p.var())
    if var_p == 0.0:
        return None, "no variance in price; elasticity undefined"
    slope = float(((p - mean_p) * (q - mean_q)).sum() / ((p - mean_p) ** 2).sum())
    if not math.isfinite(slope):
        return None, "regression slope non-finite"
    if slope >= 0:
        return None, ("observed price-quantity slope is non-negative; "
                      "no demand-curve elasticity can be estimated")
    elasticity = slope * mean_p / mean_q
    if not math.isfinite(elasticity):
        return None, "elasticity non-finite"
    return round(elasticity, 4), ""


def _daily_revenue_std(sales_df: Any) -> float:
    try:
        import pandas as pd

        cols = _frame_columns(sales_df)
        if not cols["revenue"] or not cols["date"]:
            return 0.0
        d = sales_df.copy()
        d[cols["date"]] = pd.to_datetime(d[cols["date"]], errors="coerce")
        d[cols["revenue"]] = pd.to_numeric(d[cols["revenue"]], errors="coerce").fillna(0.0)
        d = d.dropna(subset=[cols["date"]])
        if d.empty:
            return 0.0
        daily = d.groupby(d[cols["date"]].dt.date)[cols["revenue"]].sum()
        return float(daily.std()) if len(daily) >= 2 else 0.0
    except Exception:
        return 0.0


def _totals(sales_df: Any) -> Tuple[float, float, int]:
    try:
        import pandas as pd

        cols = _frame_columns(sales_df)
        revenue = float(pd.to_numeric(sales_df[cols["revenue"]],
                                      errors="coerce").fillna(0.0).sum()) if cols["revenue"] else 0.0
        units = float(pd.to_numeric(sales_df[cols["quantity"]],
                                    errors="coerce").fillna(0.0).sum()) if cols["quantity"] else 0.0
        return revenue, units, int(len(sales_df))
    except Exception:
        return 0.0, 0.0, 0


def _run_price_change(params: Dict[str, Any], sales_df: Any) -> Dict[str, Any]:
    pct = _finite(params.get("price_change_pct"), 0.0)
    elasticity, reason = compute_elasticity(sales_df)
    if elasticity is None:
        return _unsupported("price_change_pct", params, [reason],
                            ["No elasticity assumed: refusing to invent one."])
    qty_pct = elasticity * pct
    revenue_pct = ((1.0 + pct / 100.0) * (1.0 + qty_pct / 100.0) - 1.0) * 100.0
    revenue, _, n = _totals(sales_df)
    delta_revenue = revenue * revenue_pct / 100.0
    # CI from residual spread of the qty-on-price fit (80% band, z=1.28).
    try:
        import pandas as pd

        cols = _frame_columns(sales_df)
        q = pd.to_numeric(sales_df[cols["quantity"]], errors="coerce").fillna(0.0).astype(float)
        p = pd.to_numeric(sales_df[cols["price"]], errors="coerce").fillna(0.0).astype(float)
        slope = (elasticity * float(q.mean()) / float(p.mean())) if float(p.mean()) else 0.0
        resid = q - (q.mean() + slope * (p - p.mean()))
        resid_std = float(resid.std()) if len(resid) >= 2 else 0.0
        mean_q = float(q.mean()) or 1.0
        qty_se_pct = (1.28 * resid_std / mean_q * abs(pct) / 100.0 * 100.0) if resid_std else 0.0
        rev_half = abs(delta_revenue) * (qty_se_pct / max(1e-9, abs(qty_pct)) if qty_pct else 0.0)
        rev_half = min(abs(delta_revenue), rev_half) if delta_revenue else 0.0
    except Exception:
        rev_half = 0.0
    confidence = min(0.8, 0.3 + n / 50.0)
    return {
        "supported": True,
        "type": "price_change_pct",
        "params": dict(params),
        "elasticity": elasticity,
        "deltas": {
            "revenue": round(delta_revenue, 2),
            "revenue_pct": round(revenue_pct, 2),
            "units_pct": round(qty_pct, 2),
            "baseline_revenue": round(revenue, 2),
        },
        "confidence_interval": {
            "revenue_lower": round(delta_revenue - rev_half, 2),
            "revenue_upper": round(delta_revenue + rev_half, 2),
        },
        "confidence": round(confidence, 3),
        "assumptions": [
            f"Constant elasticity {elasticity} over the simulated range.",
            "Only price moves; product mix, costs and competition held fixed.",
        ],
    }


def _run_inventory_change(params: Dict[str, Any], sales_df: Any) -> Dict[str, Any]:
    import pandas as pd

    pct = _finite(params.get("inventory_change_pct"), 0.0)
    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return _unsupported("inventory_change_pct", params,
                            ["empty sales frame: no revenue/quantity baseline"],
                            ["No baseline assumed."])
    revenue, units, n = _totals(sales_df)
    if revenue <= 0 or units <= 0:
        return _unsupported("inventory_change_pct", params,
                            ["no positive revenue/units baseline to scale from"],
                            ["Refusing to scale from a zero baseline."])
    if n < 5:
        return _unsupported("inventory_change_pct", params,
                            [f"only {n} rows; need >= 5 for a stable baseline"],
                            ["Small-sample scaling refused."])
    aov_unit = revenue / units
    delta_units = units * pct / 100.0
    delta_revenue = delta_units * aov_unit
    half = 1.28 * _daily_revenue_std(sales_df)
    confidence = min(0.7, 0.3 + n / 50.0)
    return {
        "supported": True,
        "type": "inventory_change_pct",
        "params": dict(params),
        "deltas": {
            "revenue": round(delta_revenue, 2),
            "revenue_pct": round(pct, 2),
            "units": round(delta_units, 2),
            "baseline_revenue": round(revenue, 2),
        },
        "confidence_interval": {
            "revenue_lower": round(delta_revenue - half, 2),
            "revenue_upper": round(delta_revenue + half, 2),
        },
        "confidence": round(confidence, 3),
        "assumptions": [
            "Demand is sufficient to absorb the stock change (fulfillable units scale 1:1).",
            f"Unit economics fixed at observed AOV-per-unit {round(aov_unit, 2)}.",
        ],
    }


def _run_churn_rise(params: Dict[str, Any], sales_df: Any) -> Dict[str, Any]:
    import pandas as pd

    pp = _finite(params.get("churn_rise_pp"), 0.0)
    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return _unsupported("churn_rise_pp", params,
                            ["empty sales frame: no customer baseline"],
                            ["No customer base assumed."])
    cols = _frame_columns(sales_df)
    if not cols["customer"]:
        return _unsupported("churn_rise_pp", params,
                            ["no customer column in the sales frame"],
                            ["Churn cannot be attributed without a customer key."])
    n_customers = int(sales_df[cols["customer"]].astype(str).nunique())
    if n_customers < 5:
        return _unsupported("churn_rise_pp", params,
                            [f"only {n_customers} distinct customers; need >= 5"],
                            ["Small-sample churn scaling refused."])
    revenue, _, n = _totals(sales_df)
    if revenue <= 0:
        return _unsupported("churn_rise_pp", params,
                            ["no positive revenue baseline to scale from"],
                            ["Refusing to scale from a zero baseline."])
    delta_revenue = -pp / 100.0 * revenue
    try:
        rev_col = cols["revenue"]
        per_cust = sales_df.groupby(sales_df[cols["customer"]].astype(str))[rev_col].sum() \
            if rev_col else None
        cust_std = float(pd.to_numeric(per_cust, errors="coerce").fillna(0.0).std()) \
            if per_cust is not None and len(per_cust) >= 2 else 0.0
        half = 1.28 * cust_std * (pp / 100.0) * (n_customers ** 0.5)
    except Exception:
        half = 0.0
    confidence = min(0.7, 0.3 + n_customers / 40.0)
    return {
        "supported": True,
        "type": "churn_rise_pp",
        "params": dict(params),
        "deltas": {
            "revenue": round(delta_revenue, 2),
            "revenue_pct": round(-pp, 2),
            "baseline_revenue": round(revenue, 2),
            "baseline_customers": n_customers,
        },
        "confidence_interval": {
            "revenue_lower": round(delta_revenue - half, 2),
            "revenue_upper": round(delta_revenue + half, 2),
        },
        "confidence": round(confidence, 3),
        "assumptions": [
            "Churned customers stop buying entirely (full revenue loss per churned share).",
            "Churn spreads uniformly across customer spend levels.",
        ],
    }


def run_scenario(
    scenario_type: str,
    params: Optional[Dict[str, Any]] = None,
    subject: Optional[Dict[str, Any]] = None,
    *,
    sales_df: Any = None,
    db_session: Any = None,
) -> Dict[str, Any]:
    """Run one what-if scenario. Raises ``ValueError`` for unknown types or
    out-of-range params; returns the unsupported shape (no deltas) when the
    data cannot support the simulation."""
    key = str(scenario_type or "").strip().lower()
    if key not in SCENARIO_TYPES:
        raise ValueError(
            "scenario type must be one of " + ", ".join(SCENARIO_TYPES)
            + f"; got {scenario_type!r}")
    payload = dict(params or {})
    bounds = _PARAM_BOUNDS[key]
    field = {"price_change_pct": "price_change_pct",
             "inventory_change_pct": "inventory_change_pct",
             "churn_rise_pp": "churn_rise_pp"}[key]
    if field not in payload:
        raise ValueError(f"params.{field} is required for scenario {key!r}")
    try:
        value = float(payload[field])
    except (TypeError, ValueError):
        raise ValueError(f"params.{field} must be a number")
    if not math.isfinite(value):
        raise ValueError(f"params.{field} must be finite")
    if not (bounds[0] <= value <= bounds[1]):
        raise ValueError(
            f"params.{field} must be within [{bounds[0]}, {bounds[1]}]; got {value}")
    payload[field] = value

    frame = sales_df
    if frame is None and db_session is not None:
        try:
            from app.ai.tools import _load_frame
            from app.decision.evidence import normalize_subject

            norm = normalize_subject(subject)
            frame = _load_frame("sales", db_session)
            if not frame.empty and norm.get("branch"):
                try:
                    from app.analytics import sales as sa

                    frame = sa.apply_filters(frame, None, None, norm["branch"], None)
                except Exception:
                    pass
        except Exception:
            frame = None
    if key == "price_change_pct":
        return _run_price_change(payload, frame)
    if key == "inventory_change_pct":
        return _run_inventory_change(payload, frame)
    return _run_churn_rise(payload, frame)
