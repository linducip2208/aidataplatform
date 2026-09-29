"""Chunk/streaming reader for CSV, XLS(X), JSON/JSONL, XML, Parquet, ZIP.

Memory contract: every format yields bounded ``chunksize`` DataFrames and
never materialises the whole file, except the small-file fallbacks noted
inline (empty-sheet recovery, XML). Callers needing the whole frame use
``read_full`` with its explicit ``limit_rows`` cap.

Supported suffixes: ``.csv .xlsx .xls .json .jsonl .ndjson .xml .parquet .zip``.

* Legacy ``.xls`` (BIFF) is read with **xlrd** — never openpyxl, which cannot
  parse the OLE2 container and raises ``BadZipFile``.
* CSV uses encoding detection (``utf-8-sig`` → ``utf-8`` → ``latin-1``, plus a
  zero-byte/odd-byte heuristic, no chardet dependency) and delimiter sniffing
  via ``csv.Sniffer`` with a safe fallback to comma.
* Malformed CSV rows are *collected, not raised*: a pandas ``on_bad_lines``
  callback appends the raw line to a per-process buffer retrievable with
  :func:`drain_malformed_rows` and the row is skipped, so one bad line can
  never abort an import — the ETL persists these as dead-letter records.
* Empty files / empty sheets yield zero chunks (not an exception); the
  validator turns that into a warning.
* Parquet supports column projection via the ``columns`` argument.
"""
from __future__ import annotations

import csv
import threading
from pathlib import Path
from typing import Dict, Iterator, List, Optional

import pandas as pd

from app.core.config import settings

__all__ = [
    "detect_encoding",
    "sniff_delimiter",
    "iter_chunks",
    "read_full",
    "drain_malformed_rows",
    "malformed_count",
]

# ---------------------------------------------------------------------------
# malformed-row buffer (per-process, drained by the ETL per chunk)
# ---------------------------------------------------------------------------
_malformed_lock = threading.Lock()
_malformed_buffer: List[Dict] = []


def _record_malformed(source: str, line: object, reason: str = "bad_line") -> None:
    try:
        text = ";".join(str(c) for c in line) if isinstance(line, (list, tuple)) else str(line)
    except Exception:
        text = "<unprintable>"
    with _malformed_lock:
        if len(_malformed_buffer) < 10000:
            _malformed_buffer.append({"source": source, "line": text[:4000], "reason": reason})


def drain_malformed_rows() -> List[Dict]:
    """Pop and return all buffered malformed rows since the last drain."""
    with _malformed_lock:
        rows = list(_malformed_buffer)
        _malformed_buffer.clear()
        return rows


def malformed_count() -> int:
    with _malformed_lock:
        return len(_malformed_buffer)


# ---------------------------------------------------------------------------
# encoding + delimiter detection (chardet-free)
# ---------------------------------------------------------------------------
def detect_encoding(path: Path) -> str:
    """Detect a text encoding without chardet.

    Tries ``utf-8-sig``, ``utf-8``, then ``latin-1`` (which never fails, so it
    is the floor). A NUL byte in the sample means UTF-16; that degrades to
    ``utf-16``. Returns the first encoding that round-trips a 64 KiB sample.
    """
    p = Path(path)
    try:
        with open(p, "rb") as fh:
            sample = fh.read(65536)
    except OSError:
        return "utf-8"
    if not sample:
        return "utf-8"
    if b"\x00" in sample:
        for enc in ("utf-16", "utf-16-le", "utf-16-be"):
            try:
                sample.decode(enc)
                return enc
            except Exception:
                continue
        return "utf-8"
    for enc in ("utf-8-sig", "utf-8", "latin-1"):
        try:
            with open(p, "r", encoding=enc) as fh:
                fh.read(65536)
            return enc
        except Exception:
            continue
    return "utf-8"


def sniff_delimiter(path: Path, encoding: Optional[str] = None) -> str:
    """Sniff the CSV delimiter with ``csv.Sniffer``; falls back to comma."""
    enc = encoding or detect_encoding(Path(path))
    try:
        with open(path, "r", encoding=enc, newline="") as fh:
            sample = fh.read(32768)
        if not sample.strip():
            return ","
        dialect = csv.Sniffer().sniff(sample, delimiters=[",", ";", "\t", "|", ":"])
        delim = dialect.delimiter
        return delim if delim in (",", ";", "\t", "|", ":") else ","
    except Exception:
        return ","


def _bad_line_collector(source: str):
    def _collect(bad_line: List[str]):  # pandas calls with list[str]; return None skips
        _record_malformed(source, bad_line, reason="bad_line")
        return None

    return _collect


