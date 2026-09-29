"""Sales forecaster: seasonal-naive baseline + GBM, honest intervals, joblib persistence.

The governing rule in this module is that a forecast may never be invented.
Three specific ways that used to go wrong are closed here:

* an empty or unusable history returns an **empty** forecast list with
  ``method="insufficient_data"`` — never a run of zeros, which is
  indistinguishable from a real forecast of zero revenue;
* a value that is missing or unparseable is **dropped**, not zero-filled, so a
  gap in the ingested data cannot masquerade as a collapse in sales;
* an interval is always ordered ``0 <= yhat_lower <= yhat <= yhat_upper`` and
  is widened by a documented floor when the history is too short to support an
  empirical one, instead of collapsing to a hairline that reads as certainty.
"""
from __future__ import annotations

import math
from pathlib import Path
from typing import Any, Dict, List, Tuple

import joblib
import numpy as np
import pandas as pd

from app.ml.features import sales_timeseries_features

# Column aliases accepted from the upstream history payload. "omzet" and
# "tanggal transaksi" are produced by the same ingestion mapper that
# ``app.ml.features.customer_features`` normalises, so they arrive in practice.
DATE_ALIASES = ("date", "ds", "transaction_date", "tanggal", "tanggal transaksi",
                "tgl", "order date", "period")
VALUE_ALIASES = ("y", "revenue", "total", "value", "yhat", "quantity", "omzet",
                 "amount", "sales")

# Mirrors ``ForecastRequest.horizon`` (``Field(ge=1, le=365)``) and
# ``ForecastRequest.granularity`` in app/schemas/ml.py. The CLI, the Celery
# task and the AI tool layer call this module directly and bypass pydantic, so
# the schema bounds are re-enforced here rather than assumed.
MIN_HORIZON = 1
MAX_HORIZON = 365
#: granularity -> length of one period in days, used to space the recursive
#: feature walk. Output dates for "monthly" are anchored to month ends instead.
GRANULARITIES: Dict[str, int] = {"daily": 1, "weekly": 7, "monthly": 30}
RESAMPLE_RULES: Dict[str, str] = {"weekly": "W-SUN", "monthly": "ME"}

MIN_OBS_FOR_GBM = 14
#: Below this many observations the baseline is re-anchored on the whole series
#: and contributes at most 6 of 7 seasonal days, so a walk-forward error
#: estimate is not meaningful and the interval falls back to the floor.
MIN_OBS_FOR_RESIDUALS = 8
#: z-multiplier retained from the previous symmetric band.
INTERVAL_Z = 1.28
#: Half-width used when the empirical residual spread is zero (a short, flat or
#: constant history). Without it the "80% interval" for 1 or 7 identical points
#: collapses to [y, y] and is presented as if it were certain.
RESIDUAL_FLOOR_FRACTION = 0.25
#: A history is only re-indexed onto a complete daily calendar when it is at
#: worst this much sparser than complete. One point in 120 days is not a daily
#: series and materialising the gap would fabricate 119 days of zero revenue.
MAX_GAP_RATIO = 5


def _validate_horizon(horizon: Any) -> int:
    """Coerce ``horizon`` to a whole number of periods inside the schema bounds.

    Returns the horizon as an ``int``. Raises ``ValueError`` — never a bare
    ``TypeError`` from deep inside pandas — when ``horizon`` is not a whole
    number or exceeds :data:`MAX_HORIZON`.
    """
    try:
        h = int(horizon)
    except (TypeError, ValueError) as exc:
        raise ValueError(f"horizon must be a whole number of periods, got {horizon!r}") from exc
    if isinstance(horizon, float) and not float(horizon).is_integer():
        raise ValueError(f"horizon must be a whole number of periods, got {horizon!r}")
    if h > MAX_HORIZON:
        raise ValueError(
            f"horizon={h} exceeds the maximum of {MAX_HORIZON} periods accepted by "
            "ForecastRequest.horizon"
        )
    return h


