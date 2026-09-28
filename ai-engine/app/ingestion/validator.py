"""File validation: size, extension, MIME, checksum, malformed handling."""
from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Any, Dict, List

from app.core.config import settings

ALLOWED_EXTS = {".csv", ".xlsx", ".xls", ".json", ".xml", ".parquet", ".zip"}


def sha256_file(path: Path, block_size: int = 65536) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for block in iter(lambda: fh.read(block_size), b""):
            h.update(block)
    return h.hexdigest()


def detect_mime(path: Path) -> str:
    try:
        import filetype  # type: ignore

        kind = filetype.guess(str(path))
        if kind:
            return kind.mime
    except Exception:
        pass
    import mimetypes

    mime, _ = mimetypes.guess_type(str(path))
    return mime or "application/octet-stream"


def validate_file(path: str | Path) -> Dict[str, Any]:
    """Validate a file on disk. Returns dict with ok/errors/warnings/meta."""
    p = Path(path)
    errors: List[str] = []
    warnings: List[str] = []
    if not p.exists():
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
    mime = detect_mime(p)
    checksum = sha256_file(p) if p.exists() and size > 0 else ""
    # malformed sniff: try reading first rows
    row_errors: List[Dict[str, Any]] = []
    if not errors and ext in (".csv", ".xlsx", ".xls", ".json", ".xml", ".parquet", ".zip"):
        try:
            from app.ingestion.reader import iter_chunks

            n = 0
            for chunk in iter_chunks(p, chunksize=1000):
                n += len(chunk)
                if n >= 1000:
                    break
            if n == 0:
                warnings.append("No data rows detected")
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
