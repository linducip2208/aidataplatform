"""Churn classifier: LogisticRegression baseline + RandomForest."""
from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, f1_score, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler


def _frame(customers: List[Dict[str, Any]]) -> pd.DataFrame:
    df = pd.DataFrame(customers)
    if df.empty:
        return df
    for col in ("total_orders", "total_spending", "recency", "frequency", "monetary", "aov", "tenure"):
        if col not in df.columns:
            df[col] = 0
        df[col] = pd.to_numeric(df[col], errors="coerce").fillna(0)
    if "churn" not in df.columns and "churned" not in df.columns:
        # heuristic label for training if missing: recency>90 & frequency<=2
        df["churn"] = ((df.get("recency", 0) > 90) & (df.get("frequency", 0) <= 2)).astype(int)
    elif "churned" in df.columns:
        df["churn"] = pd.to_numeric(df["churned"], errors="coerce").fillna(0).astype(int)
    else:
        df["churn"] = pd.to_numeric(df["churn"], errors="coerce").fillna(0).astype(int)
    return df


FEATURES = ["total_orders", "total_spending", "recency", "frequency", "monetary", "aov", "tenure"]


def train_churn(customers: List[Dict[str, Any]]) -> Dict[str, Any]:
    df = _frame(customers)
    X = df[FEATURES].values
    y = df["churn"].values
    scaler = StandardScaler()
    Xs = scaler.fit_transform(X)
    # stratified split when possible
    try:
        Xtr, Xte, ytr, yte = train_test_split(Xs, y, test_size=0.25, random_state=42,
                                              stratify=y if len(set(y)) > 1 else None)
    except Exception:
        Xtr, Xte, ytr, yte = Xs, Xs, y, y
    base = LogisticRegression(max_iter=500)
    base.fit(Xtr, ytr)
    clf = RandomForestClassifier(n_estimators=200, random_state=42, n_jobs=1)
    clf.fit(Xtr, ytr)
    # pick best by f1
    best, best_f1, best_name = base, -1, "logistic"
    for name, m in (("logistic", base), ("random_forest", clf)):
        try:
            f1 = f1_score(yte, m.predict(Xte), zero_division=0)
        except Exception:
            f1 = 0.0
        if f1 > best_f1:
            best, best_f1, best_name = m, f1, name
    proba = best.predict_proba(Xte)[:, 1] if hasattr(best, "predict_proba") else best.predict(Xte)
    pred = best.predict(Xte)
    metrics = {
        "accuracy": round(float(accuracy_score(yte, pred)), 4),
        "precision": round(float(precision_score(yte, pred, zero_division=0)), 4),
        "recall": round(float(recall_score(yte, pred, zero_division=0)), 4),
        "f1": round(float(f1_score(yte, pred, zero_division=0)), 4),
    }
    try:
        if len(set(yte)) > 1:
            metrics["roc_auc"] = round(float(roc_auc_score(yte, proba)), 4)
    except Exception:
        pass
    return {"model": best, "scaler": scaler, "features": FEATURES,
            "metrics": metrics, "algo": best_name}


def predict_churn_proba(model, scaler, rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    df = _frame(rows)
    if df.empty:
        return []
    X = scaler.transform(df[FEATURES].values)
    proba = model.predict_proba(X)[:, 1] if hasattr(model, "predict_proba") else model.predict(X).astype(float)
    out = []
    for i, r in enumerate(rows):
        out.append({"customer": r.get("customer_name") or r.get("customer") or f"row_{i}",
                    "churn_proba": round(float(proba[i]), 4),
                    "churn": bool(proba[i] >= 0.5)})
    return out
