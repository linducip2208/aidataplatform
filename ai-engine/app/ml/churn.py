"""Churn classifier: LogisticRegression baseline + RandomForest.

The endpoint trains on the request payload and scores the same rows, so there is
no artifact file on this path. Two cases used to raise out of the request
handler and are handled explicitly here:

* an empty or single-row cohort — nothing to fit, or sklearn's n_samples
  constraint bites;
* a cohort whose churn labels are all one class — LogisticRegression raises
  "needs samples of at least 2 classes". A constant model reports the observed
  base rate instead of a fabricated per-customer score.
"""
from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import pandas as pd
from sklearn.metrics import accuracy_score, f1_score, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler

FEATURES = ["total_orders", "total_spending", "recency", "frequency", "monetary", "aov", "tenure"]


class ConstantChurnModel:
    """Fallback estimator used when a cohort has a single churn class.

    Reports the observed base rate for every row. This is a real statement about
    the data, not a modelled score; ``metrics["reason"]`` on the training result
    says which class was observed.
    """

    def __init__(self, positive: int, total: int):
        self.positive = int(positive)
        self.total = int(total)
        self._classes = np.array([0, 1])

    def predict_proba(self, X) -> np.ndarray:
        n = len(X)
        p = (self.positive / self.total) if self.total else 0.0
        return np.tile(np.array([1.0 - p, p]), (n, 1))

    def predict(self, X) -> np.ndarray:
        p = (self.positive / self.total) if self.total else 0.0
        return np.full(len(X), 1 if p >= 0.5 else 0, dtype=int)


def _frame(customers: List[Dict[str, Any]]) -> pd.DataFrame:
    """Return a frame with every FEATURES column coerced to float and a ``churn``
    label. Columns absent from the payload default to 0 rather than raising."""
    if not customers:
        return pd.DataFrame(columns=FEATURES + ["churn"])
    df = pd.DataFrame(list(customers))
    if df.empty:
        return pd.DataFrame(columns=FEATURES + ["churn"])
    for col in FEATURES:
        if col not in df.columns:
            df[col] = 0
        df[col] = pd.to_numeric(df[col], errors="coerce").fillna(0)
    if "churn" not in df.columns and "churned" not in df.columns:
        # heuristic label for training if missing: recency>90 & frequency<=2
        df["churn"] = ((df["recency"] > 90) & (df["frequency"] <= 2)).astype(int)
    elif "churned" in df.columns:
        df["churn"] = pd.to_numeric(df["churned"], errors="coerce").fillna(0).astype(int)
    else:
        df["churn"] = pd.to_numeric(df["churn"], errors="coerce").fillna(0).astype(int)
    return df


def train_churn(customers: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fit a churn classifier over customer feature records.

    Returns {"model", "scaler", "features", "metrics", "algo"}. ``model`` and
    ``scaler`` are live sklearn objects and must be persisted with joblib, not
    JSON-serialised. An empty cohort returns ``model=None`` with
    ``metrics["reason"]``; a single-class cohort returns a ConstantChurnModel
    reporting the observed base rate. Both keep the key set stable.
    """
    df = _frame(customers)
    n_rows = int(len(df))
    base_metrics: Dict[str, Any] = {"n_rows": n_rows}
    if n_rows == 0:
        return {"model": None, "scaler": None, "features": FEATURES,
                "metrics": {**base_metrics, "reason": "no customer records to fit"},
                "algo": "none"}

    X = df[FEATURES].to_numpy(dtype=float)
    y = df["churn"].to_numpy(dtype=int)
    n_classes = int(len(set(y.tolist())))

    scaler = StandardScaler()
    Xs = scaler.fit_transform(X)

    if n_rows < 2 or n_classes < 2:
        reason = ("cohort has a single churn class" if n_classes < 2
                  else "cohort has a single customer")
        model = ConstantChurnModel(int(y.sum()), n_rows)
        return {"model": model, "scaler": scaler, "features": FEATURES,
                "metrics": {**base_metrics, "reason": reason,
                            "base_rate": round(float(y.sum()) / n_rows, 4)},
                "algo": "constant"}

    from sklearn.ensemble import RandomForestClassifier
    from sklearn.linear_model import LogisticRegression

    # stratified split when the minority class can support it, plain split otherwise
    try:
        Xtr, Xte, ytr, yte = train_test_split(Xs, y, test_size=0.25, random_state=42, stratify=y)
    except Exception:
        Xtr, Xte, ytr, yte = train_test_split(Xs, y, test_size=0.25, random_state=42)

    base = LogisticRegression(max_iter=500)
    base.fit(Xtr, ytr)
    clf = RandomForestClassifier(n_estimators=200, random_state=42, n_jobs=1)
    clf.fit(Xtr, ytr)
    # pick best by f1
    best, best_f1, best_name = base, -1.0, "logistic"
    for name, m in (("logistic", base), ("random_forest", clf)):
        try:
            f1 = float(f1_score(yte, m.predict(Xte), zero_division=0))
        except Exception:
            f1 = 0.0
        if f1 > best_f1:
            best, best_f1, best_name = m, f1, name
    proba = best.predict_proba(Xte)[:, 1] if hasattr(best, "predict_proba") else np.asarray(best.predict(Xte))
    pred = best.predict(Xte)
    metrics: Dict[str, Any] = {
        **base_metrics,
        "accuracy": round(float(accuracy_score(yte, pred)), 4),
        "precision": round(float(precision_score(yte, pred, zero_division=0)), 4),
        "recall": round(float(recall_score(yte, pred, zero_division=0)), 4),
        "f1": round(float(f1_score(yte, pred, zero_division=0)), 4),
    }
    try:
        if len(set(np.asarray(yte).tolist())) > 1:
            metrics["roc_auc"] = round(float(roc_auc_score(yte, proba)), 4)
    except Exception:
        pass
    return {"model": best, "scaler": scaler, "features": FEATURES,
            "metrics": metrics, "algo": best_name}


def predict_churn_proba(model, scaler, rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Score customer feature records with a fitted model/scaler pair.

    Returns one ``{customer, churn_proba, churn}`` per input row, in input order.
    When the model or scaler is missing or unusable the rows are still returned
    with ``churn_proba: None`` and an ``error`` key rather than raising, so a
    caller never receives a fabricated probability.
    """
    if not rows:
        return []
    df = _frame(list(rows))
    if df.empty:
        return []
    names = [str(r.get("customer_name") or r.get("customer") or r.get("customer_code") or f"row_{i}")
             if isinstance(r, dict) else f"row_{i}" for i, r in enumerate(rows)]

    if model is None or scaler is None:
        reason = "no fitted churn model available"
        return [{"customer": names[i], "churn_proba": None, "churn": None, "error": reason}
                for i in range(len(names))]

    try:
        X = scaler.transform(df[FEATURES].to_numpy(dtype=float))
        raw = model.predict_proba(X)[:, 1] if hasattr(model, "predict_proba") else np.asarray(model.predict(X), dtype=float)
    except Exception as exc:
        reason = f"scoring failed: {type(exc).__name__}"
        return [{"customer": names[i], "churn_proba": None, "churn": None, "error": reason}
                for i in range(len(names))]

    if len(raw) != len(names):
        raw = np.resize(np.asarray(raw, dtype=float), len(names))
    out = []
    for i in range(len(names)):
        p = float(raw[i])
        if not np.isfinite(p):
            out.append({"customer": names[i], "churn_proba": None, "churn": None,
                        "error": "model returned a non-finite probability"})
            continue
        out.append({"customer": names[i], "churn_proba": round(p, 4), "churn": bool(p >= 0.5)})
    return out