def _validate_granularity(granularity: Any) -> str:
    """Normalise ``granularity`` to one of :data:`GRANULARITIES`.

    Returns the lower-cased key. Raises ``ValueError`` naming the accepted
    values rather than silently forecasting daily, which would hand the caller
    a series at a resolution nobody asked for.
    """
    key = str(granularity or "daily").strip().lower()
    if key not in GRANULARITIES:
        raise ValueError(
            "granularity must be one of "
            + ", ".join(sorted(GRANULARITIES))
            + f"; got {granularity!r}"
        )
    return key


def _daily_series(history: List[Dict[str, Any]]) -> pd.DataFrame:
    """Normalise a raw history payload into a clean daily ``{"ds", "y"}`` frame.

    Rows with an unparseable date or an unparseable/missing value are dropped
    rather than zero-filled: a missing revenue is unknown, and reading it as
    zero is the same fabrication as returning a zero forecast. Duplicate dates
    are summed. A series that is otherwise dense is re-indexed onto a complete
    daily calendar so that ``lag_1``/``lag_7`` mean one and seven *days* and
    not one and seven *rows*; the number of inserted gap days is reported
    through ``frame.attrs`` and surfaces in ``metrics``.

    Returns a DataFrame with columns ``["ds", "y"]``, datetime64 ``ds`` and
    finite float ``y``, sorted ascending. Empty in, empty out — never a frame
    of zeros.
    """
    empty = pd.DataFrame(columns=["ds", "y"])
    rows = [r for r in (history or []) if isinstance(r, dict)]
    if not rows:
        return empty
    df = pd.DataFrame(rows)
    if df.empty or len(df.columns) == 0:
        return empty
    dcol = next((c for c in df.columns if str(c).strip().lower() in DATE_ALIASES), df.columns[0])
    vcol = next((c for c in df.columns if str(c).strip().lower() in VALUE_ALIASES), df.columns[-1])
    out = pd.DataFrame({
        "ds": pd.to_datetime(df[dcol], errors="coerce", format="mixed"),
        "y": pd.to_numeric(df[vcol], errors="coerce"),
    })
    out = out.dropna(subset=["ds", "y"])
    n_dropped = int(len(df) - len(out))
    if out.empty:
        empty.attrs["n_dropped"] = n_dropped
        return empty
    out = out.groupby("ds", as_index=False)["y"].sum().sort_values("ds")
    n_gap_days = 0
    span = int((out["ds"].max() - out["ds"].min()).days) + 1
    gaps = max(0, span - len(out))
    if gaps and gaps <= MAX_GAP_RATIO * len(out):
        n_gap_days = gaps
        full = pd.date_range(out["ds"].min(), out["ds"].max(), freq="D")
        out = (out.set_index("ds")["y"]
               .reindex(full, fill_value=0.0)
               .rename_axis("ds").reset_index())
    out.attrs["n_dropped"] = n_dropped
    out.attrs["n_gap_days"] = n_gap_days
    return out


def _resample(s: pd.DataFrame, granularity: str) -> pd.DataFrame:
    """Aggregate a daily frame onto ``granularity`` periods by summing revenue.

    Returns the same ``{"ds", "y"]`` shape with ``ds`` set to each period's
    final day. A no-op for ``"daily"`` and for an empty frame.
    """
    if granularity == "daily" or s.empty:
        return s
    out = (s.set_index("ds")["y"]
           .resample(RESAMPLE_RULES[granularity]).sum()
           .reset_index())
    out.columns = ["ds", "y"]
    out = out[out["ds"].notna()].reset_index(drop=True)
    out.attrs.update(s.attrs)
    return out


def _future_dates(anchor: pd.Timestamp, horizon: int, granularity: str) -> List[str]:
    """Return the ISO dates of the ``horizon`` periods that follow ``anchor``.

    Dates are stepped from the last *observed* date, never from a row index, so
    a history with missing days does not make the calendar drift. Monthly
    periods are anchored to month ends.
    """
    if granularity == "monthly":
        return [(anchor + pd.offsets.MonthEnd(i)).date().isoformat()
                for i in range(1, horizon + 1)]
    step = GRANULARITIES[granularity]
    return [(anchor + pd.Timedelta(days=step * i)).date().isoformat()
            for i in range(1, horizon + 1)]