# ---------------------------------------------------------------------------
# per-format chunk readers (all bounded-memory generators)
# ---------------------------------------------------------------------------
def _read_csv_chunks(path: Path, chunksize: int, encoding: Optional[str] = None,
                     delimiter: Optional[str] = None) -> Iterator[pd.DataFrame]:
    enc = encoding or detect_encoding(path)
    delim = delimiter or sniff_delimiter(path, enc)
    # on_bad_lines=callable: malformed rows are collected and skipped, never raised.
    try:
        reader = pd.read_csv(path, chunksize=chunksize, encoding=enc, sep=delim,
                             engine="python",
                             on_bad_lines=_bad_line_collector(str(path)))
    except pd.errors.EmptyDataError:
        return  # 0-byte or headerless file: zero chunks, not an exception
    except StopIteration:
        return
    try:
        for chunk in reader:
            # A header-only or fully-blank file yields an empty frame; drop
            # all-NA rows so downstream sees zero data rows, not one null row.
            if not chunk.empty:
                chunk = chunk.dropna(how="all")
            if chunk.empty:
                continue
            yield chunk
    except pd.errors.EmptyDataError:
        return
    except StopIteration:
        return  # header-only file under some pandas versions


def _read_xlsx_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    # openpyxl read-only streaming via manual batching: bounded memory.
    try:
        import openpyxl

        wb = openpyxl.load_workbook(path, read_only=True, data_only=True)
        try:
            ws = wb.active
            if ws is None or ws.max_row is None or ws.max_row < 2:
                return
            rows = ws.iter_rows(values_only=True)
            header = None
            batch: List = []
            for row in rows:
                if header is None:
                    header = [str(c) if c is not None else f"col_{i}" for i, c in enumerate(row)]
                    continue
                if row is None or all(c is None for c in row):
                    continue
                batch.append(row)
                if len(batch) >= chunksize:
                    yield pd.DataFrame(batch, columns=header)
                    batch = []
            if batch:
                yield pd.DataFrame(batch, columns=header)
        finally:
            try:
                wb.close()
            except Exception:
                pass
    except Exception:
        # fallback: pandas normal read (small files); empty sheet -> no chunks
        try:
            df = pd.read_excel(path, engine="openpyxl")
        except Exception:
            return
        if df.empty:
            return
        for i in range(0, len(df), chunksize):
            yield df.iloc[i: i + chunksize]


def _read_xls_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    """Legacy BIFF ``.xls`` via xlrd. openpyxl is never attempted here: it only
    reads OOXML (ZIP) workbooks and fails on the OLE2 container."""
    try:
        import xlrd
    except ImportError as exc:
        raise ValueError("reading .xls requires the 'xlrd' package") from exc
    try:
        book = xlrd.open_workbook(str(path), on_demand=True)
    except Exception:
        return
    try:
        if book.nsheets == 0:
            return
        sheet = book.sheet_by_index(0)
        if sheet.nrows == 0:
            return
        header = [str(sheet.cell_value(0, c)) if sheet.cell_value(0, c) != "" else f"col_{c}"
                  for c in range(sheet.ncols)]
        batch: List = []
        for r in range(1, sheet.nrows):
            values = [sheet.cell_value(r, c) for c in range(sheet.ncols)]
            if all(v == "" or v is None for v in values):
                continue
            batch.append(values)
            if len(batch) >= chunksize:
                yield pd.DataFrame(batch, columns=header)
                batch = []
        if batch:
            yield pd.DataFrame(batch, columns=header)
    finally:
        try:
            book.release_resources()
        except Exception:
            pass


def _read_json_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    import json

    enc = detect_encoding(path)
    # Fast path: a top-level JSON array/object document parses whole (small docs).
    # Guard by size so a 500 MB array does not get materialised here: stream it
    # as JSONL instead.
    try:
        if path.stat().st_size <= 32 * 1024 * 1024:
            df = pd.read_json(path, encoding=enc)
            if df.empty:
                return
            for i in range(0, len(df), chunksize):
                yield df.iloc[i: i + chunksize]
            return
    except ValueError:
        pass  # not a plain JSON document -> fall through to JSON-lines streaming
    except Exception:
        pass
    # Streaming JSON-lines: one object per line, bounded memory. Malformed
    # lines are collected (never raised) for dead-letter persistence.
    batch: List = []
    try:
        fh = open(path, "r", encoding=enc)
    except OSError:
        return
    with fh:
        for lineno, line in enumerate(fh, start=1):
            line = line.strip()
            if not line:
                continue
            # A top-level array spread over lines ("[", "{...},", "]") is not
            # JSONL; skip structural lines, parse element lines tolerantly.
            if line in ("[", "]"):
                continue
            candidate = line.rstrip(",")
            try:
                batch.append(json.loads(candidate))
            except Exception:
                _record_malformed(f"{path}#L{lineno}", line, reason="invalid_json")
                continue
            if len(batch) >= chunksize:
                frame = pd.DataFrame(batch)
                batch = []
                if not frame.empty:
                    yield frame
    if batch:
        frame = pd.DataFrame(batch)
        if not frame.empty:
            yield frame


