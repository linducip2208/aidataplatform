"""File validation: size, extension, MIME, checksum, malformed handling."""
from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Any, Dict, List

from app.core.config import settings

ALLOWED_EXTS = {".csv", ".xlsx", ".xls", ".json", ".jsonl", ".ndjson", ".xml", ".parquet", ".zip"}

# Content signatures that can legitimately back one of ALLOWED_EXTS: the zip
# container family (a bare .zip, and .xlsx/.docx which are zips) and the OLE2
# legacy office formats. Only a *positive* binary sniff is compared against
# this -- csv, json, xml and parquet have no magic bytes, so they report ""
# and are never affected. Extension-only validation let a PNG or an ELF
# binary renamed to .csv through as a valid dataset upload.
_DATA_MIME_PREFIXES = (
    "application/zip", "application/x-zip-compressed",
    "application/vnd.ms-", "application/vnd.openxmlformats-officedocument",
    "application/x-ole-storage", "application/x-ole-", "application/msword",
)


def sha256_file(path: Path, block_size: int = 65536) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for block in iter(lambda: fh.read(block_size), b""):
            h.update(block)
    return h.hexdigest()


def _sniff(path: Path) -> str:
    """Content-magic MIME, or "" when the file has no binary signature.

    Kept separate from ``detect_mime`` because the ``mimetypes`` fallback maps
    .csv to ``application/vnd.ms-excel`` on Windows, so only a real ``filetype``
    hit is trustworthy enough to validate against.
    """
    try:
        import filetype  # type: ignore

        kind = filetype.guess(str(path))
        return kind.mime if kind else ""
    except Exception:
        return ""


def detect_mime(path: Path) -> str:
    sniffed = _sniff(path)
    if sniffed:
        return sniffed
    import mimetypes

    mime, _ = mimetypes.guess_type(str(path))
    return mime or "application/octet-stream"


def validate_file(path: str | Path) -> Dict[str, Any]:
    """Validate a file on disk. Returns dict with ok/errors/warnings/meta."""
    p = Path(path)
    errors: List[str] = []
    warnings: List[str] = []
    if not p.is_file():
        return {"ok": False, "errors": ["File not found"], "warnings": [], "meta": {}}
    size = p.stat().st_size
    if size == 0:
        errors.append("File is empty (0 bytes)")
    max_bytes = settings.upload_max_mb * 1024 * 1024
    if size > max_bytes:
        errors.append(f"File too large: {size} bytes > {max_bytes} bytes limit")
    ext = p.suffix.lower()
    if ext not in ALLOWED_EXTS:
        errors.append(f"Unsupported extension '{ext}'. Allowed: {sorted(ALLOWED_EXTS)}")
    if size > 0:
        sniffed = _sniff(p)
        if sniffed and not sniffed.startswith(_DATA_MIME_PREFIXES):
            errors.append(f"File content is {sniffed}, not a supported data file")
    mime = detect_mime(p)
    # Only hash a file that passed every check above. The checksum is what the
    # raw_upload row is deduplicated on, and a rejected upload never becomes a
    # row, so hashing an already-rejected 200 MB file just re-read 200 MB off
    # disk to compute a value nothing will ever look at. The unreadable case
    # is reported instead of raising, which used to surface as a 500 out of
    # /imports/upload rather than a validation error the UI can show.
    checksum = ""
    if not errors and size > 0:
        try:
            checksum = sha256_file(p)
        except OSError as exc:
            errors.append(f"File is unreadable: {exc}")
    # malformed sniff: stream the first rows with malformed-row tolerance.
    # A single bad line must never fail validation: the reader collects bad
    # lines into a buffer (see reader.drain_malformed_rows) and skips them, so
    # they are reported here as row_errors / warnings and later persisted as
    # dead-letter records by the ETL. Only an unreadable file is an error.
    row_errors: List[Dict[str, Any]] = []
    if not errors and ext in (".csv", ".xlsx", ".xls", ".json", ".jsonl", ".ndjson",
                              ".xml", ".parquet", ".zip"):
        try:
            from app.ingestion.reader import drain_malformed_rows, iter_chunks

            n = 0
            for chunk in iter_chunks(p, chunksize=1000):
                n += len(chunk)
                if n >= 1000:
                    break
            for bad in drain_malformed_rows():
                row_errors.append({"row": bad.get("source", 0), "error": bad.get("reason", "bad_line"),
                                   "line": str(bad.get("line", ""))[:500]})
            if n == 0 and not row_errors:
                # Empty file (0 data rows) or empty sheet: valid container, no
                # rows. A warning, not an error — the commit loads zero rows
                # and reports the job accordingly.
                warnings.append("No data rows detected (empty file or empty sheet)")
            elif row_errors:
                warnings.append(f"{len(row_errors)} malformed row(s) will be skipped "
                                f"(see row_errors / dead-letter records)")
        except Exception as exc:  # malformed
            errors.append(f"Malformed file, cannot parse: {exc}")
            row_errors.append({"row": 0, "error": str(exc)})
    return {
        "ok": len(errors) == 0,
        "errors": errors,
        "warnings": warnings,
        "row_errors": row_errors,
        "meta": {
            "filename": p.name,
            "size_bytes": size,
            "mime": mime,
            "checksum_sha256": checksum,
            "extension": ext,
        },
    }
