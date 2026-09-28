"""KMeans segmentation + RFM labels.

``segment()`` returns a fixed set of keys on every path. The live ``KMeans`` and
``StandardScaler`` objects live under ``model``/``scaler`` because the API layer
and the trainer both pop them before serialising; the other keys are plain JSON.
"""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd

#: Preferred feature columns, in the order customer_features() emits them.
FEATURE_CANDIDATES = ("total_orders", "total_spending", "recency", "frequency",
                      "monetary", "aov", "tenure")
#: Columns that identify a customer rather than measure one.
_ID_COLUMNS = ("customer", "customer_name", "customer_code", "customer_id", "segment")
#: Cluster names assigned by descending monetary centroid.
_NAMES = ["champions", "loyal", "potential", "at_risk", "new",
          "dormant", "vip", "regular", "low_value", "other"]


def _result(segments: List[Dict[str, Any]], metrics: Dict[str, Any],
            centers: List[List[float]] | None = None,
            model: Any = None, scaler: Any = None) -> Dict[str, Any]:
    """Build the response envelope. Every key is always present so a caller can
    index it without a KeyError, and ``model``/``scaler`` are always poppable."""
    return {"segments": segments, "metrics": metrics,
            "centers": centers or [], "model": model, "scaler": scaler}


def segment(customers: List[Dict[str, Any]], n_clusters: int = 4) -> Dict[str, Any]:
    """Cluster customer feature records.

    Returns ``{"segments": [{customer, cluster, segment}, ...], "metrics": {...},
    "centers": [[...]], "model": KMeans|None, "scaler": StandardScaler|None}``.

    Degrades rather than raising: an empty input, an input with no measurable
    column, or a single customer each return a well-formed envelope whose
    ``metrics["reason"]`` explains why nothing was clustered. ``model`` and
    ``scaler`` are live objects and must be popped before JSON serialisation.
    """
    try:
        n_clusters = int(n_clusters)
    except (TypeError, ValueError):
        n_clusters = 4
    n_clusters = max(1, min(n_clusters, 10))

    if not customers:
        return _result([], {"reason": "no customer records", "n_clusters": 0, "features": []})
    try:
        df = pd.DataFrame(list(customers))
    except (TypeError, ValueError):
        return _result([], {"reason": "input is not a list of records", "n_clusters": 0,
                            "features": []})
    if df.empty:
        return _result([], {"reason": "no customer records", "n_clusters": 0, "features": []})

    feat_cols = [c for c in FEATURE_CANDIDATES if c in df.columns]
    if not feat_cols:
        feat_cols = [c for c in df.columns if c not in _ID_COLUMNS]
    if not feat_cols:
        return _result([], {"reason": "no measurable feature column", "n_clusters": 0,
                            "features": []})

    X = df[feat_cols].apply(pd.to_numeric, errors="coerce").fillna(0).to_numpy(dtype=float)
    n_rows = int(X.shape[0])
    if n_rows < 2:
        # KMeans requires n_samples >= n_clusters; a single customer has no
        # grouping to discover, so report it as its own segment instead.
        name_col = next((c for c in ("customer_name", "customer", "customer_code")
                         if c in df.columns), None)
        segs = [{"customer": str(df.iloc[0].get(name_col) or "row_0") if name_col else "row_0",
                 "cluster": 0, "segment": _NAMES[0]}]
        return _result(segs, {"silhouette": None, "n_clusters": 1, "features": feat_cols,
                              "reason": "fewer than 2 customers"}, centers=[[0.0] * len(feat_cols)])

    k = max(2, min(n_clusters, n_rows))
    if k < 2:
        return _result([], {"reason": "not enough customers to cluster", "n_clusters": 0,
                            "features": feat_cols})

    from sklearn.cluster import KMeans
    from sklearn.preprocessing import StandardScaler

    scaler = StandardScaler()
    Xs = scaler.fit_transform(X)
    km = KMeans(n_clusters=k, random_state=42, n_init=10)
    labels = km.fit_predict(Xs)

    sil = None
    try:
        from sklearn.metrics import silhouette_score

        if len(set(labels)) > 1 and n_rows > k:
            sil = round(float(silhouette_score(Xs, labels)), 4)
    except Exception:
        sil = None

    # RFM-ish label per cluster by monetary centroid rank
    centers = scaler.inverse_transform(km.cluster_centers_)
    m_idx = feat_cols.index("monetary") if "monetary" in feat_cols else -1
    order = sorted(range(k), key=lambda i: (centers[i][m_idx] if m_idx >= 0
                                            else float(centers[i].sum())), reverse=True)
    label_map = {c: (_NAMES[i] if i < len(_NAMES) else f"cluster_{i}") for i, c in enumerate(order)}

    name_col = next((c for c in ("customer_name", "customer", "customer_code")
                     if c in df.columns), None)
    records = df.to_dict("records")
    segs = []
    for i, row in enumerate(records):
        who = row.get("customer_name") or row.get("customer") or row.get("customer_code")
        segs.append({"customer": str(who) if who else f"row_{i}",
                     "cluster": int(labels[i]), "segment": label_map[int(labels[i])]})

    return _result(segs, {"silhouette": sil, "n_clusters": k, "features": feat_cols},
                   centers=centers.tolist(), model=km, scaler=scaler)
