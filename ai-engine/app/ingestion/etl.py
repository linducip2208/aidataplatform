"""ETL pipeline: extract chunks -> transform -> per-chunk warehouse load.

Every column written here is declared in ``app/database/models.py``; the fact
tables take naive ``datetime.date`` values because their columns are ``Date``,
and every fact row carries ``import_job_id`` so a re-run replaces rather than
appends.
"""
from __future__ import annotations

import re
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Callable, Dict, Iterator, Optional

import pandas as pd
from sqlalchemy import text

from app.core.config import settings
from app.core.logging import get_logger
from app.ingestion.mapper import apply_mapping
from app.ingestion.quality import run_quality_checks

log = get_logger("ingestion.etl", "etl")

# "scheme://user:password@host" -> "scheme://user:***@host"
_URL_CREDENTIALS_RE = re.compile(r"(://[^:/@\s]+:)([^@/\s]+)(@)")
# "api_key=sk-live-...", "password: hunter2"
_SECRET_ASSIGNMENT_RE = re.compile(
    r"(?i)\b(api[_-]?key|secret|password|passwd|token|authorization)\b(\s*[=:]\s*)(\S+)"
)

ProgressCb = Optional[Callable[[float, str], None]]

# dataset_type -> warehouse target. Must stay in step with CANONICAL_FIELDS in
# app/ingestion/mapper.py; an unlisted type is rejected rather than silently
# loading zero rows and reporting the job as done.
FACT_DATASETS = ("sales", "inventory", "purchases", "expenses")
DIM_DATASETS = ("customers", "products")
DATASET_TYPES = FACT_DATASETS + DIM_DATASETS


@contextmanager
def _serialise_job(db_session, import_job_id: int | None) -> Iterator[bool]:
    """Hold a Postgres advisory lock for the whole purge-and-load window.

    The purge and every chunk commit separately, so a transaction-scoped lock
    would be released before the inserts began. Nothing in the schema enforces
    that a given ``import_job_id`` is loaded once, so two concurrent runs — a
    Celery retry racing the original, or a double ``POST /imports/commit`` —
    would interleave the purge and the inserts and double the revenue.

    The lock is session-scoped, which means it survives a rollback and would
    leak to whoever borrows the pooled connection next. The unlock therefore
    runs in a ``finally`` on every path, and if it fails the session is closed so
    the connection is discarded rather than returned with the lock held.
    """
    if db_session is None or import_job_id is None:
        yield False
        return

    try:
        dialect = db_session.get_bind().dialect.name
    except Exception:
        yield False
        return

    if dialect != "postgresql":
        yield False
        return

    key = int(import_job_id)
    db_session.execute(text("SELECT pg_advisory_lock(:key)"), {"key": key})
    try:
        yield True
    finally:
        try:
            db_session.execute(text("SELECT pg_advisory_unlock(:key)"), {"key": key})
            db_session.commit()
        except Exception:
            log.error("could not release the advisory lock for import_job %s; "
                      "discarding the connection so the lock is not leaked", import_job_id)
            try:
                db_session.close()
            except Exception:
                pass


def _safe_error(exc: BaseException) -> str:
    """Render an exception without leaking credentials. A driver or DSN error can
    embed the connection string, and this text is persisted to import_jobs and
    returned to API callers."""
    text = f"{type(exc).__name__}: {exc}"
    text = _URL_CREDENTIALS_RE.sub(r"\1***\3", text)
    return _SECRET_ASSIGNMENT_RE.sub(r"\1\2***", text)


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


def _estimate_rows(file_path: str | Path, limit: int = 50_000_000) -> int:
    """Best-effort row count for progress reporting only. Counts newlines for
    text formats; returns 0 when the file is too large to scan cheaply or the
    format is binary, which makes the caller fall back to an indeterminate
    progress curve. Never raises."""
    try:
        p = Path(file_path)
        if p.suffix.lower() not in (".csv", ".json", ".xml") or not p.is_file():
            return 0
        if p.stat().st_size > limit:
            return 0
        with open(p, "rb") as fh:
            return max(0, sum(chunk.count(b"\n") for chunk in iter(lambda: fh.read(1 << 20), b"")) - 1)
    except Exception:
        return 0


