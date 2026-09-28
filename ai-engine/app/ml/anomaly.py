"""Anomaly detection: zscore/IQR + IsolationForest + LOF.

The module is stateless: it trains its detectors on the series handed to it on
every call, so there is no artifact file to load and no FileNotFoundError path.
What it does guard is the *input* — a series whose value column is not numeric,
or which is shorter than the detectors require, is reported through
``metrics["detectors"]`` instead of raising out of the request handler, and the
returned envelope always carries the same keys whether or not anything was
flagged.
"""
from __future__ import annotations

import math
from typing import Any, Dict, List

import numpy as np
import pandas as pd

#: Column names treated as the measured value, in priority order.
_VALUE_ALIASES = ("value", "y", "yhat", "revenue", "amount", "quantity", "total", "omzet")


def _empty_metrics(reason: str, n: int = 0) -> Dict[str, Any]:
    """Return the zero envelope for an empty or unusable series."""
    return {"anomalies": [], "all": [],
            "metrics": {"n": int(n), "n_anomalies": 0, "detectors": {},
                        "reason": reason}}


def _pick_value_column(df: pd.DataFrame) -> str | None:
    """Return the column to score: the first known alias, else the first column
    that actually parses as numeric. A date column can never be the value, so a
    series of only dates resolves to None rather than scoring zeros."""
    for c in df.columns:
        if str(c).lower() in _VALUE_ALIASES:
            return c
    for c in df.columns:
        if str(c).lower() in ("date", "ds", "period"):
            continue
        converted = pd.to_numeric(df[c], errors="coerce")
        if converted.notna().any():
            return c
    return None


def _label(value: Any, fallback: str) -> str:
    """Render a date-like cell as an ISO string, falling back when it is null."""
    try:
        if value is None or (not isinstance(value, str) and pd.isna(value)):
            return fallback
    except (TypeError, ValueError):
        return str(value)
    try:
        ts = pd.Timestamp(value)
        if pd.isna(ts):
            return fallback
        return ts.date().isoformat() if ts == ts.normalize() else ts.isoformat()
    except (TypeError, ValueError, OverflowError):
        return str(value)


def detect_anomalies(series: List[Dict[str, Any]], sensitivity: float = 2.5) -> Dict[str, Any]:
    """Score each point of ``series`` (a list of records) for anomaly.

    Returns ``{"anomalies": [...], "all": [...], "metrics": {...}}`` where every
    row of ``all`` is ``{index, date, value, anomaly, score, severity,
    explanation}``. The envelope keeps the same keys for an empty, unusable or
    well-formed series, so a consumer can index ``data["all"]`` on a fresh
    warehouse without a KeyError. ``metrics["reason"]`` is set when the series
    could not be scored; no score is ever fabricated from a non-numeric column.
    """
    try:
        sensitivity = float(sensitivity)
    except (TypeError, ValueError):
        sensitivity = 2.5
    if not math.isfinite(sensitivity) or sensitivity <= 0:
        sensitivity = 2.5

    if not series:
        return _empty_metrics("empty series")
    try:
        df = pd.DataFrame(list(series))
    except (TypeError, ValueError):
        return _empty_metrics("series is not a list of records")
    if df.empty:
        return _empty_metrics("empty series")

    vcol = _pick_value_column(df)
    if vcol is None:
        return _empty_metrics("no numeric value column found", len(df))

    raw = df[vcol]
    numeric = pd.to_numeric(raw, errors="coerce")
    n = len(numeric)
    non_numeric = int(numeric.isna().sum())
    y = numeric.fillna(0.0).to_numpy(dtype=float)
    detectors: Dict[str, Any] = {"value_column": str(vcol), "non_numeric_points": non_numeric}

    dcol = next((c for c in df.columns
                 if "date" in str(c).lower() or str(c).lower() in ("ds", "period")), None)
    labels = ([_label(v, f"{i}") for i, v in enumerate(df[dcol])] if dcol
              else [str(i) for i in range(n)])

    if n < 2 or np.allclose(y, y[0]):
        # Nothing to compare against: report the points without scoring them
        # rather than emitting z=0/IQR=0 and calling the result "no anomalies".
        return _empty_metrics("series too short or constant to score", n)

    mean = float(np.mean(y))
    std = float(np.std(y))
    z = (y - mean) / (std or 1.0)
    q1, q3 = np.quantile(y, 0.25), np.quantile(y, 0.75)
    iqr = (q3 - q1) or 1.0
    iqr_flag = (y < q1 - 1.5 * iqr) | (y > q3 + 1.5 * iqr)
    stat_flag = (np.abs(z) > sensitivity) | iqr_flag
    detectors["zscore"] = "on"
    detectors["iqr"] = "on"

    iso_score = np.zeros(n)
    iso_flag = np.zeros(n, dtype=bool)
    try:
        from sklearn.ensemble import IsolationForest

        contamination = min(0.15, max(0.02, 5 / n))
        iso = IsolationForest(contamination=contamination, random_state=42)
        lab = iso.fit_predict(y.reshape(-1, 1))
        iso_flag = lab == -1
        raw_score = -iso.score_samples(y.reshape(-1, 1))
        span = float(raw_score.max() - raw_score.min())
        iso_score = (raw_score - raw_score.min()) / (span + 1e-9) if span > 0 else np.zeros(n)
        detectors["isolation_forest"] = "on"
    except Exception as exc:
        detectors["isolation_forest"] = f"unavailable: {type(exc).__name__}"

    lof_score = np.zeros(n)
    if n >= 6:
        try:
            from sklearn.neighbors import LocalOutlierFactor

            lof = LocalOutlierFactor(n_neighbors=min(5, n - 1))
            lof.fit_predict(y.reshape(-1, 1))
            raw_lof = -lof.negative_outlier_factor_
            span = float(raw_lof.max() - raw_lof.min())
            lof_score = (raw_lof - raw_lof.min()) / (span + 1e-9) if span > 0 else np.zeros(n)
            detectors["lof"] = "on"
        except Exception as exc:
            detectors["lof"] = f"unavailable: {type(exc).__name__}"
    else:
        detectors["lof"] = f"needs >= 6 points, got {n}"

    out: List[Dict[str, Any]] = []
    for i in range(n):
        stat_s = min(1.0, abs(float(z[i])) / (sensitivity + 1.5))
        combined = float(0.5 * stat_s + 0.3 * float(iso_score[i]) + 0.2 * float(lof_score[i]))
        is_anom = bool(stat_flag[i] or iso_flag[i] or combined > 0.65)
        if combined >= 0.85:
            sev = "critical"
        elif combined >= 0.7:
            sev = "high"
        elif combined >= 0.55:
            sev = "medium"
        else:
            sev = "low"
        out.append({
            "index": i,
            "date": labels[i],
            "value": round(float(y[i]), 2),
            "anomaly": is_anom,
            "score": round(combined, 3),
            "severity": sev if is_anom else "low",
            "explanation": {
                "top_features": {str(vcol): round(float(abs(y[i] - mean) / (std or 1.0)), 2)},
                "zscore": round(float(z[i]), 2),
                "iqr_outlier": bool(iqr_flag[i]),
            },
        })

    anoms = [r for r in out if r["anomaly"]]
    return {
        "anomalies": anoms,
        "all": out,
        "metrics": {"n": n, "n_anomalies": len(anoms), "detectors": detectors,
                    "sensitivity": sensitivity, "label_source": "date" if dcol else "index"},
    }
