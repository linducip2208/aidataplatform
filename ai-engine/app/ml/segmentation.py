"""KMeans segmentation + RFM labels.

``segment()`` returns a JSON-safe envelope on every path: ``{"segments": [...],
"metrics": {...}, "centers": [[...]]}``. The fitted ``KMeans`` and
``StandardScaler`` do not cross that boundary: the default result serialises
with ``json.dumps`` as-is, so no caller has to pop a live estimator out before
handing the dict to FastAPI or to the AI evidence layer. A caller that genuinely
needs the fitted objects -- the trainer, which persists them as the version
artifact -- opts in with ``with_artifacts=True``.

Degrades rather than raising. An empty input, a malformed input, a frame with
no measurable column, or a frame whose columns carry no signal each return a
well-formed envelope whose ``metrics["reason"]`` explains why nothing was
clustered. Whenever a frame was built, ``segments`` still holds exactly one
record per input row, so a caller can count coverage without a special case.
"""
from __future__ import annotations

from collections.abc import Mapping
from typing import Any, Dict, List, Tuple

import numpy as np
import pandas as pd

#: Preferred feature columns, in the order customer_features() emits them.
FEATURE_CANDIDATES = ("total_orders", "total_spending", "recency", "frequency",
                      "monetary", "aov", "tenure")
#: Columns that identify a customer rather than measure one.
_ID_COLUMNS = ("customer", "customer_name", "customer_code", "customer_id", "segment")
#: Cluster names assigned by descending monetary centroid.
_NAMES = ["champions", "loyal", "potential", "at_risk", "new",
          "dormant", "vip", "regular", "low_value", "other"]
#: Segment label used when the data supports no grouping at all.
_UNSEGMENTED = "unsegmented"
_NAME_COLUMNS = ("customer_name", "customer", "customer_code")
_DEFAULT_CLUSTERS = 4
_MAX_CLUSTERS = 10


def _metrics(reason: str | None = None, n_clusters: int = 0,
             features: List[str] | None = None,
             dropped: Dict[str, str] | None = None,
             silhouette: float | None = None) -> Dict[str, Any]:
    """The ``metrics`` block. ``silhouette``, ``n_clusters`` and ``features`` are
    always present so a caller can index them without a KeyError; ``reason`` and
    ``dropped_features`` only appear when they carry something."""
    out: Dict[str, Any] = {"silhouette": silhouette, "n_clusters": n_clusters,
                           "features": list(features or [])}
    if dropped:
        out["dropped_features"] = dropped
    if reason:
        out["reason"] = reason
    return out


def _result(segments: List[Dict[str, Any]], metrics: Dict[str, Any],
            centers: List[List[float]] | None = None,
            model: Any = None, scaler: Any = None,
            with_artifacts: bool = False) -> Dict[str, Any]:
    """Build the response envelope. The three documented keys are always
    present; the fitted estimators are attached only when explicitly requested."""
    out: Dict[str, Any] = {"segments": segments, "metrics": metrics,
                           "centers": centers or []}
    if with_artifacts:
        out["model"] = model
        out["scaler"] = scaler
    return out


def _customer_of(row: Mapping[str, Any], index: int) -> str:
    for col in _NAME_COLUMNS:
        value = row.get(col)
        if value:
            return str(value)
    return f"row_{index}"


def _flat(df: pd.DataFrame, features: List[str], reason: str,
          dropped: Dict[str, str] | None = None,
          centers: List[List[float]] | None = None) -> Dict[str, Any]:
    """One segment record per input row, all in cluster 0.

    The rows are still enumerated so the caller sees the same coverage it would
    get from a real fit; what is missing is the grouping, which ``reason``
    explains.
    """
    segs = [{"customer": _customer_of(row, i), "cluster": 0, "segment": _UNSEGMENTED}
            for i, row in enumerate(df.to_dict("records"))]
    return _result(segs, _metrics(reason, 1, features, dropped), centers=centers)


def _usable_features(df: pd.DataFrame, feat_cols: List[str]) -> Tuple[List[str], Dict[str, str]]:
    """Split the candidate columns into ones that carry signal and ones that do not.

    A column that is entirely null, entirely non-numeric, or constant within the
    cohort is dropped before scaling. Kept, it survives ``fillna(0)`` as a
    column of zeros, ``StandardScaler`` divides by a zero standard deviation,
    and the estimator collapses the whole cohort into a single arbitrary
    cluster while still reporting the requested ``n_clusters``.
    """
    num = df[feat_cols].apply(pd.to_numeric, errors="coerce")
    kept: List[str] = []
    dropped: Dict[str, str] = {}
    for col in feat_cols:
        series = num[col]
        if series.notna().sum() == 0:
            dropped[col] = "no numeric values"
        elif series.nunique(dropna=True) <= 1:
            dropped[col] = "zero variance"
        else:
            kept.append(col)
    return kept, dropped