def _merge_quality(reports: list[dict], weights: list[int]) -> Dict:
    """Row-count-weighted merge of per-chunk quality reports. completeness,
    validity and consistency are all ``1 - bad_cells / (rows * cols)`` and
    uniqueness is ``1 - dupes / rows``, so weighting by row count reproduces
    the whole-frame score exactly. Returns the zero-score envelope for no rows."""
    keys = ("completeness", "uniqueness", "validity", "consistency")
    if not reports or sum(weights) <= 0:
        return {"score": 0.0, "breakdown": {k: 0.0 for k in keys},
                "issues": [{"rule": "empty", "column": None, "count": 0,
                            "sample_rows": [], "message": "No rows were staged"}],
                "passed": False}
    total = float(sum(weights))
    breakdown = {
        k: round(sum(float(r.get("breakdown", {}).get(k, 0.0)) * w
                     for r, w in zip(reports, weights)) / total, 4)
        for k in keys
    }
    issues: list = []
    for r in reports:
        issues.extend(r.get("issues") or [])
    score = round(sum(breakdown[k] for k in keys) / 4.0, 4)
    return {"score": score, "breakdown": breakdown, "issues": issues[:200],
            "passed": bool(score >= settings.quality_min_score)}


def _update_job(db_session, import_job_id: Optional[int], **fields) -> None:
    """Persist import_job progress/status. Rolls back and re-raises on failure so
    a failed job never reports 'done'."""
    if db_session is None or import_job_id is None:
        return
    from app.database.models import ImportJob

    try:
        job = db_session.query(ImportJob).filter_by(id=import_job_id).first()
        if job is None:
            db_session.rollback()
            return
        for key, value in fields.items():
            setattr(job, key, value)
        db_session.commit()
    except Exception:
        try:
            db_session.rollback()
        except Exception:
            pass
        log.error(f"import_job {import_job_id}: could not persist {sorted(fields)}")
        raise


