"""Enterprise KPI engine: registry, thresholds, history, compare, drilldown.

All functions take DataFrames / a warehouse session, are deterministic, and
never invent numbers. Empty input yields zeros / empty lists, never None/NaN.
"""
from __future__ import annotations

import math
import re
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import pandas as pd

_NAME_RE = re.compile(r"^[a-z][a-z0-9_]{1,63}$")

#: Built-in KPI catalogue. DB rows override these by name.
BUILTIN_KPIS: Dict[str, Dict[str, Any]] = {
    "revenue": {
        "description": "Total net revenue: sum(revenue), floored at zero per row.",
        "formula": "sum(revenue)",
        "unit": "IDR",
        "target": 10000000.0,
        "warn_threshold": 8000000.0,
        "crit_threshold": 5000000.0,
        "higher_is_better": True,
    },
    "orders": {
        "description": "Order count: number of sales fact rows (one row per line item).",
        "formula": "count(rows)",
        "unit": "orders",
        "target": 400.0,
        "warn_threshold": 300.0,
        "crit_threshold": 150.0,
        "higher_is_better": True,
    },
    "units": {
        "description": "Units sold: sum(quantity).",
        "formula": "sum(quantity)",
        "unit": "units",
        "target": 900.0,
        "warn_threshold": 700.0,
        "crit_threshold": 400.0,
        "higher_is_better": True,
    },
    "aov": {
        "description": "Average order value: revenue / orders.",
        "formula": "revenue / orders",
        "unit": "IDR",
        "target": 10000.0,
        "warn_threshold": 5000.0,
        "crit_threshold": 2000.0,
        "higher_is_better": True,
    },
    "growth_pct": {
        "description": "Revenue growth: second-half vs first-half revenue in date order.",
        "formula": "(revenue_2h - revenue_1h) / revenue_1h * 100",
        "unit": "%",
        "target": 5.0,
        "warn_threshold": 0.0,
        "crit_threshold": -5.0,
        "higher_is_better": True,
    },
    "margin_pct": {
        "description": "Net margin on revenue: net_profit / total_revenue * 100.",
        "formula": "net_profit / total_revenue * 100",
        "unit": "%",
        "target": 15.0,
        "warn_threshold": 8.0,
        "crit_threshold": 0.0,
        "higher_is_better": True,
    },
    "net_profit": {
        "description": "Net profit: revenue - cogs - expenses.",
        "formula": "revenue - cogs - expenses",
        "unit": "IDR",
        "target": 1500000.0,
        "warn_threshold": 800000.0,
        "crit_threshold": 0.0,
        "higher_is_better": True,
    },
    "gross_profit": {
        "description": "Gross profit: revenue - cogs.",
        "formula": "revenue - cogs",
        "unit": "IDR",
        "target": 3000000.0,
        "warn_threshold": 2000000.0,
        "crit_threshold": 500000.0,
        "higher_is_better": True,
    },
}

_VALID_STATUSES = ("ok", "warn", "crit")
_DRILLDOWN_DIMS = ("branch", "product", "customer")

_EMPLOYEE_ALIASES = (
    "employee", "employee_name", "salesperson", "sales_person",
    "sales_rep", "staff", "kasir", "karyawan", "nama karyawan", "petugas",
)


def _finite(value: Any, default: float = 0.0) -> float:
    try:
        out = float(value)
    except (TypeError, ValueError):
        return default
    return out if math.isfinite(out) else default


def _round(value: Any) -> float:
    return round(_finite(value), 2)


# ------------------------------------------------------------------
# registry
# ------------------------------------------------------------------

