"""Anomaly detection: zscore/IQR + IsolationForest + LOF.

The module is stateless: it trains its detectors on the series handed to it on
every call, so there is no artifact file to load and no FileNotFoundError path.
What it does guard is the *input* — a series whose value column is not numeric,
or which is shorter than the detectors require, is reported through
``metrics["reason"]`` instead of raising out of the request handler, and the
returned envelope always carries the same keys whether or not anything was
flagged.

``sensitivity`` is a real dial: a stricter setting flags strictly fewer or
equal points, never more. Both the z gate and the two distribution detectors
are scaled from it, and at the 2.5 default they keep their conventional values
(1.5x IQR, contamination 5/n capped at 0.15).
"""
from __future__ import annotations

import math
import re
from typing import Any, Dict, List

import numpy as np
import pandas as pd

#: Column names treated as the measured value, in priority order.
_VALUE_ALIASES = ("value", "y", "yhat", "revenue", "amount", "quantity", "total", "omzet")
#: Tokens that mark a column as the time axis of a series.
_DATE_TOKENS = ("date", "ds", "period", "time", "timestamp", "tanggal", "tgl")
#: Smallest magnitude that can plausibly be a unix timestamp (seconds or millis);
#: anything smaller in a date column is a day-of-month, not an epoch.
_MIN_EPOCH = 100_000_000
#: A series whose standard deviation is this small relative to its own scale
#: carries no variance to score against.
_MIN_REL_STD = 1e-9


def _empty_metrics(reason: str, n: int = 0, sensitivity: float = 2.5) -> Dict[str, Any]:
    """Return the zero envelope for an empty or unusable series.

    The ``metrics`` key set is the same one the scored path returns, so a
    consumer that reads ``metrics["sensitivity"]`` or ``metrics["label_source"]``
    on a fresh warehouse gets a value rather than a KeyError.
    """
    return {"anomalies": [], "all": [],
            "metrics": {"n": int(n), "n_anomalies": 0, "detectors": {},
                        "sensitivity": float(sensitivity), "label_source": "index",
                        "reason": reason}}


def _is_date_name(col: Any) -> bool:
    """True when a column *name* marks it as the time axis. Word-based, so
    ``updated_at`` and ``candidates`` are not mistaken for a date column."""
    lc = str(col).lower()
    parts = {p for p in re.split(r"[^a-z0-9]+", lc) if p}
    return bool(parts & set(_DATE_TOKENS))


def _is_date_cell(value: Any) -> bool:
    """True when a cell can be rendered as a date without guessing.

    Numbers are only dates once they are large enough to be a unix timestamp;
    rendering a bare 1..31 day-of-month as ``1970-01-01T00:00:00.000000001`` is
    worse than returning the raw value.
    """
    if isinstance(value, (bool, np.bool_)):
        return False
    if isinstance(value, (int, float, np.integer, np.floating)):
        try:
            return bool(np.isfinite(float(value))) and abs(float(value)) >= _MIN_EPOCH
        except (TypeError, ValueError, OverflowError):
            return False
    return True


def _pick_date_column(df: pd.DataFrame) -> Any | None:
    """Return the first date-named column that actually holds a date-like cell.

    A name match alone is not enough: ``updated_at`` matches on ``date`` and can
    hold free text, and picking it would relabel every point with its contents.
    """
    for c in df.columns:
        if _is_date_name(c) and any(_is_date_cell(v) for v in df[c].head(50)):
            return c
    return None


def _pick_value_column(df: pd.DataFrame) -> Any | None:
    """Return the column to score: the first known alias, else the first column
    that actually parses as numeric. A date column can never be the value, so a
    series of only dates resolves to None rather than scoring zeros."""
    for c in df.columns:
        if str(c).lower() in _VALUE_ALIASES:
            return c
    for c in df.columns:
        if _is_date_name(c):
            continue
        converted = pd.to_numeric(df[c], errors="coerce")
        if converted.notna().any():
            return c
    return None


def _epoch_unit(value: float) -> str:
    """Pick the timestamp unit for a numeric cell by its magnitude.

    ``pd.Timestamp(1756000001)`` reads as nanoseconds, so an epoch-seconds date
    column renders as 1970-01-01T00:00:01.756000001 unless the unit is stated.
    """
    magnitude = abs(float(value))
    if magnitude >= 1e17:
        return "ns"
    if magnitude >= 1e14:
        return "us"
    if magnitude >= 1e11:
        return "ms"
    return "s"


def _label(value: Any, fallback: str) -> str:
    """Render a date-like cell as an ISO string, falling back when it is null."""
    if isinstance(value, (bool, np.bool_)):
        return str(value)
    if isinstance(value, (int, float, np.integer, np.floating)):
        # a number is a date only once it is large enough to be a unix
        # timestamp, and then it is read in the unit its magnitude implies
        try:
            num = float(value)
        except (TypeError, ValueError, OverflowError):
            return fallback if value is None else str(value)
        if not math.isfinite(num) or abs(num) < _MIN_EPOCH:
            return str(value)
        try:
            return _stamp(pd.to_datetime(num, unit=_epoch_unit(num), errors="coerce"), fallback)
        except (TypeError, ValueError, OverflowError):
            return str(value)
    if not _is_date_cell(value):
        return fallback if value is None else str(value)
    try:
        if value is None or (not isinstance(value, str) and pd.isna(value)):
            return fallback
    except (TypeError, ValueError):
        return str(value)
    return _stamp(pd.Timestamp(value), fallback)