def run_etl(
    file_path: str | Path,
    dataset_type: str = "sales",
    mappings: Optional[Dict[str, str]] = None,
    import_job_id: Optional[int] = None,
    db_session=None,
    progress: ProgressCb = None,
    chunksize: int = 20000,
) -> Dict:
    """Stream a file through mapping, cleaning, quality checks and the warehouse.

    Idempotent per ``import_job_id``: every row this job previously wrote is
    deleted before the new rows land, so re-running the same job id replaces
    rather than appends and revenue is never double-counted. Each chunk is
    loaded in its own transaction, so a mid-file failure leaves the job
    re-runnable instead of half-applied.

    Returns the report dict {import_job_id, dataset_type, total_rows,
    processed_rows, error_rows, quality, error_log}. Raises on an unsupported
    dataset_type rather than silently loading nothing.
    """
    from app.ingestion.reader import iter_chunks

    if dataset_type not in DATASET_TYPES:
        raise ValueError(f"Unsupported dataset_type: {dataset_type!r}. "
                         f"Expected one of {sorted(DATASET_TYPES)}.")

    mappings = mappings or {}
    total_processed = 0
    total_errors = 0
    error_log: list[dict] = []
    quality_reports: list[dict] = []
    quality_weights: list[int] = []

    def emit(p: float, msg: str):
        if progress:
            try:
                progress(min(1.0, max(0.0, float(p))), msg)
            except Exception:
                pass

    def abort(stage: str, exc: BaseException) -> str:
        """Roll the session back and mark the job failed. Returns a message safe
        to log: exception text from a driver can carry a DSN, so only the type
        reaches the job row and the log."""
        if db_session is not None:
            try:
                db_session.rollback()
            except Exception:
                pass
        message = f"{stage}: {type(exc).__name__}"
        if import_job_id is not None:
            try:
                _update_job(db_session, import_job_id, status="failed",
                            report={"stage": stage, "error": message},
                            error_log=error_log[:200] + [{"error": message}])
            except Exception:
                pass
        log.error(f"import_job {import_job_id} aborted at {stage}: {type(exc).__name__}")
        return message

    estimated_rows = _estimate_rows(file_path)
    emit(0.02, "transform")

    chunks_seen = 0
    try:
        with _serialise_job(db_session, import_job_id):
            # The delete runs before any insert and unconditionally for this job,
            # so a rerun that stages zero rows cannot leave last run's revenue
            # behind. It is inside the advisory lock because a concurrent run of
            # the same job would otherwise interleave its purge with these
            # inserts and double every fact row.
            if db_session is not None and dataset_type in FACT_DATASETS:
                _purge_job_rows(db_session, dataset_type, import_job_id)
                db_session.commit()

            try:
                for chunk in iter_chunks(file_path, chunksize=chunksize):
                    chunks_seen += 1
                    n_rows = int(len(chunk))
                    try:
                        if mappings:
                            chunk = apply_mapping(chunk, mappings)
                        chunk = _clean(chunk)
                        q = run_quality_checks(chunk, dataset_type)
                        quality_reports.append(q)
                        quality_weights.append(n_rows)
                        if db_session is not None:
                            _load_warehouse(db_session, chunk, dataset_type, import_job_id)
                        total_processed += n_rows
                    except Exception as exc:
                        total_errors += n_rows
                        error_log.append({"chunk": chunks_seen - 1, "error": _safe_error(exc)})
                        log.error(f"chunk {chunks_seen - 1} failed: {_safe_error(exc)}")
                        if db_session is not None:
                            try:
                                db_session.rollback()
                            except Exception:
                                pass
                    if estimated_rows > 0:
                        emit(0.05 + 0.65 * min(1.0, total_processed / estimated_rows),
                             f"transformed {total_processed}/{estimated_rows} rows")
                    else:
                        emit(0.05 + 0.65 * (1 - 1 / (chunks_seen + 1)),
                             f"transformed {total_processed} rows")
            except Exception as exc:
                raise RuntimeError(abort("read", exc)) from exc
    except Exception as exc:
        raise RuntimeError(abort("lock", exc)) from exc

    quality_agg = _merge_quality(quality_reports, quality_weights)
    if db_session is not None and import_job_id is not None:
        _update_job(
            db_session, import_job_id,
            status="done_with_errors" if error_log else ("done" if total_processed else "failed"),
            progress=1.0,
            processed_rows=total_processed,
            error_rows=total_errors,
            total_rows=total_processed + total_errors,
            report=quality_agg,
            error_log=error_log[:200],
        )
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
    """Return the row matching ``key_value`` on ``key_field``, creating it when
    absent. Returns None for an empty key so the caller can store a NULL FK."""
    if not key_value:
        return None
    row = session.query(model).filter(getattr(model, key_field) == key_value).first()
    if row:
        return row
    row = model(**{key_field: key_value, **defaults})
    session.add(row)
    session.flush()
    return row


def _purge_job_rows(session, dataset_type: str, import_job_id: Optional[int]) -> int:
    """Delete every fact row previously written by ``import_job_id``. This is the
    dedupe key that makes a re-run replace instead of double-count."""
    if import_job_id is None:
        return 0
    from app.database.models import FactExpense, FactInventory, FactPurchase, FactSales

    model = {"sales": FactSales, "inventory": FactInventory,
             "purchases": FactPurchase, "expenses": FactExpense}.get(dataset_type)
    if model is None:
        return 0
    return int(session.query(model).filter_by(import_job_id=import_job_id).delete() or 0)


