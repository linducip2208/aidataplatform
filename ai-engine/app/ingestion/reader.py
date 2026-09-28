"""Chunk/streaming reader for CSV, XLSX, JSON, XML, Parquet, ZIP."""
from __future__ import annotations

from pathlib import Path
from typing import Dict, Iterator, Optional

import pandas as pd

from app.core.config import settings


def detect_encoding(path: Path) -> str:
    for enc in ("utf-8-sig", "utf-8", "cp1252", "iso-8859-1"):
        try:
            with open(path, "r", encoding=enc) as fh:
                fh.read(4096)
            return enc
        except Exception:
            continue
    return "utf-8"


def _read_csv_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    enc = detect_encoding(path)
    for chunk in pd.read_csv(path, chunksize=chunksize, encoding=enc, low_memory=False):
        yield chunk


def _read_xlsx_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    # openpyxl read-only streaming via pandas still loads; iterate manually in batches
    enc_opts: Dict = {}
    try:
        import openpyxl

        wb = openpyxl.load_workbook(path, read_only=True, data_only=True)
        ws = wb.active
        rows = ws.iter_rows(values_only=True)
        header = None
        batch = []
        for row in rows:
            if header is None:
                header = [str(c) if c is not None else f"col_{i}" for i, c in enumerate(row)]
                continue
            batch.append(row)
            if len(batch) >= chunksize:
                yield pd.DataFrame(batch, columns=header)
                batch = []
        if batch:
            yield pd.DataFrame(batch, columns=header)
        wb.close()
    except Exception:
        # fallback: pandas normal read (small files)
        df = pd.read_excel(path, engine="openpyxl")
        for i in range(0, len(df), chunksize):
            yield df.iloc[i : i + chunksize]


def _read_json_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    import json

    try:
        df = pd.read_json(path)
        for i in range(0, len(df), chunksize):
            yield df.iloc[i : i + chunksize]
        return
    except ValueError:
        pass
    batch = []
    with open(path, "r", encoding=detect_encoding(path)) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                batch.append(json.loads(line))
            except Exception:
                continue
            if len(batch) >= chunksize:
                yield pd.DataFrame(batch)
                batch = []
    if batch:
        yield pd.DataFrame(batch)


def _read_xml_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    try:
        df = pd.read_xml(path)
    except Exception:
        import xml.etree.ElementTree as ET

        tree = ET.parse(str(path))
        root = tree.getroot()
        rows = []
        for child in root:
            rows.append({e.tag: e.text for e in child})
        df = pd.DataFrame(rows)
    for i in range(0, len(df), chunksize):
        yield df.iloc[i : i + chunksize]


def _read_parquet_chunks(path: Path, chunksize: int) -> Iterator[pd.DataFrame]:
    import pyarrow.parquet as pq

    pf = pq.ParquetFile(str(path))
    for batch in pf.iter_batches(batch_size=chunksize):
        yield batch.to_pandas()


def iter_chunks(path: str | Path, chunksize: Optional[int] = None) -> Iterator[pd.DataFrame]:
    """Yield DataFrame chunks without loading a huge file fully into RAM."""
    p = Path(path)
    cs = chunksize or settings.chunk_rows
    suffix = p.suffix.lower()
    if suffix == ".zip":
        import zipfile

        with zipfile.ZipFile(p, "r") as zf:
            names = [n for n in zf.namelist() if n.lower().endswith((".csv", ".xlsx", ".xls", ".json", ".parquet"))]
            if not names:
                raise ValueError("ZIP contains no supported data file")
            inner = zf.extract(names[0], path=str(p.parent / "_zip_extract"))
            yield from iter_chunks(inner, cs)
            return
    if suffix == ".csv":
        yield from _read_csv_chunks(p, cs)
    elif suffix in (".xlsx", ".xls"):
        yield from _read_xlsx_chunks(p, cs)
    elif suffix == ".json":
        yield from _read_json_chunks(p, cs)
    elif suffix == ".xml":
        yield from _read_xml_chunks(p, cs)
    elif suffix == ".parquet":
        yield from _read_parquet_chunks(p, cs)
    else:
        raise ValueError(f"Unsupported file type: {suffix}")


def read_full(path: str | Path, limit_rows: int = 200000) -> pd.DataFrame:
    frames = []
    total = 0
    for chunk in iter_chunks(path):
        frames.append(chunk)
        total += len(chunk)
        if total >= limit_rows:
            break
    if not frames:
        return pd.DataFrame()
    import pandas as pd  # noqa: F811

    return pd.concat(frames, ignore_index=True).head(limit_rows)