def _read_xml_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    try:
        df = pd.read_xml(path)
    except Exception:
        import xml.etree.ElementTree as ET

        try:
            # iterparse would be the bounded-memory option; ElementTree.parse
            # materialises the tree — acceptable fallback for small XML files.
            tree = ET.parse(str(path))
        except ET.ParseError as exc:
            _record_malformed(str(path), str(exc), reason="xml_parse_error")
            return
        except Exception:
            return
        root = tree.getroot()
        rows = []
        for child in root:
            if len(child):
                rows.append({e.tag: e.text for e in child})
            elif child.text and child.text.strip():
                rows.append({"value": child.text.strip()})
        df = pd.DataFrame(rows)
    if df.empty:
        return
    for i in range(0, len(df), chunksize):
        yield df.iloc[i: i + chunksize]


def _read_parquet_chunks(path: Path, chunksize: int,
                         columns: Optional[List[str]] = None) -> Iterator[pd.DataFrame]:
    import pyarrow.parquet as pq

    try:
        pf = pq.ParquetFile(str(path))
    except Exception:
        return
    use_cols = list(columns) if columns else None
    if use_cols:
        try:
            available = set(pf.schema_arrow.names)
            use_cols = [c for c in use_cols if c in available] or None
        except Exception:
            pass
    try:
        for batch in pf.iter_batches(batch_size=chunksize, columns=use_cols):
            frame = batch.to_pandas()
            if frame.empty:
                continue
            yield frame
    except Exception:
        return


def iter_chunks(path: str | Path, chunksize: Optional[int] = None,
                columns: Optional[List[str]] = None,
                encoding: Optional[str] = None,
                delimiter: Optional[str] = None) -> Iterator[pd.DataFrame]:
    """Yield DataFrame chunks of a CSV/XLSX/XLS/JSON/JSONL/XML/Parquet/ZIP file.

    A ZIP is expanded into a per-call temporary directory that is removed once
    the inner file has been consumed; previously the extraction was written to a
    shared ``<upload-dir>/_zip_extract`` and never cleaned up, so every
    validation and every import left a copy of the uploaded data on disk.

    ``columns`` projects Parquet columns (ignored for other formats).
    ``encoding``/``delimiter`` override detection for CSV. The signature stays
    backward compatible: ``iter_chunks(path)`` and ``iter_chunks(path, n)``
    behave exactly as before.
    """
    p = Path(path)
    cs = chunksize or settings.chunk_rows
    suffix = p.suffix.lower()
    if suffix == ".zip":
        import shutil
        import tempfile
        import zipfile

        with zipfile.ZipFile(p, "r") as zf:
            names = [n for n in zf.namelist() if n.lower().endswith(
                (".csv", ".xlsx", ".xls", ".json", ".jsonl", ".ndjson", ".parquet"))]
            if not names:
                raise ValueError("ZIP contains no supported data file")
            tmpdir = tempfile.mkdtemp(prefix="zip_extract_")
            try:
                zf.extract(names[0], path=tmpdir)
                inner = Path(tmpdir) / names[0]
                yield from iter_chunks(inner, cs, columns=columns, encoding=encoding,
                                       delimiter=delimiter)
            finally:
                shutil.rmtree(tmpdir, ignore_errors=True)
            return
    if suffix == ".csv":
        yield from _read_csv_chunks(p, cs, encoding=encoding, delimiter=delimiter)
    elif suffix == ".xlsx":
        yield from _read_xlsx_chunks(p, cs)
    elif suffix == ".xls":
        yield from _read_xls_chunks(p, cs)
    elif suffix in (".json", ".jsonl", ".ndjson"):
        yield from _read_json_chunks(p, cs)
    elif suffix == ".xml":
        yield from _read_xml_chunks(p, cs)
    elif suffix == ".parquet":
        yield from _read_parquet_chunks(p, cs, columns=columns)
    else:
        raise ValueError(f"Unsupported file type: {suffix}")


def read_full(path: str | Path, limit_rows: int = 200000,
              columns: Optional[List[str]] = None) -> pd.DataFrame:
    frames = []
    total = 0
    for chunk in iter_chunks(path, columns=columns):
        frames.append(chunk)
        total += len(chunk)
        if total >= limit_rows:
            break
    if not frames:
        return pd.DataFrame()
    return pd.concat(frames, ignore_index=True).head(limit_rows)