def _key(v) -> str:
    """Normalise a dimension business key.

    ``iterrows`` used to upcast every row to a common dtype, turning an integer
    product code into ``123.0`` and writing that into ``dim_product``. Records
    are now built with ``to_dict("records")`` so dtypes survive; this helper
    additionally collapses an integral float so a genuinely float-typed code
    column cannot reintroduce the same corruption.
    """
    if v is None:
        return ""
    try:
        if pd.isna(v):
            return ""
    except (TypeError, ValueError):
        pass
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return str(v).strip()


def _num(v) -> float:
    """Coerce a staged value to float, returning 0.0 for None, NaN and any
    unparseable string rather than raising out of the row loop."""
    if v is None:
        return 0.0
    try:
        f = pd.to_numeric(v, errors="coerce")
    except (TypeError, ValueError):
        return 0.0
    if f is None:
        return 0.0
    try:
        if pd.isna(f):
            return 0.0
        return float(f)
    except (TypeError, ValueError):
        return 0.0


def _date(v):
    """Convert a staged value to a naive ``datetime.date`` for the ``Date``
    columns on the fact tables, or None when it is missing/unparseable."""
    if v is None:
        return None
    try:
        if pd.isna(v):
            return None
    except (TypeError, ValueError):
        pass
    try:
        ts = pd.Timestamp(v)
        if pd.isna(ts):
            return None
        if ts.tzinfo is not None:
            ts = ts.tz_convert("UTC").tz_localize(None)
        return ts.date()
    except (TypeError, ValueError, OverflowError):
        return None


def _upsert_dim(session, model, key_field: str, records: list[dict], attr_fields: tuple):
    """Insert-or-refresh dimension rows keyed on ``key_field``. Unlike
    ``_get_or_create`` this also updates the descriptive attributes, so a
    re-import of a corrected file repairs the dimension instead of leaving the
    stale first value in place."""
    seen = 0
    for rec in records:
        key = _key(rec.get(key_field))
        if not key:
            continue
        row = session.query(model).filter(getattr(model, key_field) == key).first()
        if row is None:
            row = model(**{key_field: key})
            session.add(row)
        for attr in attr_fields:
            if attr in rec:
                value = rec[attr]
                setattr(row, attr, "" if value is None else value)
        session.flush()
        seen += 1
    return seen


