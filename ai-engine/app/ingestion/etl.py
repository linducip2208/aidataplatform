"""ETL pipeline: extract chunks -> transform -> per-chunk warehouse load.

Every column written here is declared in ``app/database/models.py``; the fact
tables take naive ``datetime.date`` values because their columns are ``Date``,
and every fact row carries ``import_job_id`` so a re-run replaces rather than
appends.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Callable, Dict, Iterator, List, Optional

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
    *,
    resume: bool = True,
    skip_duplicates: bool = True,
    content_hash_dedup: bool = True,
) -> Dict:
    """Stream a file through mapping, cleaning, quality checks and the warehouse.

    Idempotent per ``import_job_id``: every row this job previously wrote is
    deleted before the new rows land on a *fresh* run, so re-running the same
    job id replaces rather than appends and revenue is never double-counted.
    Each chunk is loaded in its own transaction, so a mid-file failure leaves
    the job re-runnable instead of half-applied.

    Enterprise behaviour (all backward compatible — the historic return keys
    are unchanged, new keys are additive):

    * **Checkpoints / resume**: after every committed chunk a row is written
      to ``import_checkpoints`` (``job_id, chunk_index, rows_done, state``)
      bound to the file's SHA-256. Re-running the same job after a crash
      skips ``done`` chunks and continues where it stopped. Pass
      ``resume=False`` for a clean restart (stale checkpoints are cleared and
      the job's rows are purged again).
    * **Duplicate detection**: a content hash over
      ``file bytes + dataset_type + mappings`` (``dedup_key``) is compared
      against previously completed jobs. A different job id importing
      identical content is skipped without writing rows. Row-level exact
      duplicates inside one file are dropped by content hash and counted in
      ``skipped_duplicate_rows``.
    * **Dead-letter records**: malformed source lines collected by the reader
      plus every row that fails the resilient row-by-row retry are persisted
      to ``dead_letter_records`` with ``(job_id, row_index, raw, reason)``.
    * **Cancel flag**: the job row's ``status == 'cancelled'`` (written by
      ``POST /imports/{id}/cancel``) is checked at every chunk boundary; the
      loop stops and the status is preserved.
    * **Metrics**: ``rows/s, chunks, error rate`` are returned in ``metrics``
      and merged into the persisted report.

    Returns the report dict {import_job_id, dataset_type, total_rows,
    processed_rows, error_rows, quality, error_log, metrics,
    skipped_duplicate_rows, dead_letter_count, resumed_from, cancelled,
    file_hash}. Raises on an unsupported dataset_type rather than silently
    loading nothing.
    """
    from app.ingestion.reader import drain_malformed_rows, iter_chunks

    if dataset_type not in DATASET_TYPES:
        raise ValueError(f"Unsupported dataset_type: {dataset_type!r}. "
                         f"Expected one of {sorted(DATASET_TYPES)}.")

    mappings = mappings or {}
    t0 = time.monotonic()
    total_processed = 0
    total_errors = 0
    skipped_dupe_rows = 0
    dead_count = 0
    error_log: list[dict] = []
    quality_reports: list[dict] = []
    quality_weights: list[int] = []
    resumed_from: list[int] = []
    cancelled = False
    chunks_done = 0
    chunks_skipped = 0
    chunks_seen = 0
    seen_hashes: set[str] = set()

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

    # File identity binds checkpoints and duplicate detection to exact bytes.
    file_hash = _file_sha256(file_path)
    dedup_key = hashlib.sha256(
        f"{file_hash}|{dataset_type}|{json.dumps(mappings, sort_keys=True)}".encode("utf-8")
    ).hexdigest()

    if db_session is not None:
        try:
            from app.ingestion.models import ensure_enterprise_tables

            ensure_enterprise_tables(db_session)
        except Exception:
            pass

    # --- content-hash duplicate skip: another completed job, same bytes, same
    # --- dataset_type and mappings -> nothing new to load.
    duplicate_of: Optional[int] = None
    if skip_duplicates and db_session is not None and import_job_id is not None and file_hash:
        duplicate_of = _find_duplicate_job(db_session, import_job_id, dedup_key)
        if duplicate_of is not None:
            note = {"skipped_duplicate": True, "duplicate_of": duplicate_of,
                    "file_hash": file_hash, "dedup_key": dedup_key,
                    "score": 1.0, "breakdown": {}, "issues": [], "passed": True}
            try:
                _update_job(db_session, import_job_id, status="done", progress=1.0,
                            processed_rows=0, error_rows=0, total_rows=0,
                            report=note, error_log=[])
            except Exception:
                pass
            emit(1.0, "duplicate skipped")
            elapsed = max(1e-6, time.monotonic() - t0)
            return {
                "import_job_id": import_job_id, "dataset_type": dataset_type,
                "total_rows": 0, "processed_rows": 0, "error_rows": 0,
                "quality": note, "error_log": [],
                "metrics": _metrics(0, 0, 0, 0, 0, 0, elapsed),
                "skipped_duplicate_rows": 0, "skipped_duplicate": True,
                "duplicate_of": duplicate_of, "dead_letter_count": 0,
                "resumed_from": [], "cancelled": False, "file_hash": file_hash,
            }

    estimated_rows = _estimate_rows(file_path)
    emit(0.02, "transform")

    # --- resume set: chunks already done for these exact bytes.
    done_chunks: set[int] = set()
    cumulative = 0
    if db_session is not None and import_job_id is not None and resume and file_hash:
        from app.ingestion import checkpoints as _cp

        done_chunks = _cp.completed_chunks(db_session, import_job_id, file_hash)
        cumulative = _cp.last_rows_done(db_session, import_job_id, file_hash)
        resumed_from = sorted(done_chunks)
        total_processed = int(cumulative)
    fresh_start = not bool(done_chunks)

    def _commit_checkpoint(idx: int, rows: int, state: str) -> None:
        if db_session is None or import_job_id is None:
            return
        try:
            from app.ingestion import checkpoints as _cp

            _cp.save_checkpoint(db_session, import_job_id, idx, rows, state, file_hash)
            db_session.commit()
        except Exception:
            try:
                db_session.rollback()
            except Exception:
                pass

    try:
        with _serialise_job(db_session, import_job_id):
            # Fresh runs purge first (idempotency); resumed runs keep the rows
            # written by the completed chunks and only replay the rest.
            if db_session is not None and dataset_type in FACT_DATASETS and fresh_start:
                _purge_job_rows(db_session, dataset_type, import_job_id)
                db_session.commit()
            if db_session is not None and import_job_id is not None and not resume:
                from app.ingestion import checkpoints as _cp

                _cp.clear_checkpoints(db_session, import_job_id)
                try:
                    db_session.commit()
                except Exception:
                    pass

            # Drain any stale malformed rows buffered before this run started.
            drain_malformed_rows()

            try:
                for chunk in iter_chunks(file_path, chunksize=chunksize):
                    idx = chunks_seen
                    chunks_seen += 1
                    if idx in done_chunks:
                        # Resumed: rows already in the warehouse; count them so
                        # progress stays monotonic without re-reading anything.
                        chunks_skipped += 1
                        if estimated_rows > 0:
                            emit(0.05 + 0.65 * min(1.0, (total_processed) / estimated_rows),
                                 f"resumed: skipped chunk {idx} ({total_processed} rows)")
                        else:
                            emit(0.05 + 0.65 * (1 - 1 / (chunks_seen + 1)),
                                 f"resumed: skipped chunk {idx}")
                        continue
                    # Cancel flag is checked at every chunk boundary.
                    if db_session is not None and import_job_id is not None:
                        from app.ingestion import checkpoints as _cp

                        if _cp.is_cancelled(db_session, import_job_id):
                            cancelled = True
                            break
                    n_rows = int(len(chunk))
                    try:
                        if mappings:
                            chunk = apply_mapping(chunk, mappings)
                        chunk = _clean(chunk)
                        # Row-level content-hash dedup inside this import.
                        if content_hash_dedup and not chunk.empty:
                            chunk, n_dupes = _drop_seen_rows(chunk, seen_hashes)
                            skipped_dupe_rows += n_dupes
                        # Malformed source lines the reader skipped for this chunk.
                        malformed = drain_malformed_rows()
                        if db_session is not None and import_job_id is not None:
                            from app.ingestion import checkpoints as _cp2

                            for bad in malformed:
                                _cp2.add_dead_letter(db_session, import_job_id, -1,
                                                     {"line": str(bad.get("line", ""))[:2000],
                                                      "source": str(bad.get("source", ""))[:500]},
                                                     f"malformed_row: {bad.get('reason', 'bad_line')}",
                                                     chunk_index=idx)
                                dead_count += 1
                            if malformed:
                                try:
                                    db_session.commit()
                                except Exception:
                                    pass
                        n_rows = int(len(chunk))
                        if n_rows == 0:
                            # Chunk reduced to nothing by cleaning/dedup: still
                            # checkpoint it so resume does not revisit it.
                            _commit_checkpoint(idx, total_processed, "done")
                            chunks_done += 1
                            continue
                        _commit_checkpoint(idx, total_processed, "started")
                        q = run_quality_checks(chunk, dataset_type)
                        quality_reports.append(q)
                        quality_weights.append(n_rows)
                        written = 0
                        if db_session is not None:
                            base = total_processed
                            written, row_dead = _load_chunk_resilient(
                                db_session, chunk, dataset_type, import_job_id, idx, base)
                            if row_dead and import_job_id is not None:
                                from app.ingestion import checkpoints as _cp3

                                for d in row_dead:
                                    _cp3.add_dead_letter(db_session, import_job_id, d["row_index"],
                                                         d["raw"], d["reason"],
                                                         chunk_index=d.get("chunk_index", idx))
                                    dead_count += 1
                                try:
                                    db_session.commit()
                                except Exception:
                                    pass
                            total_errors += len(row_dead)
                            if row_dead:
                                error_log.append({"chunk": idx, "error": "row_load_failed",
                                                  "dead_rows": len(row_dead)})
                        total_processed += int(written) if db_session is not None else n_rows
                        chunks_done += 1
                        _commit_checkpoint(idx, total_processed, "done")
                    except Exception as exc:
                        total_errors += n_rows
                        error_log.append({"chunk": idx, "error": _safe_error(exc)})
                        log.error(f"chunk {idx} failed: {_safe_error(exc)}")
                        if db_session is not None:
                            try:
                                db_session.rollback()
                            except Exception:
                                pass
                        _commit_checkpoint(idx, total_processed, "failed")
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

    # Leftover malformed lines buffered after the last chunk.
    if db_session is not None and import_job_id is not None:
        try:
            from app.ingestion import checkpoints as _cp4

            for bad in drain_malformed_rows():
                _cp4.add_dead_letter(db_session, import_job_id, -1,
                                     {"line": str(bad.get("line", ""))[:2000]},
                                     f"malformed_row: {bad.get('reason', 'bad_line')}",
                                     chunk_index=chunks_seen)
                dead_count += 1
            db_session.commit()
        except Exception:
            pass

    elapsed = max(1e-6, time.monotonic() - t0)
    metrics = _metrics(total_processed, total_errors, chunks_seen, chunks_done,
                       chunks_skipped, skipped_dupe_rows, elapsed)
    metrics["dead_letter_count"] = int(dead_count)
    metrics["cancelled"] = bool(cancelled)
    quality_agg = _merge_quality(quality_reports, quality_weights)
    quality_agg["file_hash"] = file_hash
    quality_agg["dedup_key"] = dedup_key
    quality_agg["ingestion_metrics"] = dict(metrics)
    if db_session is not None and import_job_id is not None:
        if cancelled:
            _update_job(
                db_session, import_job_id,
                status="cancelled",
                progress=round(min(1.0, total_processed / max(1, estimated_rows or total_processed)), 4)
                if estimated_rows else 0.5,
                processed_rows=total_processed,
                error_rows=total_errors,
                total_rows=total_processed + total_errors,
                report=quality_agg,
                error_log=error_log[:200],
            )
        else:
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
    emit(1.0, "cancelled" if cancelled else "done")
    return {
        "import_job_id": import_job_id,
        "dataset_type": dataset_type,
        "total_rows": total_processed + total_errors,
        "processed_rows": total_processed,
        "error_rows": total_errors,
        "quality": quality_agg,
        "error_log": error_log[:200],
        "metrics": metrics,
        "skipped_duplicate_rows": skipped_dupe_rows,
        "dead_letter_count": int(dead_count),
        "resumed_from": resumed_from,
        "cancelled": bool(cancelled),
        "file_hash": file_hash,
    }


def _file_sha256(file_path: str | Path) -> str:
    """Stream a file's SHA-256 without loading it. Returns "" when unreadable."""
    try:
        h = hashlib.sha256()
        with open(Path(file_path), "rb") as fh:
            for block in iter(lambda: fh.read(1 << 20), b""):
                h.update(block)
        return h.hexdigest()
    except Exception:
        return ""


