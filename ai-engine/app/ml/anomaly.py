"""Anomaly detection: zscore/IQR + IsolationForest + LOF."""
from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import pandas as pd


def detect_anomalies(series: List[Dict[str, Any]], sensitivity: float = 2.5) -> Dict[str, Any]:
    df = pd.DataFrame(series)
    if df.empty:
        return {"anomalies": [], "metrics": {"n": 0}}
    vcol = next((c for c in df.columns if str(c).lower() in ("value", "y", "revenue", "amount", "quantity")), df.columns[-1])
    dcol = next((c for c in df.columns if "date" in str(c).lower() or str(c).lower() in ("ds", "period")), None)
    y = pd.to_numeric(df[vcol], errors="coerce").fillna(0).values.astype(float)
    n = len(y)
    z = np.zeros(n)
    if n > 2 and np.std(y) > 0:
        z = (y - np.mean(y)) / (np.std(y) or 1)
    q1, q3 = np.quantile(y, 0.25), np.quantile(y, 0.75)
    iqr = q3 - q1 or 1.0
    iqr_flag = (y < q1 - 1.5 * iqr) | (y > q3 + 1.5 * iqr)
    stat_flag = (np.abs(z) > sensitivity) | iqr_flag

    iso_score = np.zeros(n)
    iso_flag = np.zeros(n, dtype=bool)
    try:
        from sklearn.ensemble import IsolationForest

        iso = IsolationForest(contamination=min(0.15, max(0.02, 5 / max(n, 1))), random_state=42)
        lab = iso.fit_predict(y.reshape(-1, 1))
        iso_flag = lab == -1
        iso_score = (-iso.score_samples(y.reshape(-1, 1)))
        iso_score = (iso_score - iso_score.min()) / (iso_score.max() - iso_score.min() + 1e-9)
    except Exception:
        pass
    lof_score = np.zeros(n)
    try:
        from sklearn.neighbors import LocalOutlierFactor

        if n >= 6:
            lof = LocalOutlierFactor(n_neighbors=min(5, n - 1))
            lab = lof.fit_predict(y.reshape(-1, 1))
            lof_score = (-lof.negative_outlier_factor_)
            lof_score = (lof_score - lof_score.min()) / (lof_score.max() - lof_score.min() + 1e-9)
    except Exception:
        pass

    out = []
    for i in range(n):
        stat_s = min(1.0, abs(float(z[i])) / (sensitivity + 1.5))
        combined = float(0.5 * stat_s + 0.3 * float(iso_score[i]) + 0.2 * float(lof_score[i]))
        is_anom = bool(stat_flag[i] or (iso_flag[i] if isinstance(iso_flag, np.ndarray) else False) or combined > 0.65)
        if combined >= 0.85:
            sev = "critical"
        elif combined >= 0.7:
            sev = "high"
        elif combined >= 0.55:
            sev = "medium"
        else:
            sev = "low"
        # explanation: top contributing feature
        feats = {vcol: round(float(abs(y[i] - np.mean(y)) / (np.std(y) or 1)), 2)}
        out.append({"index": i, "date": str(df[dcol].iloc[i]) if dcol else str(i),
                    "value": round(float(y[i]), 2), "anomaly": is_anom,
                    "score": round(combined, 3), "severity": sev if is_anom else "low",
                    "explanation": {"top_features": feats,
                                    "zscore": round(float(z[i]), 2),
                                    "iqr_outlier": bool(iqr_flag[i])}})
    anoms = [r for r in out if r["anomaly"]]
    return {"anomalies": anoms, "all": out,
            "metrics": {"n": n, "n_anomalies": len(anoms)}}