def _load_warehouse(session, df: pd.DataFrame, dataset_type: str, import_job_id: Optional[int]) -> int:
    """Load one staged chunk into the warehouse and commit it.

    Fact tables are keyed for idempotency on ``import_job_id``: the caller has
    already purged this job's rows, so this is a pure insert. Dimension tables
    are upserted on their business key. Returns the number of rows written and
    rolls the transaction back on any failure.
    """
    from app.database.models import (
        DimBranch, DimCustomer, DimDepartment, DimProduct, DimSupplier, DimWarehouse,
        FactExpense, FactInventory, FactPurchase, FactSales,
    )

    try:
        written = 0
        # to_dict("records") keeps each column's dtype; iterrows() would upcast
        # the whole row to float whenever any value were NaN.
        records = df.to_dict("records") if not df.empty else []

        if dataset_type == "sales":
            for r in records:
                cust = _get_or_create(session, DimCustomer, "customer_code",
                                      _key(r.get("customer_code") or r.get("customer_name")),
                                      {"customer_name": _key(r.get("customer_name")),
                                       "segment": _key(r.get("segment")),
                                       "city": _key(r.get("city"))})
                prod = _get_or_create(session, DimProduct, "product_code",
                                      _key(r.get("product_code") or r.get("product_name")),
                                      {"product_name": _key(r.get("product_name")),
                                       "category": _key(r.get("category")),
                                       "unit": _key(r.get("unit")) or "pcs",
                                       "cost_price": _num(r.get("cost_price")),
                                       "selling_price": _num(r.get("selling_price"))})
                br = _get_or_create(session, DimBranch, "branch_code",
                                    _key(r.get("branch_code") or r.get("branch_name")) or "DEFAULT",
                                    {"branch_name": _key(r.get("branch_name")) or "DEFAULT"})
                qty = _num(r.get("quantity"))
                price = _num(r.get("selling_price"))
                disc = _num(r.get("discount"))
                rev = _num(r.get("revenue")) or max(0.0, qty * price - disc)
                session.add(FactSales(
                    transaction_date=_date(r.get("transaction_date")),
                    customer_id=cust.id if cust else None,
                    product_id=prod.id if prod else None,
                    branch_id=br.id if br else None,
                    quantity=qty, selling_price=price, discount=disc, revenue=rev,
                    import_job_id=import_job_id,
                ))
                written += 1
        elif dataset_type == "inventory":
            for r in records:
                prod = _get_or_create(session, DimProduct, "product_code",
                                      _key(r.get("product_code") or r.get("product_name")),
                                      {"product_name": _key(r.get("product_name")),
                                       "category": _key(r.get("category")),
                                       "unit": _key(r.get("unit")) or "pcs",
                                       "cost_price": _num(r.get("cost_price")),
                                       "selling_price": _num(r.get("selling_price"))})
                wh = _get_or_create(session, DimWarehouse, "warehouse_code",
                                    _key(r.get("warehouse_code") or r.get("warehouse_name")) or "DEFAULT",
                                    {"warehouse_name": _key(r.get("warehouse_name")) or "DEFAULT"})
                session.add(FactInventory(
                    snapshot_date=_date(r.get("snapshot_date")),
                    product_id=prod.id if prod else None,
                    warehouse_id=wh.id if wh else None,
                    stock_qty=_num(r.get("stock_qty")),
                    import_job_id=import_job_id))
                written += 1
        elif dataset_type == "purchases":
            for r in records:
                sup = _get_or_create(session, DimSupplier, "supplier_code",
                                     _key(r.get("supplier_code") or r.get("supplier_name")),
                                     {"supplier_name": _key(r.get("supplier_name"))})
                prod = _get_or_create(session, DimProduct, "product_code",
                                      _key(r.get("product_code") or r.get("product_name")),
                                      {"product_name": _key(r.get("product_name")),
                                       "category": _key(r.get("category")),
                                       "unit": _key(r.get("unit")) or "pcs",
                                       "cost_price": _num(r.get("cost"))})
                session.add(FactPurchase(
                    purchase_date=_date(r.get("purchase_date")),
                    supplier_id=sup.id if sup else None,
                    product_id=prod.id if prod else None,
                    quantity=_num(r.get("quantity")),
                    cost=_num(r.get("cost")), import_job_id=import_job_id))
                written += 1
        elif dataset_type == "expenses":
            for r in records:
                dept = _get_or_create(session, DimDepartment, "dept_code",
                                      _key(r.get("dept_code") or r.get("department_name")) or "GENERAL",
                                      {"dept_name": _key(r.get("department_name")) or "GENERAL"})
                session.add(FactExpense(
                    expense_date=_date(r.get("expense_date")),
                    department_id=dept.id if dept else None,
                    amount=_num(r.get("amount")),
                    category=_key(r.get("category")), import_job_id=import_job_id))
                written += 1
        elif dataset_type == "customers":
            written = _upsert_dim(session, DimCustomer, "customer_code", records,
                                  ("customer_name", "segment", "city"))
        elif dataset_type == "products":
            prepared = []
            for r in records:
                item = dict(r)
                item["cost_price"] = _num(r.get("cost_price"))
                item["selling_price"] = _num(r.get("selling_price"))
                item["unit"] = _key(r.get("unit")) or "pcs"
                prepared.append(item)
            written = _upsert_dim(session, DimProduct, "product_code", prepared,
                                  ("product_name", "category", "unit", "cost_price", "selling_price"))
        session.commit()
        return written
    except Exception:
        try:
            session.rollback()
        except Exception:
            pass
        raise
