"""Evidence aggregation for the decision engine.

Collects KPI context + anomaly context + forecast context + ML prediction
context for a subject (e.g. ``{dataset_ref, branch, period}``) by calling the
existing analytics/ML functions in-process (imports are local and read-only).

Every evidence item has the shape::

    {id, kind, source, metrics, observed_at, confidence, status, reason}

* ``status`` is ``"available"`` or ``"unavailable"``.
* An unavailable source carries ``confidence: 0.0`` and a non-empty
  ``reason``; its ``metrics`` hold no business numbers (empty dict).
* Nothing is ever fabricated: an empty warehouse yields unavailable items,
  not zeros dressed up as measurements.
"""
from __future__ import annotations

import math
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

EVIDENCE_KINDS = ("kpi", "anomaly", "forecast", "ml_prediction")


def _utcnow_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _finite(value: Any, default: float = 0.0) -> float:
    try:
        out = float(value)
    except (TypeError, ValueError):
        return default
    return out if math.isfinite(out) else default


def normalize_subject(subject: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    """Normalise a raw subject mapping. Never raises; never invents values."""
    raw = dict(subject or {})
    granularity = str(raw.get("granularity") or "daily").strip().lower()
    if granularity not in ("daily", "weekly", "monthly"):
        granularity = "daily"
    try:
        horizon = int(raw.get("horizon", 7))
    except (TypeError, ValueError):
        horizon = 7
    horizon = max(1, min(365, horizon))
    return {
        "dataset_ref": str(raw.get("dataset_ref") or "")[:256],
        "branch": str(raw.get("branch") or "")[:128],
        "period": str(raw.get("period") or "")[:32],
        "granularity": granularity,
        "horizon": horizon,
    }


def _load_sales_frame(db_session: Any = None, branch: str = ""):
    """Load the warehouse sales frame, filtered by branch when possible.

    Returns an empty DataFrame when there is no session, no rows, or a read
    failure. The caller turns emptiness into ``unavailable`` evidence.
    """
    import pandas as pd

    if db_session is None:
        return pd.DataFrame()
    try:
        from app.ai.tools import _load_frame

        df = _load_frame("sales", db_session)
    except Exception:
        return pd.DataFrame()
    if df is None or getattr(df, "empty", True):
        return pd.DataFrame()
    if branch:
        try:
            from app.analytics import sales as sa

            df = sa.apply_filters(df, None, None, branch, None)
        except Exception:
            pass
    return df


def _item(
    seq: int,
    kind: str,
    source: str,
    metrics: Dict[str, Any],
    confidence: float,
    status: str,
    reason: str = "",
) -> Dict[str, Any]:
    conf = _finite(confidence, 0.0)
    conf = max(0.0, min(1.0, conf))
    return {
        "id": f"ev-{seq:04d}",
        "kind": kind,
        "source": source,
        "metrics": dict(metrics or {}),
        "observed_at": _utcnow_iso(),
        "confidence": round(conf, 3),
        "status": status,
        "reason": str(reason or ""),
    }


def _kpi_context(sales_df: Any, seq: int) -> Dict[str, Any]:
    import pandas as pd

    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return _item(seq, "kpi", "analytics.kpi.compute_kpi_values", {}, 0.0,
                     "unavailable", "empty sales frame: no rows in the window")
    try:
        from app.analytics import kpi as ka

        values = ka.compute_kpi_values(sales_df)
        evaluations = ka.evaluate_all(values)
    except Exception as exc:
        return _item(seq, "kpi", "analytics.kpi.compute_kpi_values", {}, 0.0,
                     "unavailable", f"kpi computation failed: {type(exc).__name__}")
    n_rows = int(len(sales_df))
    confidence = min(1.0, n_rows / 30.0)
    statuses = {str(e.get("name")): str(e.get("status")) for e in evaluations}
    return _item(seq, "kpi", "analytics.kpi.compute_kpi_values",
                 {"values": values, "statuses": statuses,
                  "n_rows": n_rows}, confidence, "available")


def _daily_revenue(sales_df: Any) -> List[Dict[str, Any]]:
    import pandas as pd

    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return []
    d = sales_df.copy()
    lower = {str(c).strip().lower(): c for c in d.columns}
    date_col = next((lower[a] for a in (
        "transaction_date", "tanggal transaksi", "tgl", "date",
        "order date", "tanggal") if a in lower), None)
    rev_col = next((lower[a] for a in ("revenue", "total", "omzet") if a in lower), None)
    if date_col is None or rev_col is None:
        return []
    d[date_col] = pd.to_datetime(d[date_col], errors="coerce")
    d[rev_col] = pd.to_numeric(d[rev_col], errors="coerce").fillna(0.0)
    d = d.dropna(subset=[date_col])
    if d.empty:
        return []
    g = d.groupby(d[date_col].dt.date)[rev_col].sum()
    return [{"date": str(k), "y": round(float(v), 2)} for k, v in g.items()]


def _anomaly_context(sales_df: Any, seq: int) -> Dict[str, Any]:
    history = _daily_revenue(sales_df)
    if len(history) < 6:
        return _item(seq, "anomaly", "ml.anomaly.detect_anomalies", {}, 0.0,
                     "unavailable",
                     f"only {len(history)} daily points; need >= 6 to score variance")
    try:
        from app.ml.anomaly import detect_anomalies

        res = detect_anomalies([{"date": h["date"], "value": h["y"]} for h in history])
    except Exception as exc:
        return _item(seq, "anomaly", "ml.anomaly.detect_anomalies", {}, 0.0,
                     "unavailable", f"anomaly detection failed: {type(exc).__name__}")
    metrics = res.get("metrics", {}) if isinstance(res, dict) else {}
    if metrics.get("reason"):
        return _item(seq, "anomaly", "ml.anomaly.detect_anomalies", {}, 0.0,
                     "unavailable", str(metrics.get("reason")))
    anomalies = res.get("anomalies", []) if isinstance(res, dict) else []
    n = int(metrics.get("n", len(history)) or len(history))
    confidence = min(0.9, 0.4 + n / 60.0)
    kept = [dict(a) for a in anomalies[:10]]
    return _item(seq, "anomaly", "ml.anomaly.detect_anomalies",
                 {"n": n, "n_anomalies": int(metrics.get("n_anomalies", len(kept))),
                  "anomalies": kept}, confidence, "available")


def _forecast_context(sales_df: Any, horizon: int, seq: int) -> Dict[str, Any]:
    history = _daily_revenue(sales_df)
    if not history:
        return _item(seq, "forecast", "ml.forecasting.forecast", {}, 0.0,
                     "unavailable", "no usable history points")
    try:
        from app.ml.forecasting import forecast as _fc

        res = _fc(history, horizon)
    except Exception as exc:
        return _item(seq, "forecast", "ml.forecasting.forecast", {}, 0.0,
                     "unavailable", f"forecast failed: {type(exc).__name__}")
    if not isinstance(res, dict) or res.get("method") == "insufficient_data":
        reason = ""
        try:
            reason = str((res or {}).get("metrics", {}).get("reason") or "insufficient data")
        except Exception:
            reason = "insufficient data"
        return _item(seq, "forecast", "ml.forecasting.forecast", {}, 0.0,
                     "unavailable", reason)
    metrics = res.get("metrics", {}) if isinstance(res, dict) else {}
    floored = bool(metrics.get("interval_floored", True))
    confidence = 0.45 if floored else 0.75
    points = res.get("forecast", []) if isinstance(res, dict) else []
    return _item(seq, "forecast", "ml.forecasting.forecast",
                 {"method": res.get("method", "baseline"),
                  "points": points, "metrics": metrics}, confidence, "available")


def _customer_features(sales_df: Any) -> List[Dict[str, Any]]:
    """Minimal per-customer features derived from the sales frame only."""
    import pandas as pd

    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return []
    d = sales_df.copy()
    lower = {str(c).strip().lower(): c for c in d.columns}
    cust_col = next((lower[a] for a in (
        "customer_name", "customer", "nama customer", "pelanggan",
        "nm customer", "customer_code") if a in lower), None)
    if cust_col is None:
        return []
    rev_col = next((lower[a] for a in ("revenue", "total", "omzet") if a in lower), None)
    date_col = next((lower[a] for a in (
        "transaction_date", "tanggal transaksi", "tgl", "date",
        "order date", "tanggal") if a in lower), None)
    if rev_col is None:
        return []
    d[rev_col] = pd.to_numeric(d[rev_col], errors="coerce").fillna(0.0)
    if date_col is not None:
        d[date_col] = pd.to_datetime(d[date_col], errors="coerce")
    today = pd.Timestamp.now().normalize()
    out: List[Dict[str, Any]] = []
    for name, g in d.groupby(d[cust_col].astype(str)):
        total = float(g[rev_col].sum())
        orders = int(len(g))
        aov = total / orders if orders else 0.0
        if date_col is not None and g[date_col].notna().any():
            last = g[date_col].max()
            first = g[date_col].min()
            try:
                recency = int((today - last.normalize()).days)
            except Exception:
                recency = 0
            try:
                tenure = int((last - first).days)
            except Exception:
                tenure = 0
        else:
            recency, tenure = 0, 0
        out.append({
            "customer_name": str(name),
            "total_orders": orders, "total_spending": round(total, 2),
            "recency": max(0, recency), "frequency": orders,
            "monetary": round(total, 2), "aov": round(aov, 2),
            "tenure": max(0, tenure),
        })
    return out


def _ml_prediction_context(sales_df: Any, seq: int) -> Dict[str, Any]:
    import pandas as pd

    if not isinstance(sales_df, pd.DataFrame) or sales_df.empty:
        return _item(seq, "ml_prediction", "ml.recommendation+segmentation", {}, 0.0,
                     "unavailable", "empty sales frame: no customer/product rows")
    metrics: Dict[str, Any] = {}
    reasons: List[str] = []
    try:
        from app.ml.recommendation import recommend as _rec

        recs = _rec(sales_df, None, None, 3)
        if recs:
            metrics["recommendations"] = recs
        else:
            reasons.append("recommendation: no product co-occurrence available")
    except Exception as exc:
        reasons.append(f"recommendation failed: {type(exc).__name__}")
    customers = _customer_features(sales_df)
    if len(customers) >= 2:
        try:
            from app.ml.segmentation import segment as _seg

            n_clusters = max(2, min(4, len(customers)))
            seg = _seg(customers, n_clusters=n_clusters)
            seg_metrics = seg.get("metrics", {}) if isinstance(seg, dict) else {}
            if seg_metrics.get("reason") and not seg.get("segments"):
                reasons.append(f"segmentation: {seg_metrics.get('reason')}")
            else:
                metrics["segments"] = seg.get("segments", [])[:20]
                metrics["segment_metrics"] = seg_metrics
                metrics["n_customers"] = len(customers)
        except Exception as exc:
            reasons.append(f"segmentation failed: {type(exc).__name__}")
    else:
        reasons.append(
            f"segmentation: only {len(customers)} customer(s); need >= 2 to cluster")
    if not metrics:
        return _item(seq, "ml_prediction", "ml.recommendation+segmentation", {}, 0.0,
                     "unavailable", "; ".join(reasons) or "no ML signal available")
    confidence = min(1.0, max(0.3, len(customers) / 20.0)) if customers else 0.4
    if reasons:
        metrics["partial_reasons"] = reasons
    return _item(seq, "ml_prediction", "ml.recommendation+segmentation",
                 metrics, confidence, "available")


def collect_evidence(
    subject: Optional[Dict[str, Any]] = None,
    *,
    sales_df: Any = None,
    db_session: Any = None,
) -> Dict[str, Any]:
    """Aggregate the four evidence contexts for ``subject``.

    ``sales_df`` wins when given (tests pass synthetic frames); otherwise the
    warehouse frame is loaded via ``db_session`` and filtered by
    ``subject.branch``. Deterministic: item ids follow the fixed kind order.
    """
    norm = normalize_subject(subject)
    frame = sales_df
    if frame is None:
        frame = _load_sales_frame(db_session, norm["branch"])
    evidence = [
        _kpi_context(frame, 1),
        _anomaly_context(frame, 2),
        _forecast_context(frame, norm["horizon"], 3),
        _ml_prediction_context(frame, 4),
    ]
    return {
        "subject": norm,
        "evidence": evidence,
        "collected_at": _utcnow_iso(),
    }


def available_evidence(collected: Dict[str, Any]) -> List[Dict[str, Any]]:
    """Return only the ``available`` items of a collected evidence envelope."""
    return [e for e in (collected or {}).get("evidence", [])
            if isinstance(e, dict) and e.get("status") == "available"]


def evidence_by_id(collected: Dict[str, Any]) -> Dict[str, Dict[str, Any]]:
    """Index collected evidence by item id."""
    out: Dict[str, Dict[str, Any]] = {}
    for e in (collected or {}).get("evidence", []) or []:
        if isinstance(e, dict) and e.get("id"):
            out[str(e["id"])] = e
    return out