def _stamp(ts: Any, fallback: str) -> str:
    """Format a Timestamp, keeping midnight as a plain date."""
    try:
        if pd.isna(ts):
            return fallback
        return ts.date().isoformat() if ts == ts.normalize() else ts.isoformat()
    except (TypeError, ValueError, OverflowError, AttributeError):
        return fallback


def _point(index: int, date: str, value: float, anomaly: bool, score: float,
           severity: str, vcol: Any, zscore: float, iqr_outlier: bool,
           deviation: float) -> Dict[str, Any]:
    """Build one ``all`` row. The scored and unscored paths share this so the
    row shape is identical on both."""
    return {
        "index": int(index),
        "date": date,
        "value": round(float(value), 2),
        "anomaly": bool(anomaly),
        "score": round(float(score), 3),
        "severity": severity,
        "explanation": {
            "top_features": {str(vcol): round(float(deviation), 2)},
            "zscore": round(float(zscore), 2),
            "iqr_outlier": bool(iqr_outlier),
        },
    }


def _unscored(y: np.ndarray, labels: List[str], vcol: Any, reason: str, n: int,
              sensitivity: float, non_numeric: int, label_source: str) -> Dict[str, Any]:
    """Envelope for a series that cannot be compared against itself.

    The points are still returned, unflagged with a zero score, so
    ``metrics["n"] == len(all)`` holds on every path and a consumer reads the
    same row shape it gets from a scored series.
    """
    return {
        "anomalies": [],
        "all": [_point(i, labels[i], y[i], False, 0.0, "low", vcol, 0.0, False, 0.0)
                for i in range(n)],
        "metrics": {"n": int(n), "n_anomalies": 0,
                    "detectors": {"value_column": str(vcol),
                                  "non_numeric_points": non_numeric},
                    "sensitivity": float(sensitivity), "label_source": label_source,
                    "reason": reason},
    }


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
        return _empty_metrics("empty series", 0, sensitivity)
    try:
        df = pd.DataFrame(list(series))
    except (TypeError, ValueError):
        return _empty_metrics("series is not a list of records", 0, sensitivity)
    if df.empty:
        return _empty_metrics("empty series", 0, sensitivity)

    vcol = _pick_value_column(df)
    if vcol is None:
        return _empty_metrics("no numeric value column found", len(df), sensitivity)

    raw = df[vcol]
    numeric = pd.to_numeric(raw, errors="coerce")
    n = len(numeric)
    non_numeric = int(numeric.isna().sum())
    if n == 0 or non_numeric == n:
        # the column was chosen by name but holds no measurable value at all
        return _empty_metrics(
            f"value column {str(vcol)!r} has no numeric values", n, sensitivity)
    y = numeric.fillna(0.0).to_numpy(dtype=float)
    detectors: Dict[str, Any] = {"value_column": str(vcol), "non_numeric_points": non_numeric}

    dcol = _pick_date_column(df)
    labels = ([_label(v, f"{i}") for i, v in enumerate(df[dcol])] if dcol is not None
              else [str(i) for i in range(n)])
    label_source = "date" if dcol is not None else "index"

    scale = float(np.max(np.abs(y))) or 1.0
    std = float(np.std(y))
    if n < 2 or std <= _MIN_REL_STD * scale:
        # Nothing to compare against: report the points unscored rather than
        # emitting z=0/IQR=0 and calling the result "no anomalies". The test is
        # relative, so a near-flat series of large revenues is still scored
        # rather than dropped for being "equal" to its own first point.
        return _unscored(y, labels, vcol, "series too short or has no variance to score",
                         n, sensitivity, non_numeric, label_source)

    mean = float(np.mean(y))
    sd = std or 1.0
    z = (y - mean) / sd
    q1, q3 = np.quantile(y, 0.25), np.quantile(y, 0.75)
    iqr = (q3 - q1) or 1.0
    # 1.5x IQR at the 2.5 default; the fence follows the dial in both directions
    iqr_k = min(max(1.5 * sensitivity / 2.5, 0.5), 4.0)
    iqr_flag = (y < q1 - iqr_k * iqr) | (y > q3 + iqr_k * iqr)
    stat_flag = np.abs(z) > sensitivity
    detectors["zscore"] = "on"
    detectors["iqr"] = "on"

    iso_score = np.zeros(n)
    iso_flag = np.zeros(n, dtype=bool)
    try:
        from sklearn.ensemble import IsolationForest

        # looser sensitivity lets the forest see more outliers, stricter fewer
        contamination = min(0.15, max(0.02, 5.0 / n)) * (2.5 / sensitivity)
        contamination = min(0.5, max(0.005, contamination))
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
        out.append(_point(i, labels[i], y[i], is_anom, combined, sev if is_anom else "low",
                          vcol, z[i], iqr_flag[i], abs(float(y[i]) - mean) / sd))

    anoms = [r for r in out if r["anomaly"]]
    return {
        "anomalies": anoms,
        "all": out,
        "metrics": {"n": n, "n_anomalies": len(anoms), "detectors": detectors,
                    "sensitivity": sensitivity, "label_source": label_source, "reason": None},
    }
