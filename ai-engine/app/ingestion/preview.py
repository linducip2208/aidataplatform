"""Preview + profiling: dtypes, samples, missing, duplicates, warnings."""
from __future__ import annotations

from pathlib import Path
from typing import Any, Dict

import pandas as pd

from app.core.config import settings
from app.ingestion.reader import read_full


def profile_dataframe(df: pd.DataFrame, filename: str = "", size_bytes: int = 0) -> Dict[str, Any]:
    columns = []
    for col in df.columns:
        s = df[col]
        columns.append(
            {
                "name": str(col),
                "dtype": str(s.dtype),
                "missing": int(s.isna().sum()),
                "missing_pct": round(float(s.isna().mean()) * 100, 2) if len(s) else 0.0,
                "unique": int(s.nunique(dropna=True)),
                "sample": [str(v) for v in s.dropna().head(3).tolist()],
            }
        )
    sample_rows = df.head(20).fillna("").to_dict(orient="records")
    dup = int(df.duplicated().sum()) if len(df) else 0
    warnings: list[str] = []
    errors: list[str] = []
    if df.empty:
        errors.append("Dataset is empty after parsing")
    if dup > 0:
        warnings.append(f"{dup} duplicate rows detected")
    for c in columns:
        if c["missing_pct"] > 30:
            warnings.append(f"Column '{c['name']}' has {c['missing_pct']}% missing values")
    if not list(df.columns):
        errors.append("No columns detected")
    return {
        "filename": filename,
        "size_bytes": size_bytes,
        "row_count": int(len(df)),
        "column_count": int(len(df.columns)),
        "columns": columns,
        "sample_rows": sample_rows,
        "duplicate_count": dup,
        "warnings": warnings,
        "errors": errors,
    }


def preview_file(path: str | Path) -> Dict[str, Any]:
    """Profile a file on disk. Returns the profile dict {filename, size_bytes,
    row_count, column_count, columns, sample_rows, duplicate_count, warnings,
    errors}. A missing or unreadable file yields a well-formed profile with the
    reason in ``errors`` rather than raising out of the request handler."""
    p = Path(path)
    if not p.exists():
        return {"filename": p.name, "size_bytes": 0, "row_count": 0, "column_count": 0,
                "columns": [], "sample_rows": [], "duplicate_count": 0,
                "warnings": [], "errors": [f"File not found: {p.name}"]}
    try:
        size = p.stat().st_size
        df = read_full(p, limit_rows=min(50000, settings.chunk_rows * 3))
    except Exception as exc:
        return {"filename": p.name, "size_bytes": 0, "row_count": 0, "column_count": 0,
                "columns": [], "sample_rows": [], "duplicate_count": 0,
                "warnings": [],
                "errors": [f"Could not read {p.name}: {type(exc).__name__}"]}
    # approximate full row count cheaply for csv
    row_count = len(df)
    if p.suffix.lower() == ".csv":
        try:
            with open(p, "r", encoding="utf-8", errors="ignore") as fh:
                row_count = max(0, sum(1 for _ in fh) - 1)
        except Exception:
            pass
    prof = profile_dataframe(df, filename=p.name, size_bytes=size)
    prof["row_count"] = int(row_count)
    return prof