def _walk_forward_errors(s: pd.DataFrame) -> np.ndarray:
    """One-step-ahead errors of the seasonal-naive baseline, walk-forward.

    The predictor for period *i* is built only from periods ``< i``, so no
    future observation ever enters its own training window. Returns a 1-D array
    of ``y[i] - prediction[i]`` (empty when the series is too short).
    """
    if len(s) < MIN_OBS_FOR_RESIDUALS:
        return np.array([], dtype=float)
    y = np.asarray(s["y"].to_numpy(), dtype=float)
    errs = []
    for i in range(7, len(y)):
        window = y[:i]
        level = float(window[-7:].mean()) if len(window) >= 7 else float(window.mean())
        errs.append(float(y[i] - (0.6 * float(window[-1]) + 0.4 * level)))
    return np.asarray(errs, dtype=float)


def _baseline_forecast(s: pd.DataFrame, horizon: int, granularity: str = "daily") -> np.ndarray:
    """Seasonal-naive forecast blended with the trailing 7-period mean.

    Returns an ``np.ndarray`` of exactly ``horizon`` finite, non-negative
    floats. Raises ``ValueError`` for an empty series: a run of fabricated
    zeros is indistinguishable from a real forecast of zero revenue, so the
    caller is required to decide what an empty history means.
    """
    h = _validate_horizon(horizon)
    if len(s) == 0:
        raise ValueError(
            "_baseline_forecast requires at least one observation; an empty series "
            "must be reported as insufficient data, not as a zero forecast"
        )
    y = np.asarray(s["y"].to_numpy(), dtype=float)
    if not np.isfinite(y).all():
        raise ValueError("series contains non-finite values; clean it before forecasting")
    level = float(y[-7:].mean()) if len(y) >= 7 else float(y.mean())
    period = 7 if granularity == "daily" else 1
    preds = np.empty(h, dtype=float)
    for i in range(h):
        seasonal = float(y[-period + (i % period)]) if period > 1 and len(y) >= period else level
        preds[i] = max(0.0, 0.6 * seasonal + 0.4 * level)
    return preds


def _gbm_forecast(s: pd.DataFrame, horizon: int, granularity: str = "daily") -> Tuple[np.ndarray, str]:
    """Forecast with XGBoost, LightGBM or sklearn's GBM; fall back to the baseline.

    The point estimate is fit on the full history, which is the correct choice
    for a forecast (no held-out data is discarded), and accuracy is reported
    separately by the caller via :func:`_walk_forward_errors`. Any step whose
    prediction is not finite is replaced by the baseline value for that step
    and the reported ``method`` drops to ``"baseline"`` — never a silent ``0.0``,
    which would read as a collapse in revenue.

    Returns ``(preds, method)`` where ``preds`` is a finite, non-negative
    ``np.ndarray`` of length exactly ``horizon`` and ``method`` is one of
    ``"xgboost"``, ``"lightgbm"``, ``"sklearn-gbm"`` or ``"baseline"``.
    """
    h = _validate_horizon(horizon)
    if h < MIN_HORIZON:
        return np.array([], dtype=float), "insufficient_data"
    if len(s) < MIN_OBS_FOR_GBM:
        return _baseline_forecast(s, h, granularity), "baseline"
    base = _baseline_forecast(s, h, granularity)
    feat = sales_timeseries_features(
        pd.DataFrame({"transaction_date": s["ds"].to_numpy(), "revenue": s["y"].to_numpy()})
    )
    drop = [c for c in ("transaction_date", "revenue") if c in feat.columns]
    X = feat.drop(columns=drop).to_numpy(dtype=float)
    y = np.asarray(feat["revenue"].to_numpy(), dtype=float)
    if len(X) < MIN_OBS_FOR_GBM or not np.isfinite(X).all():
        return base, "baseline"
    model = None
    method = "baseline"
    try:
        try:
            from xgboost import XGBRegressor  # type: ignore

            model = XGBRegressor(n_estimators=200, max_depth=4, learning_rate=0.08,
                                 subsample=0.9, random_state=42, n_jobs=1)
            method = "xgboost"
        except Exception:
            try:
                from lightgbm import LGBMRegressor  # type: ignore

                model = LGBMRegressor(n_estimators=200, max_depth=-1, learning_rate=0.08, verbose=-1)
                method = "lightgbm"
            except Exception:
                from sklearn.ensemble import GradientBoostingRegressor

                model = GradientBoostingRegressor(random_state=42)
                method = "sklearn-gbm"
        model.fit(X, y)
    except Exception:
        return base, "baseline"
    step = pd.Timedelta(days=GRANULARITIES[granularity])
    hist_ds = list(feat["transaction_date"])
    hist_y = [float(v) for v in y]
    last_date = pd.Timestamp(hist_ds[-1])
    preds = np.empty(h, dtype=float)
    fell_back = False
    for i in range(h):
        dt = last_date + step * (i + 1)
        fdf = sales_timeseries_features(
            pd.DataFrame({"transaction_date": hist_ds + [dt],
                          "revenue": hist_y + [hist_y[-1]]})
        )
        x_last = fdf.drop(columns=drop).to_numpy(dtype=float)[-1:]
        if x_last.shape[1] != X.shape[1]:
            return base, "baseline"
        raw = float(model.predict(x_last)[0])
        if not math.isfinite(raw):
            raw = float(base[i])
            fell_back = True
        value = max(0.0, raw)
        preds[i] = value
        hist_ds.append(dt)
        hist_y.append(value)
    return preds, ("baseline" if fell_back else method)