def _find_duplicate_job(db_session, import_job_id: int, dedup_key: str) -> Optional[int]:
    """Another completed job with the same content key, or None.

    The key binds file bytes + dataset_type + mappings, so a genuinely
    re-uploaded file with different mappings is never treated as a duplicate.
    Bounded: only the 500 most recent completed jobs are inspected.
    """
    if not dedup_key:
        return None
    try:
        from app.database.models import ImportJob

        rows = db_session.query(ImportJob)\
            .filter(ImportJob.id != import_job_id,
                    ImportJob.status.in_(["done", "succeeded", "done_with_errors"]))\
            .order_by(ImportJob.id.desc()).limit(500).all()
        for job in rows:
            try:
                if isinstance(job.report, dict) and job.report.get("dedup_key") == dedup_key:
                    return int(job.id)
            except Exception:
                continue
    except Exception:
        pass
    return None


def _row_hash(rec: dict) -> str:
    """Canonical content hash of one cleaned record for within-file dedup."""
    try:
        items = sorted((str(k), repr(v)) for k, v in rec.items())
        return hashlib.sha256(repr(items).encode("utf-8", "replace")).hexdigest()
    except Exception:
        return ""


def _drop_seen_rows(chunk: pd.DataFrame, seen: set[str]):
    """Drop exact-duplicate rows already seen in this import. Returns the
    filtered frame and the dropped count."""
    try:
        records = chunk.to_dict("records")
    except Exception:
        return chunk, 0
    keep: List[int] = []
    dupes = 0
    for i, rec in enumerate(records):
        h = _row_hash(rec if isinstance(rec, dict) else {"v": str(rec)})
        if h and h in seen:
            dupes += 1
            continue
        if h:
            seen.add(h)
        keep.append(i)
    if dupes and keep:
        return chunk.iloc[keep].reset_index(drop=True), dupes
    if dupes and not keep:
        return chunk.iloc[0:0], dupes
    return chunk, 0