def validate_definition(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Validate a KPI definition payload, returning the normalised mapping.

    Raises ``ValueError`` with a human message on any violation.
    """
    if not isinstance(payload, dict):
        raise ValueError("definition must be an object")
    name = str(payload.get("name") or "").strip().lower()
    if not _NAME_RE.match(name):
        raise ValueError(
            "name must match ^[a-z][a-z0-9_]{1,63}$ (lowercase, start with a letter)"
        )
    description = str(payload.get("description") or payload.get("formula_description") or "").strip()
    formula = str(payload.get("formula") or "").strip()
    if not description and not formula:
        raise ValueError("description or formula is required")
    unit = str(payload.get("unit") or "").strip()[:32]

    def _opt_float(key: str) -> Optional[float]:
        raw = payload.get(key)
        if raw is None or (isinstance(raw, str) and not raw.strip()):
            return None
        try:
            out = float(raw)
        except (TypeError, ValueError):
            raise ValueError(f"{key} must be a number")
        if not math.isfinite(out):
            raise ValueError(f"{key} must be finite")
        return out

    target = _opt_float("target")
    warn = _opt_float("warn_threshold")
    if warn is None:
        warn = _opt_float("warn_below")
    if warn is None:
        warn = _opt_float("warn_above")
    crit = _opt_float("crit_threshold")
    if crit is None:
        crit = _opt_float("crit_below")
    if crit is None:
        crit = _opt_float("crit_above")
    higher = payload.get("higher_is_better", True)
    if isinstance(higher, str):
        higher = higher.strip().lower() in ("1", "true", "yes", "y")
    higher_is_better = bool(higher)
    if warn is not None and crit is not None:
        if higher_is_better and crit > warn:
            raise ValueError("for higher_is_better, crit_threshold must be <= warn_threshold")
        if not higher_is_better and warn > crit:
            raise ValueError("for lower_is_better, warn_threshold must be <= crit_threshold")
    active = payload.get("is_active", True)
    if isinstance(active, str):
        active = active.strip().lower() in ("1", "true", "yes", "y")
    return {
        "name": name,
        "description": description or formula,
        "formula": formula or description,
        "unit": unit,
        "target": target,
        "warn_threshold": warn,
        "crit_threshold": crit,
        "higher_is_better": higher_is_better,
        "is_active": bool(active),
    }


def _row_to_dict(row: Any) -> Dict[str, Any]:
    return {
        "name": str(row.name),
        "description": str(row.description or ""),
        "formula": str(row.formula or ""),
        "unit": str(row.unit or ""),
        "target": None if row.target is None else float(row.target),
        "warn_threshold": None if row.warn_threshold is None else float(row.warn_threshold),
        "crit_threshold": None if row.crit_threshold is None else float(row.crit_threshold),
        "higher_is_better": bool(row.higher_is_better),
        "is_active": bool(row.is_active),
    }


def list_definitions(db_session: Any = None) -> List[Dict[str, Any]]:
    """Return built-ins merged with DB rows (DB wins), sorted by name."""
    merged: Dict[str, Dict[str, Any]] = {
        name: {"name": name, **spec} for name, spec in BUILTIN_KPIS.items()
    }
    if db_session is not None:
        try:
            from app.analytics.models import KpiDefinition

            rows = db_session.query(KpiDefinition).all()
        except Exception:
            rows = []
        for row in rows:
            try:
                merged[str(row.name)] = _row_to_dict(row)
            except Exception:
                continue
    return [merged[k] for k in sorted(merged)]


def get_definition(name: Any, db_session: Any = None) -> Optional[Dict[str, Any]]:
    key = str(name or "").strip().lower()
    if not key:
        return None
    if db_session is not None:
        try:
            from app.analytics.models import KpiDefinition

            row = db_session.query(KpiDefinition).filter_by(name=key).first()
            if row is not None:
                return _row_to_dict(row)
        except Exception:
            pass
    spec = BUILTIN_KPIS.get(key)
    if spec is None:
        return None
    return {"name": key, **dict(spec)}


def register_definition(db_session: Any, payload: Dict[str, Any]) -> Dict[str, Any]:
    """Validate and upsert a KPI definition. Requires a session."""
    if db_session is None:
        raise ValueError("db_session is required to register a definition")
    clean = validate_definition(payload)
    from app.analytics.models import KpiDefinition

    row = db_session.query(KpiDefinition).filter_by(name=clean["name"]).first()
    if row is None:
        row = KpiDefinition(name=clean["name"])
        db_session.add(row)
    row.description = clean["description"]
    row.formula = clean["formula"]
    row.unit = clean["unit"]
    row.target = clean["target"]
    row.warn_threshold = clean["warn_threshold"]
    row.crit_threshold = clean["crit_threshold"]
    row.higher_is_better = clean["higher_is_better"]
    row.is_active = clean["is_active"]
    row.updated_at = datetime.now(timezone.utc)
    db_session.flush()
    return _row_to_dict(row)


# ------------------------------------------------------------------
# computation + thresholds
# ------------------------------------------------------------------

def compute_kpi_values(
    sales_df: Any = None,
    purchases_df: Any = None,
    expenses_df: Any = None,
    *,
    db_session: Any = None,
) -> Dict[str, Any]:
    """Compute the KPI value set from warehouse frames (deterministic)."""
    from app.analytics import finance as fa
    from app.analytics import sales as sa

    sales = sales_df if isinstance(sales_df, pd.DataFrame) else pd.DataFrame()
    kpi = sa.sales_kpi(sales) if not sales.empty else {
        "revenue": 0.0, "orders": 0, "units": 0.0,
        "aov": 0.0, "growth_pct": 0.0, "margin_pct": 0.0,
    }
    fin = fa.finance_summary(
        sales if not sales.empty else None,
        purchases_df if isinstance(purchases_df, pd.DataFrame) else None,
        expenses_df if isinstance(expenses_df, pd.DataFrame) else None,
        db_session=db_session,
    )
    values: Dict[str, Any] = {
        "revenue": _round(kpi.get("revenue", 0.0)),
        "orders": int(kpi.get("orders", 0) or 0),
        "units": _round(kpi.get("units", 0.0)),
        "aov": _round(kpi.get("aov", 0.0)),
        "growth_pct": _round(kpi.get("growth_pct", 0.0)),
        "margin_pct": _round(fin.get("margin_pct", 0.0)),
        "total_revenue": _round(fin.get("total_revenue", 0.0)),
        "total_cogs": _round(fin.get("total_cogs", 0.0)),
        "total_expenses": _round(fin.get("total_expenses", 0.0)),
        "gross_profit": _round(fin.get("gross_profit", 0.0)),
        "net_profit": _round(fin.get("net_profit", 0.0)),
    }
    return values


def evaluate_kpi(name: Any, value: Any, definition: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """Evaluate one value against its thresholds. Never raises."""
    key = str(name or "").strip().lower() or "unknown"
    spec = definition or BUILTIN_KPIS.get(key) or {}
    num = _round(value)
    target = spec.get("target")
    target_f = _finite(target) if target is not None else None
    warn = spec.get("warn_threshold")
    crit = spec.get("crit_threshold")
    warn_f: Optional[float] = _finite(warn) if warn is not None else None
    crit_f: Optional[float] = _finite(crit) if crit is not None else None
    if warn is None:
        warn_f = None
    if crit is None:
        crit_f = None
    higher = bool(spec.get("higher_is_better", True))
    status = "ok"
    if higher:
        if crit_f is not None and num < crit_f:
            status = "crit"
        elif warn_f is not None and num < warn_f:
            status = "warn"
    else:
        if crit_f is not None and num > crit_f:
            status = "crit"
        elif warn_f is not None and num > warn_f:
            status = "warn"
    return {
        "name": key,
        "value": num,
        "target": target_f,
        "unit": str(spec.get("unit") or ""),
        "status": status,
        "breach": status != "ok",
        "higher_is_better": higher,
    }


def evaluate_all(values: Dict[str, Any], db_session: Any = None) -> List[Dict[str, Any]]:
    """Evaluate every entry of ``values`` against the merged registry."""
    defs = {d["name"]: d for d in list_definitions(db_session)}
    out = []
    for key in sorted((values or {}).keys()):
        out.append(evaluate_kpi(key, (values or {})[key], defs.get(str(key))))
    return out


# ------------------------------------------------------------------
# history
# ------------------------------------------------------------------

def store_snapshot(
    db_session: Any,
    kpi_name: Any,
    value: Any,
    period: str = "",
    filters: Optional[Dict[str, Any]] = None,
    target: Optional[float] = None,
    status: str = "ok",
    meta: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """Persist one snapshot row and return it as a dict."""
    if db_session is None:
        raise ValueError("db_session is required to store a snapshot")
    from app.analytics.models import KpiSnapshot

    name = str(kpi_name or "").strip().lower() or "unknown"
    st = str(status or "ok").lower()
    if st not in _VALID_STATUSES:
        st = "ok"
    row = KpiSnapshot(
        kpi_name=name,
        value=_round(value),
        target=None if target is None else _finite(target),
        status=st,
        period=str(period or "")[:32],
        filters=dict(filters or {}),
        meta=dict(meta or {}),
        computed_at=datetime.now(timezone.utc),
    )
    db_session.add(row)
    db_session.flush()
    return {
        "id": int(row.id or 0),
        "kpi_name": row.kpi_name,
        "value": float(row.value),
        "target": None if row.target is None else float(row.target),
        "status": row.status,
        "period": row.period,
        "filters": dict(row.filters or {}),
        "meta": dict(row.meta or {}),
        "computed_at": row.computed_at.isoformat() if row.computed_at else "",
    }


def compute_and_store(
    sales_df: Any = None,
    purchases_df: Any = None,
    expenses_df: Any = None,
    *,
    db_session: Any = None,
    period: str = "",
    filters: Optional[Dict[str, Any]] = None,
) -> List[Dict[str, Any]]:
    """Compute all KPI values, evaluate thresholds, persist one row per KPI."""
    if db_session is None:
        raise ValueError("db_session is required to compute snapshots")
    values = compute_kpi_values(sales_df, purchases_df, expenses_df)
    defs = {d["name"]: d for d in list_definitions(db_session)}
    out = []
    filt = dict(filters or {})
    for key in sorted(values.keys()):
        spec = defs.get(key, {})
        ev = evaluate_kpi(key, values[key], spec)
        out.append(store_snapshot(
            db_session, key, values[key], period=period, filters=filt,
            target=ev["target"], status=ev["status"],
            meta={"unit": ev["unit"]},
        ))
    return out


def get_history(
    db_session: Any,
    kpi_name: Any = None,
    period: Any = None,
    limit: int = 100,
) -> List[Dict[str, Any]]:
    """Return snapshot rows newest-first. Empty list when no session/rows."""
    if db_session is None:
        return []
    try:
        from app.analytics.models import KpiSnapshot

        q = db_session.query(KpiSnapshot)
        if kpi_name:
            q = q.filter(KpiSnapshot.kpi_name == str(kpi_name).strip().lower())
        if period:
            q = q.filter(KpiSnapshot.period == str(period)[:32])
        try:
            n = max(1, min(1000, int(limit)))
        except (TypeError, ValueError):
            n = 100
        rows = q.order_by(KpiSnapshot.computed_at.desc(), KpiSnapshot.id.desc()).limit(n).all()
    except Exception:
        return []
    out = []
    for row in rows:
        out.append({
            "id": int(row.id),
            "kpi_name": str(row.kpi_name),
            "value": float(row.value),
            "target": None if row.target is None else float(row.target),
            "status": str(row.status),
            "period": str(row.period or ""),
            "filters": dict(row.filters or {}),
            "meta": dict(row.meta or {}),
            "computed_at": row.computed_at.isoformat() if row.computed_at else "",
        })
    return out


def snapshot_kpis(
    db_session: Any = None,
    period: str = "daily",
    filters: Optional[Dict[str, Any]] = None,
) -> List[Dict[str, Any]]:
    """Compute KPIs from the warehouse and persist snapshots.

    This is the ``bi.snapshot_kpis`` beat entry point documented in
    ``docs/bi.md``: master wires it in Celery beat without touching
    ``app/workers/*``. Never raises on an empty warehouse; without a
    session it returns the evaluated (unp persisted) rows instead.
    """
    filt = dict(filters or {})
    sales = pd.DataFrame()
    if db_session is not None:
        try:
            from app.ai.tools import _load_frame

            sales = _load_frame("sales", db_session)
        except Exception:
            sales = pd.DataFrame()
    values = compute_kpi_values(sales if not sales.empty else None)
    defs = {d["name"]: d for d in list_definitions(db_session)}
    evaluated = []
    for key in sorted(values.keys()):
        spec = defs.get(key, {})
        ev = evaluate_kpi(key, values[key], spec)
        evaluated.append({**ev, "period": str(period or "")})
    if db_session is None:
        return evaluated
    try:
        return compute_and_store(sales if not sales.empty else None, None, None,
                                 db_session=db_session, period=str(period or ""),
                                 filters=filt)
    except Exception:
        return evaluated


# ------------------------------------------------------------------
# comparison periods
# ------------------------------------------------------------------

def compare_kpis(current: Dict[str, Any], previous: Dict[str, Any]) -> Dict[str, Any]:
    """Delta math for two KPI value mappings. Deterministic, no NaN."""
    keys = sorted(set((current or {}).keys()) | set((previous or {}).keys()))
    rows: Dict[str, Dict[str, Any]] = {}
    for key in keys:
        cur = _finite((current or {}).get(key, 0.0))
        prev = _finite((previous or {}).get(key, 0.0))
        delta = cur - prev
        if prev != 0:
            pct = delta / abs(prev) * 100.0
        else:
            pct = 0.0 if cur == 0 else 100.0
        rows[key] = {
            "current": round(cur, 2),
            "previous": round(prev, 2),
            "delta": round(delta, 2),
            "delta_pct": round(_finite(pct), 2),
        }
    return {"kpis": rows}


def compare_periods(
    current_df: Any,
    previous_df: Any,
    purchases_df: Any = None,
    expenses_df: Any = None,
) -> Dict[str, Any]:
    """Compute KPI sets for two frames and return their deltas."""
    cur = compute_kpi_values(current_df, purchases_df, expenses_df)
    prev = compute_kpi_values(previous_df, None, None)
    return compare_kpis(cur, prev)


def compare_by_filter(
    df: Any,
    current_filter: Optional[Dict[str, Any]] = None,
    previous_filter: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """Split one sales frame by two filter blocks and compare the halves."""
    from app.analytics import sales as sa

    frame = df if isinstance(df, pd.DataFrame) else pd.DataFrame()
    cf = dict(current_filter or {})
    pf = dict(previous_filter or {})
    cur = sa.apply_filters(
        frame, cf.get("date_from"), cf.get("date_to"), cf.get("branch"), cf.get("category"),
    ) if not frame.empty else frame
    prev = sa.apply_filters(
        frame, pf.get("date_from"), pf.get("date_to"), pf.get("branch"), pf.get("category"),
    ) if not frame.empty else frame
    return compare_periods(cur, prev)


# ------------------------------------------------------------------
# drill-down / pareto / profitability / employees
# ------------------------------------------------------------------

def _resolve_group_column(df: pd.DataFrame, dimension: str) -> Optional[str]:
    dim = str(dimension or "").strip().lower()
    cols = {str(c).strip().lower(): c for c in df.columns}
    if dim == "branch":
        for alias in ("branch_name", "branch", "nm cabang", "nama cabang", "cabang"):
            if alias in cols:
                return cols[alias]
        return None
    if dim == "product":
        for alias in ("product_name", "product", "nm brg", "nama barang", "nama produk", "barang"):
            if alias in cols:
                return cols[alias]
        if "product_code" in cols:
            return cols["product_code"]
        return None
    if dim == "customer":
        for alias in ("customer_name", "customer", "nama customer", "pelanggan", "nm customer"):
            if alias in cols:
                return cols[alias]
        if "customer_code" in cols:
            return cols["customer_code"]
        return None
    raise ValueError("dimension must be one of: branch, product, customer")


def drilldown(
    df: Any,
    dimension: str,
    metric: str = "revenue",
    limit: int = 50,
) -> List[Dict[str, Any]]:
    """Group a sales frame by branch/product/customer with revenue share.

    Empty input yields []. Unknown dimension raises ``ValueError``.
    """
    dim = str(dimension or "").strip().lower()
    if dim not in _DRILLDOWN_DIMS:
        raise ValueError("dimension must be one of: branch, product, customer")
    if not isinstance(df, pd.DataFrame) or df.empty:
        return []
    d = df.copy()
    gcol = _resolve_group_column(d, dim)
    if gcol is None:
        return []
    # revenue resolution mirrors sales._norm_sales without importing privates
    rcol = next((c for c in d.columns if str(c).strip().lower() in ("revenue", "total", "omzet")), None)
    if rcol is None:
        if "quantity" in [str(c).lower() for c in d.columns] and any(
                str(c).lower() in ("selling_price", "harga jual", "price") for c in d.columns):
            qcol = next(c for c in d.columns if str(c).lower() in ("quantity", "qty", "jumlah", "jml"))
            pcol = next(c for c in d.columns if str(c).lower() in ("selling_price", "harga jual", "price"))
            d["_rev"] = pd.to_numeric(d[qcol], errors="coerce").fillna(0) * pd.to_numeric(d[pcol], errors="coerce").fillna(0)
            rcol = "_rev"
        else:
            d["_rev"] = 0.0
            rcol = "_rev"
    d[rcol] = pd.to_numeric(d[rcol], errors="coerce").fillna(0)
    qcol = next((c for c in d.columns if str(c).strip().lower() in ("quantity", "qty", "jumlah", "jml")), None)
    if qcol is not None:
        d[qcol] = pd.to_numeric(d[qcol], errors="coerce").fillna(0)
    g = d.groupby(d[gcol].astype(str)).agg(
        revenue=(rcol, "sum"),
        orders=(rcol, "size"),
        units=(qcol, "sum") if qcol is not None else (rcol, "size"),
    )
    total = float(g["revenue"].sum())
    try:
        n = max(1, min(500, int(limit)))
    except (TypeError, ValueError):
        n = 50
    out = []
    for label, row in g.iterrows():
        rev = float(row["revenue"])
        units = float(row["units"]) if qcol is not None else 0.0
        out.append({
            "dimension": dim,
            "label": str(label),
            "revenue": round(rev, 2),
            "orders": int(row["orders"]),
            "units": round(units, 2),
            "share_pct": round(rev / total * 100, 2) if total else 0.0,
        })
    out.sort(key=lambda r: r["revenue"], reverse=True)
    _ = metric  # metric is accepted for forward-compat; revenue is the value today
    return out[:n]


def pareto_analysis(rows: List[Dict[str, Any]], value_key: str = "revenue") -> List[Dict[str, Any]]:
    """Add cumulative share + pareto flag to a sorted row list (deterministic)."""
    items = [dict(r) for r in (rows or [])]
    for item in items:
        item[value_key] = _finite(item.get(value_key, 0.0))
    items.sort(key=lambda r: r.get(value_key, 0.0), reverse=True)
    total = sum(r.get(value_key, 0.0) for r in items)
    cum = 0.0
    out = []
    for item in items:
        share = (item.get(value_key, 0.0) / total * 100.0) if total else 0.0
        cum += share
        out.append({
            **item,
            "share_pct": round(share, 2),
            "cumulative_pct": round(cum, 2),
            "pareto": bool(cum <= 80.0),
        })
    return out


def profitability(
    sales_df: Any,
    purchases_df: Any = None,
    expenses_df: Any = None,
    by: str = "product",
) -> Dict[str, Any]:
    """Margin by product/branch. Explicit unsupported when no cost basis exists.

    Never invents a cost: without a per-unit cost column the answer is
    ``{"supported": False, ...}`` with empty rows.
    """
    axis = str(by or "product").strip().lower()
    if axis not in ("product", "branch"):
        raise ValueError("by must be one of: product, branch")
    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return {"supported": True, "by": axis, "rows": [], "reason": ""}
    d = sales_df.copy()
    lower = {str(c).strip().lower(): c for c in d.columns}
    cost_col = next((lower[a] for a in ("cost_price", "unit_cost", "cost", "harga beli", "harga pokok", "cogs") if a in lower), None)
    qty_col = next((lower[a] for a in ("quantity", "qty", "jumlah", "jml") if a in lower), None)
    rev_col = next((lower[a] for a in ("revenue", "total", "omzet") if a in lower), None)
    if cost_col is None or qty_col is None:
        return {
            "supported": False,
            "by": axis,
            "rows": [],
            "reason": "no per-unit cost basis in the sales frame (need quantity + cost_price/unit_cost); refusing to invent COGS",
        }
    d[qty_col] = pd.to_numeric(d[qty_col], errors="coerce").fillna(0)
    d[cost_col] = pd.to_numeric(d[cost_col], errors="coerce").fillna(0)
    if rev_col is None:
        price_col = next((lower[a] for a in ("selling_price", "harga jual", "price") if a in lower), None)
        if price_col is None:
            return {"supported": False, "by": axis, "rows": [],
                    "reason": "no revenue or selling_price column to price margin against"}
        d[price_col] = pd.to_numeric(d[price_col], errors="coerce").fillna(0)
        disc_col = next((lower[a] for a in ("discount", "diskon", "disc") if a in lower), None)
        rev = d[qty_col] * d[price_col]
        if disc_col is not None:
            rev = rev - pd.to_numeric(d[disc_col], errors="coerce").fillna(0)
        d["_rev"] = rev.clip(lower=0.0)
        rev_col = "_rev"
    else:
        d[rev_col] = pd.to_numeric(d[rev_col], errors="coerce").fillna(0)
    d["_cogs"] = (d[qty_col] * d[cost_col]).clip(lower=0.0)
    try:
        gcol = _resolve_group_column(d, axis)
    except ValueError:
        gcol = None
    if gcol is None:
        return {"supported": False, "by": axis, "rows": [],
                "reason": f"no {axis} column in the sales frame"}
    g = d.groupby(d[gcol].astype(str)).agg(revenue=(rev_col, "sum"), cogs=("_cogs", "sum"),
                                            orders=(rev_col, "size"))
    out = []
    for label, row in g.iterrows():
        rev = float(row["revenue"])
        cogs = float(row["cogs"])
        gross = rev - cogs
        margin = (gross / rev * 100.0) if rev else 0.0
        out.append({"label": str(label), "revenue": round(rev, 2), "cogs": round(cogs, 2),
                    "gross_profit": round(gross, 2), "margin_pct": round(_finite(margin), 2),
                    "orders": int(row["orders"])})
    out.sort(key=lambda r: r["gross_profit"], reverse=True)
    _ = (purchases_df, expenses_df)
    return {"supported": True, "by": axis, "rows": out, "reason": ""}


def employee_analytics(df: Any) -> Dict[str, Any]:
    """Per-employee sales when the frame names a salesperson; else unsupported.

    The warehouse fact_sales carries no employee key today, so the common
    outcome is ``supported=False`` with an explicit reason — never zeros
    dressed up as people.
    """
    if not isinstance(df, pd.DataFrame) or df.empty:
        return {"supported": False, "rows": [], "reason": "empty sales frame"}
    lower = {str(c).strip().lower(): c for c in df.columns}
    ecol = next((lower[a] for a in _EMPLOYEE_ALIASES if a in lower), None)
    if ecol is None:
        return {"supported": False, "rows": [],
                "reason": "unsupported: no employee/salesperson column in the sales frame"}
    d = df.copy()
    rcol = next((c for c in d.columns if str(c).strip().lower() in ("revenue", "total", "omzet")), None)
    if rcol is None:
        d["_rev"] = 0.0
        rcol = "_rev"
    d[rcol] = pd.to_numeric(d[rcol], errors="coerce").fillna(0)
    g = d.groupby(d[ecol].astype(str)).agg(revenue=(rcol, "sum"), orders=(rcol, "size"))
    total = float(g["revenue"].sum())
    rows = []
    for label, row in g.iterrows():
        rev = float(row["revenue"])
        rows.append({"employee": str(label), "revenue": round(rev, 2),
                     "orders": int(row["orders"]),
                     "share_pct": round(rev / total * 100, 2) if total else 0.0})
    rows.sort(key=lambda r: r["revenue"], reverse=True)
    return {"supported": True, "rows": rows, "reason": ""}