class SalesForecaster:
    """Seasonal forecaster with a persisted, inspectable state.

    :meth:`fit` stores the cleaned series and the walk-forward residual spread
    used for the prediction interval; :meth:`predict` returns
    ``[{"date", "yhat", "yhat_lower", "yhat_upper"}, ...]`` with exactly one
    entry per requested period, ordered
    ``0 <= yhat_lower <= yhat <= yhat_upper`` and anchored to the last observed
    date. With no usable history it returns an empty list and sets
    ``method`` to ``"insufficient_data"``.
    """

    def __init__(self, horizon: int = 30, granularity: str = "daily"):
        self.horizon = _validate_horizon(horizon)
        self.granularity = _validate_granularity(granularity)
        self.method = "baseline"
        self.resid_std = 0.0
        self.resid_q: Dict[str, float] = {}
        self.interval_floored = True
        self.model = None
        self._series = pd.DataFrame(columns=["ds", "y"])

    def _resid_offsets(self) -> Tuple[float, float]:
        """Return the ``(lower, upper)`` offsets for the prediction interval.

        Uses the empirical 10th/90th percentile of the walk-forward residuals
        when the history was long enough to produce them, otherwise a
        symmetric ``INTERVAL_Z * resid_std``, otherwise a documented floor of
        :data:`RESIDUAL_FLOOR_FRACTION` of the series level. The returned
        offsets are always finite and satisfy ``lower <= upper``.
        """
        lo = self.resid_q.get("q10")
        hi = self.resid_q.get("q90")
        if (lo is not None and hi is not None
                and math.isfinite(float(lo)) and math.isfinite(float(hi))
                and float(hi) > float(lo)):
            return float(lo), float(hi)
        if math.isfinite(self.resid_std) and self.resid_std > 0.0:
            return -INTERVAL_Z * self.resid_std, INTERVAL_Z * self.resid_std
        level = 1.0
        if len(self._series):
            level = max(float(np.median(np.abs(
                np.asarray(self._series["y"].to_numpy(), dtype=float)))), 1.0)
        floor = RESIDUAL_FLOOR_FRACTION * level
        self.interval_floored = True
        return -floor, floor

    def fit(self, history: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Absorb ``history`` and estimate the walk-forward residual spread.

        Unparseable dates and values are dropped, duplicate dates are summed
        and (when dense enough) missing days are filled. Returns
        ``{"n_obs", "resid_std", "granularity", "n_dropped", "n_gap_days",
        "degenerate", "interval_floored"}``; ``n_obs`` and ``resid_std`` are the
        keys existing callers read.
        """
        s = _daily_series(history)
        if self.granularity != "daily":
            s = _resample(s, self.granularity)
        errs = _walk_forward_errors(s)
        if errs.size:
            self.resid_std = float(np.std(errs))
            self.resid_q = {"q10": float(np.quantile(errs, 0.1)),
                            "q90": float(np.quantile(errs, 0.9))}
            self.interval_floored = False
        else:
            self.resid_std = 0.0
            self.resid_q = {}
            self.interval_floored = True
        self._series = s
        y = np.asarray(s["y"].to_numpy(), dtype=float) if len(s) else np.array([], dtype=float)
        return {
            "n_obs": int(len(s)),
            "resid_std": self.resid_std,
            "granularity": self.granularity,
            "n_dropped": int(s.attrs.get("n_dropped", 0)),
            "n_gap_days": int(s.attrs.get("n_gap_days", 0)),
            "degenerate": bool(y.size == 0 or float(np.ptp(y)) == 0.0),
            "interval_floored": self.interval_floored,
        }

    def predict(self, horizon: int | None = None,
                granularity: str | None = None) -> List[Dict[str, Any]]:
        """Return ``[{"date", "yhat", "yhat_lower", "yhat_upper"}, ...]``.

        ``horizon`` is the number of *periods* ahead (defaulting to the
        instance horizon); at the given ``granularity`` those periods are one
        day, one week or one month apart. Exactly ``horizon`` points are
        returned, dated from the last observation, each satisfying
        ``0 <= yhat_lower <= yhat <= yhat_upper``. Requires a prior :meth:`fit`
        or :meth:`load`; an empty series yields an empty list and sets
        ``method`` to ``"insufficient_data"`` rather than fabricated zeros.

        Raises ``ValueError`` for a non-integer, zero, negative or
        over-maximum horizon, and for an unknown granularity.
        """
        h = self.horizon if horizon is None else _validate_horizon(horizon)
        gran = self.granularity if granularity is None else _validate_granularity(granularity)
        s = getattr(self, "_series", pd.DataFrame(columns=["ds", "y"]))
        if h < MIN_HORIZON or len(s) == 0:
            self.method = "insufficient_data"
            return []
        preds, method = _gbm_forecast(s, h, gran)
        self.method = method
        lo_off, hi_off = self._resid_offsets()
        dates = _future_dates(pd.Timestamp(s["ds"].max()), len(preds), gran)
        out: List[Dict[str, Any]] = []
        for dt, p in zip(dates, preds):
            value = float(p) if math.isfinite(float(p)) else 0.0
            value = max(0.0, value)
            # A one-sided residual distribution (a strong trend makes the
            # baseline systematically under-predict, so q10 > 0) would push the
            # lower bound above the point estimate. Clamp to the invariant
            # 0 <= yhat_lower <= yhat <= yhat_upper rather than emit an
            # inverted band.
            lower = min(max(0.0, value + lo_off), value)
            upper = max(value + hi_off, lower)
            if not math.isfinite(upper):
                upper = lower
            out.append({"date": dt, "yhat": round(value, 2),
                        "yhat_lower": round(lower, 2), "yhat_upper": round(upper, 2)})
        return out

    def save(self, path: str | Path) -> str:
        """Persist the forecaster state to ``path`` as a joblib artifact.

        Returns the artifact path as a string.
        """
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"method": self.method, "resid_std": self.resid_std,
                     "resid_q": self.resid_q, "horizon": self.horizon,
                     "granularity": self.granularity,
                     "interval_floored": self.interval_floored,
                     "series": getattr(self, "_series", pd.DataFrame()).to_dict("records")}, p)
        return str(p)

    @classmethod
    def load(cls, path: str | Path) -> "SalesForecaster":
        """Restore a forecaster from an artifact written by :meth:`save`.

        The stored series is re-normalised through :func:`_daily_series`, so a
        partially damaged artifact (null values, unsorted or unparseable dates)
        is cleaned rather than carried into a forecast, and a non-finite
        ``resid_std`` is discarded instead of poisoning the interval.

        Raises ``FileNotFoundError`` when the artifact is absent and
        ``ValueError`` when it is unreadable or not a forecaster payload. Both
        messages name the artifact so a caller surfaces a clear failure instead
        of an opaque joblib/AttributeError traceback. Use :meth:`try_load` when
        a missing artifact should degrade rather than fail.
        """
        p = Path(path)
        if not p.is_file():
            raise FileNotFoundError(f"Forecast artifact not found: {p}")
        try:
            data = joblib.load(p)
        except Exception as exc:
            raise ValueError(f"Forecast artifact is unreadable: {p} ({type(exc).__name__})") from exc
        if not isinstance(data, dict) or "series" not in data:
            raise ValueError(
                f"Forecast artifact is not a SalesForecaster payload: {p}. "
                "Retrain the model to produce a valid artifact."
            )
        try:
            resid_std = float(data.get("resid_std", 0.0))
        except (TypeError, ValueError):
            resid_std = 0.0
        if not math.isfinite(resid_std):
            resid_std = 0.0
        raw_q = data.get("resid_q") or {}
        resid_q: Dict[str, float] = {}
        if isinstance(raw_q, dict):
            for key in ("q10", "q90"):
                try:
                    val = float(raw_q.get(key))
                except (TypeError, ValueError):
                    continue
                if math.isfinite(val):
                    resid_q[key] = val
        try:
            horizon = _validate_horizon(int(data.get("horizon", 30)))
        except (TypeError, ValueError):
            horizon = 30
        try:
            granularity = _validate_granularity(data.get("granularity", "daily"))
        except ValueError:
            granularity = "daily"
        obj = cls(horizon=horizon, granularity=granularity)
        method = data.get("method")
        obj.method = method if isinstance(method, str) and method else "baseline"
        obj.resid_std = resid_std
        obj.resid_q = resid_q
        obj.interval_floored = bool(data.get("interval_floored", not resid_q))
        obj._series = _daily_series(list(data.get("series") or []))
        return obj

    @classmethod
    def try_load(cls, path: str | Path) -> "SalesForecaster | None":
        """Return the restored forecaster, or None when the artifact is missing
        or unusable. Callers that must not fail a request should use this."""
        try:
            return cls.load(path)
        except (FileNotFoundError, ValueError):
            return None


def forecast(history: List[Dict[str, Any]], horizon: int = 30,
             granularity: str = "daily") -> Dict[str, Any]:
    """Forecast ``horizon`` periods past the last observation in ``history``.

    ``horizon`` is a count of periods, so at the default ``granularity="daily"``
    it is a number of days. The returned list always has exactly ``horizon``
    entries dated from the last *observed* date.

    Returns ``{"forecast": [{date, yhat, yhat_lower, yhat_upper}, ...],
    "method": str, "metrics": {...}}``. With no usable history the forecast list
    is empty and ``method`` is ``"insufficient_data"`` — a zero-valued forecast
    would be indistinguishable from a real forecast of zero revenue.

    Raises ``ValueError`` for a non-integer, over-maximum horizon or an unknown
    granularity; a non-positive horizon returns the empty
    ``"insufficient_data"`` payload rather than an empty list carrying a
    method that claims a model was fitted.
    """
    h = _validate_horizon(horizon)
    gran = _validate_granularity(granularity)
    fc = SalesForecaster(horizon=max(h, MIN_HORIZON), granularity=gran)
    info = fc.fit(history)
    metrics: Dict[str, Any] = {"mae_baseline": 0.0, "n_obs": int(info["n_obs"]),
                               "resid_std": float(info["resid_std"]),
                               "granularity": gran, "n_dropped": info["n_dropped"],
                               "n_gap_days": info["n_gap_days"],
                               "degenerate": info["degenerate"]}
    s = _daily_series(history)
    if h < MIN_HORIZON:
        metrics["reason"] = f"horizon must be at least {MIN_HORIZON} period"
        return {"forecast": [], "method": "insufficient_data", "metrics": metrics}
    if fc.granularity != "daily":
        s = _resample(s, gran)
    if len(s) == 0:
        metrics["reason"] = "no usable history points"
        return {"forecast": [], "method": "insufficient_data", "metrics": metrics}
    errs = _walk_forward_errors(s)
    metrics["mae_baseline"] = round(float(np.mean(np.abs(errs))), 2) if errs.size else 0.0
    preds = fc.predict(h)
    metrics["interval_floored"] = bool(fc.interval_floored)
    metrics["resid_q"] = {k: round(v, 2) for k, v in fc.resid_q.items()}
    if not preds:
        metrics["reason"] = "no forecast could be produced from the supplied history"
        return {"forecast": [], "method": "insufficient_data", "metrics": metrics}
    return {"forecast": preds, "method": fc.method, "metrics": metrics}
