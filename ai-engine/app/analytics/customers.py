"""Customer analytics: RFM quintiles, CLV, cohort retention."""
from __future__ import annotations

from datetime import datetime
from typing import Any, Dict, List

import pandas as pd


def _prep(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    ren = {}
    for c in list(d.columns):
        lc = str(c).lower()
        if lc in ("tanggal transaksi", "date", "order date", "tgl"):
            ren[c] = "transaction_date"
        if lc in ("nama customer", "pelanggan", "customer", "customer name", "nm customer"):
            ren[c] = "customer_name"
        if lc in ("total", "omzet"):
            ren[c] = "revenue"
    d = d.rename(columns=ren)
    if "customer_name" not in d.columns:
        d["customer_name"] = d.get("customer_code", "UNKNOWN").astype(str) if "customer_code" in d.columns else "UNKNOWN"
    if "transaction_date" in d.columns:
        d["transaction_date"] = pd.to_datetime(d["transaction_date"], errors="coerce")
    if "revenue" in d.columns:
        d["revenue"] = pd.to_numeric(d["revenue"], errors="coerce").fillna(0)
    else:
        d["revenue"] = 0.0
    return d


def _quintile_score(s: pd.Series, reverse: bool = False) -> pd.Series:
    try:
        q = pd.qcut(s.rank(method="first"), 5, labels=[1, 2, 3, 4, 5])
        scores = q.astype(int)
    except Exception:
        # fallback: rank-based linear scaling
        r = s.rank(pct=True)
        scores = (r * 5).clip(1, 5).round().astype(int)
    if reverse:
        scores = 6 - scores
    return scores


def rfm(df: pd.DataFrame, ref_date=None) -> List[Dict[str, Any]]:
    d = _prep(df)
    if d.empty:
        return []
    ref = pd.to_datetime(ref_date) if ref_date else (
        d["transaction_date"].max() if "transaction_date" in d.columns else pd.Timestamp.now()
    )
    g = d.groupby("customer_name").agg(
        last=("transaction_date", "max") if "transaction_date" in d.columns else ("revenue", "size"),
        frequency=("revenue", "size"),
        monetary=("revenue", "sum"),
    )
    if "transaction_date" in d.columns:
        g["recency_days"] = (ref - g["last"]).dt.days.clip(lower=0)
    else:
        g["recency_days"] = 0
    g["r_score"] = _quintile_score(g["recency_days"], reverse=True)
    g["f_score"] = _quintile_score(g["frequency"])
    g["m_score"] = _quintile_score(g["monetary"])

    def label(r):
        s = r["r_score"] + r["f_score"] + r["m_score"]
        if s >= 13:
            return "champions"
        if s >= 11:
            return "loyal"
        if r["r_score"] <= 2:
            return "at_risk"
        if r["f_score"] <= 2:
            return "new"
        return "potential"

    g["segment"] = g.apply(label, axis=1)
    out = []
    for cust, row in g.iterrows():
        out.append({"customer": str(cust), "recency_days": int(row["recency_days"]),
                    "frequency": int(row["frequency"]), "monetary": round(float(row["monetary"]), 2),
                    "r_score": int(row["r_score"]), "f_score": int(row["f_score"]),
                    "m_score": int(row["m_score"]), "segment": str(row["segment"])})
    return sorted(out, key=lambda x: x["monetary"], reverse=True)


def clv(df: pd.DataFrame) -> List[Dict[str, Any]]:
    rows = rfm(df)
    for r in rows:
        # simple CLV = monetary * (frequency / max(1, recency_months+1))
        r["clv"] = round(r["monetary"] * (1 + r["frequency"] / 10), 2)
    return rows


def cohort_retention(df: pd.DataFrame) -> List[Dict[str, Any]]:
    d = _prep(df)
    if d.empty or "transaction_date" not in d.columns:
        return []
    d = d.dropna(subset=["transaction_date"]).copy()
    d["cohort"] = d.groupby("customer_name")["transaction_date"].transform("min").dt.to_period("M")
    d["period"] = d["transaction_date"].dt.to_period("M")
    d["offset"] = (d["period"].astype(int) - d["cohort"].astype(int))
    cohorts = d.groupby(["cohort", "offset"])["customer_name"].nunique().reset_index()
    base = cohorts[cohorts["offset"] == 0].set_index("cohort")["customer_name"].to_dict()
    out = []
    for _, row in cohorts.iterrows():
        b = base.get(row["cohort"], 1) or 1
        out.append({"cohort": str(row["cohort"]), "period_offset": int(row["offset"]),
                    "retention_pct": round(row["customer_name"] / b * 100, 2),
                    "active_customers": int(row["customer_name"])})
    return out
