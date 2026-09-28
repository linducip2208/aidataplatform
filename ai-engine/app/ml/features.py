"""Feature engineering: time-series + customer features."""
from __future__ import annotations

import pandas as pd


def sales_timeseries_features(df: pd.DataFrame, date_col: str = "transaction_date",
                              value_col: str = "revenue") -> pd.DataFrame:
    d = df.copy()
    if date_col in d.columns:
        d[date_col] = pd.to_datetime(d[date_col], errors="coerce")
        d = d.dropna(subset=[date_col]).sort_values(date_col)
        d["dow"] = d[date_col].dt.dayofweek
        d["month"] = d[date_col].dt.month
        d["day"] = d[date_col].dt.day
        d["is_weekend"] = (d["dow"] >= 5).astype(int)
    if value_col in d.columns:
        d[value_col] = pd.to_numeric(d[value_col], errors="coerce").fillna(0)
        for lag in (1, 7, 14, 30):
            d[f"lag_{lag}"] = d[value_col].shift(lag).fillna(0)
        for w in (7, 14, 30):
            d[f"roll_mean_{w}"] = d[value_col].rolling(w, min_periods=1).mean()
            d[f"roll_std_{w}"] = d[value_col].rolling(w, min_periods=1).std().fillna(0)
    return d


def customer_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    # normalize
    ren = {}
    for c in list(d.columns):
        lc = str(c).lower()
        if lc in ("tanggal transaksi", "date", "tgl", "order date"):
            ren[c] = "transaction_date"
        if lc in ("nama customer", "pelanggan", "customer", "customer name", "nm customer"):
            ren[c] = "customer_name"
        if lc in ("total", "omzet"):
            ren[c] = "revenue"
    d = d.rename(columns=ren)
    if "customer_name" not in d.columns:
        d["customer_name"] = "UNKNOWN"
    if "transaction_date" in d.columns:
        d["transaction_date"] = pd.to_datetime(d["transaction_date"], errors="coerce")
    if "revenue" not in d.columns:
        d["revenue"] = 0.0
    d["revenue"] = pd.to_numeric(d["revenue"], errors="coerce").fillna(0)
    ref = d["transaction_date"].max() if "transaction_date" in d.columns else pd.Timestamp.now()
    rows = []
    for cust, g in d.groupby("customer_name"):
        rows.append({
            "customer_name": cust,
            "total_orders": int(len(g)),
            "total_spending": float(g["revenue"].sum()),
            "recency": int((ref - g["transaction_date"].max()).days) if "transaction_date" in d.columns else 0,
            "frequency": int(len(g)),
            "monetary": float(g["revenue"].sum()),
            "aov": float(g["revenue"].mean()) if len(g) else 0.0,
            "tenure": int((ref - g["transaction_date"].min()).days) if "transaction_date" in d.columns else 0,
        })
    return pd.DataFrame(rows)
