"""ETL pipeline: extract chunks -> transform -> staging -> warehouse upsert."""
from __future__ import annotations

from datetime import datetime
from pathlib import Path
from typing Callable, Dict, Optional

import pandas as pd

from app.core.logging import get_logger
from app.ingestion.mapper import apply_mapping
from app.ingestion.quality import run_quality_checks

log = get_logger("ingestion.etl", "etl")

ProgressCb = Optional[Callable[[float, str], None]]


def _clean(df: pd.DataFrame) -> pd.DataFrame:
    df = df.copy()
    df.columns = [str(c).strip() for c in df.columns]
    for col in df.columns:
        if df[col].dtype == object:
            df[col] = df[col].astype(str).str.strip().replace({"": None, "nan": None, "None": None})
    # coerce dates
    for col in df.columns:
        ln = str(col).lower()
        if "date" in ln or "tanggal" in ln or ln in ("snapshot_date", "purchase_date", "expense_date", "transaction_date"):
            df[col] = pd.to_datetime(df[col], errors="coerce")
    # coerce numerics
    for col in df.columns:
        ln = str(col).lower()
        if any(k in ln for k in ("quantity", "price", "revenue", "discount", "stock", "cost", "amount")):
            df[col] = pd.to_numeric(df[col], errors="coerce").fillna(0)
    # revenue derivation
    if "revenue" in df.columns and (df["revenue"] == 0).all():
        if "quantity" in df.columns and "selling_price" in df.columns:
            df["revenue"] = df["quantity"] * df["selling_price"] - df.get("discount", 0)
    return df


def run_etl(
    file_path: str | Path,
    dataset_type: str = "sales",
    mappings: Optional[Dict[str, str]] = None,
    import_job_id: Optional[int] = None,
    db_session=None,
    progress: ProgressCb = None,
    chunksize: int = 20000,
) -> Dict:
    """Idempotent per import_job_id: warehouse loader deletes prior rows for that job first."""
    from app.ingestion.reader import iter_chunks

    mappings = mappings or {}
    total_processed = 0
    total_errors = 0
    error_log: list[dict] = []
    staged_frames: list[pd.DataFrame] = []
    quality_agg: Dict = {}

    def emit(p: float, msg: str):
        if progress:
            try:
                progress(p, msg)
            except Exception:
                pass

    chunks = list(iter_chunks(file_path, chunksize=chunksize))
    n_chunks = max(1, len(chunks))
    for i, chunk in enumerate(chunks):
        emit(i / n_chunks * 0.7, f"transform chunk {i+1}/{n_chunks}")
        try:
            if mappings:
                chunk = apply_mapping(chunk, mappings)
            chunk = _clean(chunk)
            q = run_quality_checks(chunk, dataset_type)
            quality_agg = q
            staged_frames.append(chunk)
            total_processed += len(chunk)
        except Exception as exc:
            total_errors += len(chunk)
            error_log.append({"chunk": i, "error": str(exc)})
            log.error(f"chunk {i} failed: {exc}")
    emit(0.75, "loading warehouse")
    staged = pd.concat(staged_frames, ignore_index=True) if staged_frames else pd.DataFrame()
    if db_session is not None and not staged.empty:
        _load_warehouse(db_session, staged, dataset_type, import_job_id)
    if db_session is not None and import_job_id is not None:
        try:
            from app.database.models import ImportJob

            job = db_session.query(ImportJob).filter_by(id=import_job_id).first()
            if job:
                job.status = "done" if not error_log else "done_with_errors"
                job.progress = 1.0
                job.processed_rows = total_processed
                job.error_rows = total_errors
                job.total_rows = total_processed + total_errors
                job.report = quality_agg
                job.error_log = error_log[:200]
                db_session.commit()
        except Exception:
            try:
                db_session.rollback()
            except Exception:
                pass
    emit(1.0, "done")
    return {
        "import_job_id": import_job_id,
        "dataset_type": dataset_type,
        "total_rows": total_processed + total_errors,
        "processed_rows": total_processed,
        "error_rows": total_errors,
        "quality": quality_agg,
        "error_log": error_log[:200],
    }


def _get_or_create(session, model, key_field: str, key_value: str, defaults: dict):
    if not key_value:
        return None
    row = session.query(model).filter(getattr(model, key_field) == key_value).first()
    if row:
        return row
    row = model(**{key_field: key_value, **defaults})
    session.add(row)
    session.flush()
    return row


