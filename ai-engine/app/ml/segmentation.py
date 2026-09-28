"""KMeans segmentation + RFM labels."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd
from sklearn.cluster import KMeans
from sklearn.metrics import silhouette_score
from sklearn.preprocessing import StandardScaler


def segment(customers: List[Dict[str, Any]], n_clusters: int = 4) -> Dict[str, Any]:
    df = pd.DataFrame(customers)
    if df.empty:
        return {"segments": [], "metrics": {}, "centers": []}
    feat_cols = [c for c in ("total_orders", "total_spending", "recency", "frequency", "monetary", "aov", "tenure") if c in df.columns]
    if not feat_cols:
        # try RFM style
        feat_cols = [c for c in df.columns if c not in ("customer", "customer_name", "segment")]
    X = df[feat_cols].apply(pd.to_numeric, errors="coerce").fillna(0).values
    k = max(2, min(int(n_clusters), len(df)))
    scaler = StandardScaler()
    Xs = scaler.fit_transform(X)
    km = KMeans(n_clusters=k, random_state=42, n_init=10)
    labels = km.fit_predict(Xs)
    sil = None
    try:
        if len(set(labels)) > 1 and len(df) > k:
            sil = round(float(silhouette_score(Xs, labels)), 4)
    except Exception:
        pass
    # RFM-ish label per cluster by monetary centroid rank
    import numpy as np

    centers = scaler.inverse_transform(km.cluster_centers_)
    m_idx = feat_cols.index("monetary") if "monetary" in feat_cols else -1
    order = sorted(range(k), key=lambda i: centers[i][m_idx] if m_idx >= 0 else centers[i].sum(), reverse=True)
    names = ["champions", "loyal", "potential", "at_risk", "new", "dormant", "vip", "regular", "low_value", "other"]
    label_map = {c: names[i] if i < len(names) else f"cluster_{i}" for i, c in enumerate(order)}
    segs = []
    for i, row in enumerate(df.to_dict("records")):
        segs.append({"customer": row.get("customer_name") or row.get("customer") or f"row_{i}",
                     "cluster": int(labels[i]), "segment": label_map[int(labels[i])]})
    return {"segments": segs, "metrics": {"silhouette": sil, "n_clusters": k, "features": feat_cols},
            "centers": centers.tolist(), "model": km, "scaler": scaler}