def segment(customers: List[Dict[str, Any]], n_clusters: int = 4, *,
            with_artifacts: bool = False) -> Dict[str, Any]:
    """Cluster customer feature records.

    Returns ``{"segments": [{customer, cluster, segment}, ...], "metrics": {...},
    "centers": [[...]]}``. With ``with_artifacts=True`` the fitted ``KMeans`` and
    ``StandardScaler`` are added under ``model``/``scaler`` for the trainer; the
    default result is plain JSON.

    ``metrics["n_clusters"]`` is the number of clusters actually produced, not
    the number requested, and ``metrics["features"]`` lists the columns that
    survived filtering. ``metrics["reason"]`` is set when nothing was clustered.
    """
    try:
        n_clusters = int(n_clusters)
    except (TypeError, ValueError):
        n_clusters = _DEFAULT_CLUSTERS
    n_clusters = max(1, min(n_clusters, _MAX_CLUSTERS))

    if not customers:
        return _result([], _metrics("no customer records", 0, []),
                       with_artifacts=with_artifacts)

    rows = list(customers)
    if not all(isinstance(r, Mapping) for r in rows):
        return _result([], _metrics("input is not a list of records", 0, []),
                       with_artifacts=with_artifacts)
    try:
        df = pd.DataFrame(rows)
    except (TypeError, ValueError):
        return _result([], _metrics("input is not a list of records", 0, []),
                       with_artifacts=with_artifacts)
    if df.empty:
        return _result([], _metrics("no customer records", 0, []),
                       with_artifacts=with_artifacts)

    feat_cols = [c for c in FEATURE_CANDIDATES if c in df.columns]
    if not feat_cols:
        feat_cols = [c for c in df.columns if c not in _ID_COLUMNS]
    if not feat_cols:
        return _flat(df, [], "no measurable feature column")

    n_rows = int(df.shape[0])
    if n_rows < 2:
        # Checked before the variance filter, which a single row can never
        # survive: one row is constant in every column, so filtering first would
        # report "no feature column carries any signal" for what is really too
        # little data to group.
        row = df[feat_cols].apply(pd.to_numeric, errors="coerce").fillna(0.0).iloc[0]
        return _flat(df, feat_cols, "fewer than 2 customers",
                     centers=[[float(v) for v in row.tolist()]])

    kept, dropped = _usable_features(df, feat_cols)
    if not kept:
        return _flat(df, feat_cols, "no feature column carries any signal", dropped)

    X = df[kept].apply(pd.to_numeric, errors="coerce").fillna(0.0).to_numpy(dtype=float)

    # KMeans also needs k <= n_distinct: asking for more clusters than there are
    # distinct profiles makes it return fewer than requested (with a
    # ConvergenceWarning) rather than failing, so the cap has to be applied here
    # for the reported cluster count to be true.
    n_distinct = int(np.unique(X, axis=0).shape[0])
    k = min(n_clusters, n_rows, n_distinct)
    if k < 2:
        return _flat(df, kept, "fewer than 2 distinct customer profiles", dropped)

    from sklearn.cluster import KMeans
    from sklearn.preprocessing import StandardScaler

    scaler = StandardScaler()
    Xs = scaler.fit_transform(X)
    km = KMeans(n_clusters=k, random_state=42, n_init=10)
    labels = km.fit_predict(Xs)

    actual = len({int(x) for x in labels})
    sil = None
    if actual > 1 and n_rows > k:
        try:
            from sklearn.metrics import silhouette_score

            sil = round(float(silhouette_score(Xs, labels)), 4)
        except Exception:
            sil = None

    # RFM-ish label per cluster by monetary centroid rank
    centers = scaler.inverse_transform(km.cluster_centers_)
    m_idx = kept.index("monetary") if "monetary" in kept else -1
    order = sorted(range(k), key=lambda i: (centers[i][m_idx] if m_idx >= 0
                                            else float(centers[i].sum())), reverse=True)
    label_map = {c: (_NAMES[i] if i < len(_NAMES) else f"cluster_{i}")
                 for i, c in enumerate(order)}

    segs = [{"customer": _customer_of(row, i), "cluster": int(labels[i]),
             "segment": label_map[int(labels[i])]}
            for i, row in enumerate(df.to_dict("records"))]

    return _result(segs, _metrics(None, actual, kept, dropped, sil),
                   centers=centers.tolist(), model=km, scaler=scaler,
                   with_artifacts=with_artifacts)