def _load_warehouse(session, df: pd.DataFrame, dataset_type: str, import_job_id: Optional[int]):
    from app.database.models import (
        DimBranch, DimCustomer, DimDepartment, DimProduct, DimSupplier, DimWarehouse,
        FactExpense, FactInventory, FactPurchase, FactSales,
    )

    def _date(v):
        try:
            if v is None or (isinstance(v, float) and pd.isna(v)):
                return None
            ts = pd.to_datetime(v, errors="coerce")
            if pd.isna(ts):
                return None
            return ts.date()
        except Exception:
            return None

    if dataset_type == "sales":
        if import_job_id is not None:
            session.query(FactSales).filter_by(import_job_id=import_job_id).delete()
        for _, r in df.iterrows():
            g = lambda k: r.get(k) if k in r else None  # noqa: E731
            cust = _get_or_create(session, DimCustomer, "customer_code",
                                  str(g("customer_code") or g("customer_name") or "").strip(),
                                  {"customer_name": str(g("customer_name") or "")})
            prod = _get_or_create(session, DimProduct, "product_code",
                                  str(g("product_code") or g("product_name") or "").strip(),
                                  {"product_name": str(g("product_name") or ""),
                                   "selling_price": float(g("selling_price") or 0)})
            br = _get_or_create(session, DimBranch, "branch_code",
                                str(g("branch_name") or "").strip() or "DEFAULT",
                                {"branch_name": str(g("branch_name") or "DEFAULT")})
            qty = float(g("quantity") or 0)
            price = float(g("selling_price") or 0)
            disc = float(g("discount") or 0)
            rev = float(g("revenue") or (qty * price - disc))
            session.add(FactSales(
                transaction_date=_date(g("transaction_date")),
                customer_id=cust.id if cust else None,
                product_id=prod.id if prod else None,
                branch_id=br.id if br else None,
                quantity=qty, selling_price=price, discount=disc, revenue=rev,
                import_job_id=import_job_id,
            ))
    elif dataset_type == "inventory":
        if import_job_id is not None:
            session.query(FactInventory).filter_by(import_job_id=import_job_id).delete()
        for _, r in df.iterrows():
            g = lambda k: r.get(k) if k in r else None  # noqa: E731
            prod = _get_or_create(session, DimProduct, "product_code",
                                  str(g("product_code") or g("product_name") or "").strip(),
                                  {"product_name": str(g("product_name") or "")})
            wh = _get_or_create(session, DimWarehouse, "warehouse_code",
                                str(g("warehouse_name") or "").strip() or "DEFAULT",
                                {"warehouse_name": str(g("warehouse_name") or "DEFAULT")})
            session.add(FactInventory(
                snapshot_date=_date(g("snapshot_date")), product_id=prod.id if prod else None,
                warehouse_id=wh.id if wh else None, stock_qty=float(g("stock_qty") or 0),
                import_job_id=import_job_id))
    elif dataset_type == "purchases":
        if import_job_id is not None:
            session.query(FactPurchase).filter_by(import_job_id=import_job_id).delete()
        for _, r in df.iterrows():
            g = lambda k: r.get(k) if k in r else None  # noqa: E731
            sup = _get_or_create(session, DimSupplier, "supplier_code",
                                 str(g("supplier_code") or g("supplier_name") or "").strip(),
                                 {"supplier_name": str(g("supplier_name") or "")})
            prod = _get_or_create(session, DimProduct, "product_code",
                                  str(g("product_code") or "").strip(),
                                  {"product_name": str(g("product_name") or "")})
            session.add(FactPurchase(
                purchase_date=_date(g("purchase_date")), supplier_id=sup.id if sup else None,
                product_id=prod.id if prod else None, quantity=float(g("quantity") or 0),
                cost=float(g("cost") or 0), import_job_id=import_job_id))
    elif dataset_type == "expenses":
        if import_job_id is not None:
            session.query(FactExpense).filter_by(import_job_id=import_job_id).delete()
        for _, r in df.iterrows():
            g = lambda k: r.get(k) if k in r else None  # noqa: E731
            dept = _get_or_create(session, DimDepartment, "dept_code",
                                  str(g("department_name") or "").strip() or "GENERAL",
                                  {"dept_name": str(g("department_name") or "GENERAL")})
            session.add(FactExpense(
                expense_date=_date(g("expense_date")), department_id=dept.id if dept else None,
                amount=float(g("amount") or 0), category=str(g("category") or ""),
                import_job_id=import_job_id))
    session.commit()