def _load_chunk_resilient(session, df: pd.DataFrame, dataset_type: str,
                          import_job_id: Optional[int], chunk_index: int,
                          base_row_index: int):
    """Load one chunk; on chunk failure retry row-by-row so a single bad row
    becomes a dead-letter record instead of failing ``len(df)`` rows.

    Returns (rows_written, dead_letters). The fast path is one transaction per
    chunk (unchanged behaviour); the row-by-row slow path runs only for chunks
    that failed, each row in its own transaction.
    """
    try:
        written = _load_warehouse(session, df, dataset_type, import_job_id)
        return int(written), []
    except Exception as chunk_exc:
        try:
            session.rollback()
        except Exception:
            pass
        pending_exc = chunk_exc
    try:
        records = df.to_dict("records") if not df.empty else []
    except Exception:
        records = []
    written = 0
    dead: List[Dict] = []
    for offset in range(len(records)):
        single = df.iloc[offset:offset + 1]
        try:
            written += int(_load_warehouse(session, single, dataset_type, import_job_id))
        except Exception as exc:
            try:
                session.rollback()
            except Exception:
                pass
            rec = records[offset]
            dead.append({"row_index": base_row_index + offset, "chunk_index": chunk_index,
                         "raw": rec if isinstance(rec, dict) else {"value": str(rec)},
                         "reason": f"row_load_failed: {_safe_error(exc)}"})
    if not records and not dead:
        # The failure was not row-scoped (e.g. connection loss): re-raise the
        # original chunk error so the chunk error path records it instead of
        # silently writing zero rows.
        raise pending_exc
    return written, dead


def _metrics(processed: int, errors: int, chunks_seen: int, chunks_done: int,
             chunks_skipped: int, dupes: int, elapsed: float) -> Dict:
    total = processed + errors
    return {
        "rows_per_sec": round(processed / elapsed, 2),
        "chunks": int(chunks_seen),
        "chunks_done": int(chunks_done),
        "chunks_skipped": int(chunks_skipped),
        "error_rate": round(errors / total, 4) if total else 0.0,
        "elapsed_sec": round(elapsed, 3),
        "processed_rows": int(processed),
        "skipped_duplicate_rows": int(dupes),
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
