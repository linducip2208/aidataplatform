"""Data quality checks + score (completeness/uniqueness/validity/consistency)."""
from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import pandas as pd

from app.core.config import settings
from app.core.logging import get_logger

log = get_logger("ingestion.quality", "quality")


def _is_date_col(s: pd.Series) -> bool:
    name = str(s.name).lower()
    return any(k in name for k in ("date", "tanggal", "tgl"))


def run_quality_checks(df: pd.DataFrame, dataset_type: str = "sales") -> Dict[str, Any]:
    issues: List[Dict[str, Any]] = []
    n = len(df)
    if n == 0:
        return {
            "score": 0.0,
            "breakdown": {"completeness": 0.0, "uniqueness": 0.0, "validity": 0.0, "consistency": 0.0},
            "issues": [{"rule": "empty", "column": None, "count": 0, "sample_rows": [], "message": "Empty dataset"}],
            "passed": False,
        }
    # completeness
    total_cells = n * max(1, len(df.columns))
    null_cells = int(df.isna().sum().sum())
    completeness = 1 - (null_cells / total_cells) if total_cells else 1.0
    for col in df.columns:
        c = int(df[col].isna().sum())
        if c > 0:
            issues.append({"rule": "null", "column": str(col), "count": c,
                           "sample_rows": df[df[col].isna()].index[:5].tolist(),
                           "message": f"{c} null values in {col}"})
    # uniqueness
    dup = int(df.duplicated().sum())
    uniqueness = 1 - (dup / n) if n else 1.0
    if dup:
        issues.append({"rule": "duplicate", "column": None, "count": dup,
                       "sample_rows": df[df.duplicated()].index[:5].tolist(),
                       "message": f"{dup} duplicate rows"})
    # validity
    invalid = 0
    for col in df.columns:
        s = df[col]
        ln = str(col).lower()
        if "quantity" in ln or "qty" in ln or "stock" in ln:
            num = pd.to_numeric(s, errors="coerce")
            neg = int(((num < 0)).sum())
            if neg:
                invalid += neg
                issues.append({"rule": "negative_quantity", "column": str(col), "count": neg,
                               "sample_rows": num[num < 0].index[:5].tolist(),
                               "message": f"{neg} negative values in {col}"})
        if _is_date_col(s):
            parsed = pd.to_datetime(s, errors="coerce")
            bad = int(parsed.isna().sum() - s.isna().sum())
            if bad > 0:
                invalid += bad
                issues.append({"rule": "invalid_date", "column": str(col), "count": bad,
                               "sample_rows": [], "message": f"{bad} invalid dates in {col}"})
            elif len(s) and parsed.isna().all():
                # Already all-null: parsed.isna() equals s.isna(), so the count
                # above is 0. The column is unusable and the ETL will write NULL
                # into every fact row, so it has to be reported explicitly.
                invalid += int(len(s))
                issues.append({"rule": "unparsable_date", "column": str(col),
                               "count": int(len(s)), "sample_rows": [],
                               "message": f"column {col} has no parseable date value"})
        if any(k in ln for k in ("price", "harga", "revenue", "amount", "cost", "total")):
            num = pd.to_numeric(s, errors="coerce")
            bad = int(num.isna().sum() - s.isna().sum())
            if bad > 0:
                invalid += bad
                issues.append({"rule": "invalid_currency", "column": str(col), "count": bad,
                               "sample_rows": [], "message": f"{bad} invalid currency values in {col}"})
    validity = max(0.0, 1 - (invalid / total_cells)) if total_cells else 1.0
    # consistency: outliers via IQR on numeric cols
    inconsistent = 0
    for col in df.columns:
        s = pd.to_numeric(df[col], errors="coerce").dropna()
        if len(s) < 8:
            continue
        q1, q3 = s.quantile(0.25), s.quantile(0.75)
        iqr = q3 - q1
        if iqr == 0:
            continue
        out = int(((s < q1 - 1.5 * iqr) | (s > q3 + 1.5 * iqr)).sum())
        if out:
            inconsistent += out
            issues.append({"rule": "outlier", "column": str(col), "count": out,
                           "sample_rows": [], "message": f"{out} outliers in {col} (IQR)"})
        # zscore guard
        try:
            z = (s - s.mean()) / (s.std() or 1)
            zout = int((z.abs() > 4).sum())
            if zout and zout != out:
                issues.append({"rule": "outlier_zscore", "column": str(col), "count": zout,
                               "sample_rows": [], "message": f"{zout} extreme outliers in {col} (|z|>4)"})
        except Exception:
            pass
    consistency = max(0.0, 1 - (inconsistent / total_cells)) if total_cells else 1.0
    score = round(float(np.mean([completeness, uniqueness, validity, consistency])), 4)
    passed = score >= settings.quality_min_score
    return {
        "score": score,
        "breakdown": {
            "completeness": round(float(completeness), 4),
            "uniqueness": round(float(uniqueness), 4),
            "validity": round(float(validity), 4),
            "consistency": round(float(consistency), 4),
        },
        "issues": issues,
        "passed": bool(passed),
    }


def persist_report(db_session, import_job_id: int | None, result: Dict[str, Any]):
    """Write a quality report to data_quality_reports and return the row, or None
    when there is no session or the write fails (logged, transaction rolled back)."""
    if db_session is None:
        return None
    try:
        from app.database.models import DataQualityReport

        rep = DataQualityReport(
            import_job_id=import_job_id,
            score=float(result.get("score", 0)),
            breakdown=dict(result.get("breakdown", {})),
            issues=list(result.get("issues", [])),
        )
        db_session.add(rep)
        db_session.commit()
        db_session.refresh(rep)
        return rep
    except Exception as exc:
        try:
            db_session.rollback()
        except Exception:
            pass
        log.error(f"quality report for import_job {import_job_id} not persisted "
                  f"({type(exc).__name__})")
        return None
