"""Customer analytics: RFM quintiles, CLV, cohort retention."""
from __future__ import annotations

from typing import Any, Dict, List

import pandas as pd

#: Upper edges of the five RFM percentile bands, in score order.
_BAND_EDGES = [0.0, 0.2, 0.4, 0.6, 0.8, 1.0]
_BAND_LABELS = [1, 2, 3, 4, 5]


def _naive(ts: pd.Timestamp) -> pd.Timestamp:
    """Return ``ts`` as a tz-naive UTC instant. The warehouse stores dates without a
    zone, so a tz-aware reference date has to be flattened before it is subtracted
    from them; mixing the two raises inside pandas rather than returning a number."""
    if isinstance(ts, pd.Timestamp) and ts.tzinfo is not None:
        return ts.tz_convert("UTC").tz_localize(None)
    return ts


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
        if getattr(d["transaction_date"].dtype, "tz", None) is not None:
            d["transaction_date"] = d["transaction_date"].dt.tz_convert("UTC").dt.tz_localize(None)
    if "revenue" in d.columns:
        d["revenue"] = pd.to_numeric(d["revenue"], errors="coerce").fillna(0)
    else:
        d["revenue"] = 0.0
    return d


def _quintile_score(s: pd.Series, reverse: bool = False) -> pd.Series:
    """Return an int Series scored 1..5 by percentile band of the average rank.

    Ranking with ``method="average"`` is what makes the banding deterministic:
    tied values share a rank, so two customers with an identical recency,
    frequency and monetary always land in the same band. Ranking with
    ``method="first"`` instead broke every tie by position, which scored the same
    customer differently depending on where the groupby happened to sort it.

    A series with no spread at all -- one customer, or every value equal, e.g. a
    frame with no date column where everyone scores recency 0 -- scores 5 across
    the board, and stays 5 under ``reverse``: a tied value cannot be worse than
    itself, so a lone customer is the most recent customer there is rather than
    the stalest.
    """
    s = pd.to_numeric(s, errors="coerce").fillna(0)
    if s.shape[0] == 0:
        return pd.Series(dtype="int64")
    if s.nunique() <= 1:
        return pd.Series(5, index=s.index, dtype="int64")
    pct = s.rank(method="average", pct=True)
    scores = pd.cut(pct, bins=_BAND_EDGES, labels=_BAND_LABELS,
                    include_lowest=True).astype("int64")
    return 6 - scores if reverse else scores


def rfm(df: pd.DataFrame, ref_date=None) -> List[Dict[str, Any]]:
    """Return a list of {customer, recency_days, frequency, monetary, r_score,
    f_score, m_score, segment} rows sorted by monetary desc. Empty input yields [].

    ``ref_date`` is the instant recency is measured back from; when it is missing
    or unparseable the last observed transaction is used, and when the frame
    carries no date at all every customer scores recency 0. Rows with an
    unparseable date are dropped, so a customer whose orders are all undated is
    absent from the report rather than scored on a recency nobody knows.
    """
    d = _prep(df)
    if d.empty:
        return []
    if "transaction_date" in d.columns:
        # A row with an unparseable date cannot yield a recency; keeping it would
        # make `int(NaN)` raise and 500 the whole RFM report.
        d = d.dropna(subset=["transaction_date"]).copy()
        if d.empty:
            return []
    try:
        ref = _naive(pd.to_datetime(ref_date, errors="coerce")) if ref_date is not None else pd.NaT
        usable = bool(pd.notna(ref))
    except (TypeError, ValueError):
        ref, usable = pd.NaT, False
    if not usable:
        # An unusable ref_date must not age every customer by the age of the
        # dataset, so fall back to the newest row actually present.
        ref = d["transaction_date"].max() if "transaction_date" in d.columns else pd.NaT
    if pd.isna(ref):
        ref = pd.Timestamp.now()
    if "transaction_date" not in d.columns:
        d["transaction_date"] = ref
    g = d.groupby("customer_name").agg(
        last=("transaction_date", "max"),
        frequency=("revenue", "size"),
        monetary=("revenue", "sum"),
    )
    g["recency_days"] = (ref - g["last"]).dt.days.clip(lower=0).fillna(0).astype(int)
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
    """Return the rfm() rows with an extra "clv" key. Empty input yields [].
    CLV is a spend-frequency heuristic, not a discounted cash-flow projection."""
    rows = rfm(df)
    for r in rows:
        # heuristic CLV: spend scaled up by observed order frequency
        r["clv"] = round(r["monetary"] * (1 + r["frequency"] / 10), 2)
    return rows


def cohort_retention(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Return a list of {cohort, period_offset, retention_pct, active_customers}
    rows keyed on each customer's first purchase month. Empty/undated input yields [].
    ``retention_pct`` is measured against the cohort size at offset 0, which is
    every customer in the cohort by construction."""
    d = _prep(df)
    if d.empty or "transaction_date" not in d.columns:
        return []
    d = d.dropna(subset=["transaction_date"]).copy()
    if d.empty:
        return []
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
