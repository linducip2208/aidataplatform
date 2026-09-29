"""PII detection + masking. Standard library only (``re``), no external deps.

Detects ``email``, ``phone``, ``credit_card`` and ``national_id`` out of the
box; callers add ``extra_patterns`` as ``{kind: regex}``. Masking strategies:

* ``mask_email`` -- keep the domain, mask the local part (``j***@x.com``).
* ``mask_partial`` -- keep the last 4 characters, mask the rest (``***5678``).
* ``mask_full`` -- replace every character with ``*`` (length preserved).

``detect()`` scans stringified cell values; ``mask_dataframe()`` returns
``(masked_frame, findings)`` and never mutates the input frame.
"""
from __future__ import annotations

import re
from typing import Any, Dict, List, Sequence

import pandas as pd

PATTERNS: Dict[str, str] = {
    "email": r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}",
    "phone": r"(?:\+?62[\s-]?|0)(?:\d[\s-]?){8,12}\d",
    "credit_card": r"\b(?:\d[ -]?){13,19}\b",
    "national_id": r"\b\d{16}\b",
}

_COMPILED: Dict[str, re.Pattern] = {kind: re.compile(rx) for kind, rx in PATTERNS.items()}

MAX_SAMPLES = 5


def _compile_extra(extra: Dict[str, str] | None) -> Dict[str, re.Pattern]:
    compiled: Dict[str, re.Pattern] = {}
    for kind, pattern in (extra or {}).items():
        if not isinstance(kind, str) or not kind.strip():
            raise ValueError("invalid extra_patterns: kind must be a non-empty string")
        if not isinstance(pattern, str) or not pattern:
            raise ValueError(f"invalid extra_patterns: pattern for {kind!r} must be a string")
        try:
            compiled[kind.strip()] = re.compile(pattern)
        except re.error as exc:
            raise ValueError(f"invalid extra_patterns: bad regex for {kind!r}: {exc}") from exc
    return compiled


def _luhn_ok(digits: str) -> bool:
    total = 0
    for i, ch in enumerate(reversed(digits)):
        digit = ord(ch) - 48
        if i % 2 == 1:
            digit *= 2
            if digit > 9:
                digit -= 9
        total += digit
    return total % 10 == 0


def _credible(kind: str, match: str) -> bool:
    """Drop the matches that are clearly not PII (deterministic filters)."""
    digits = re.sub(r"\D", "", match)
    if kind == "credit_card":
        if not 13 <= len(digits) <= 19:
            return False
        if len(set(digits)) == 1:  # 0000... is a placeholder, not a card
            return False
        return _luhn_ok(digits)
    if kind == "national_id":
        return len(digits) == 16 and len(set(digits)) > 1
    if kind == "phone":
        return len(set(digits)) > 1  # 0000... is a placeholder, not a number
    return True


def detect(df: pd.DataFrame, columns: Sequence[str] | None = None,
           extra_patterns: Dict[str, str] | None = None,
           kinds: Sequence[str] | None = None) -> List[Dict[str, Any]]:
    """Scan columns for PII. Returns findings ``[{column, kind, count, samples}]``.

    ``samples`` holds at most 5 matched strings. A column with no match
    produces no finding. Deterministic: column order follows the frame.
    """
    compiled = dict(_COMPILED)
    compiled.update(_compile_extra(extra_patterns))
    wanted = list(kinds) if kinds is not None else list(compiled)
    for kind in wanted:
        if kind not in compiled:
            raise ValueError(f"unknown PII kind {kind!r}; "
                             f"expected one of: {', '.join(sorted(compiled))}")
    targets = list(columns) if columns is not None else [str(c) for c in df.columns]
    findings: List[Dict[str, Any]] = []
    for column in targets:
        if column not in df.columns:
            continue
        values = df[column].dropna().astype(str)
        for kind in wanted:
            rx = compiled[kind]
            matched = [v for v in values.tolist() if rx.search(v) and _credible(kind, v)]
            if matched:
                findings.append({
                    "column": column,
                    "kind": kind,
                    "count": len(matched),
                    "samples": matched[:MAX_SAMPLES],
                })
    return findings


def mask_email(value: Any) -> Any:
    """Mask an email's local part, keep the domain (``j***@example.com``)."""
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return value
    text = str(value)
    if "@" not in text:
        return mask_partial(text)
    local, _, domain = text.partition("@")
    if not local:
        return "***@" + domain
    return local[0] + "***@" + domain


def mask_partial(value: Any, visible_last: int = 4) -> Any:
    """Keep the last ``visible_last`` characters, mask the rest with ``*``."""
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return value
    text = str(value)
    if len(text) <= visible_last:
        return "*" * len(text)
    return "*" * (len(text) - visible_last) + text[-visible_last:]


def mask_full(value: Any) -> Any:
    """Replace every character with ``*`` (length preserved)."""
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return value
    return "*" * len(str(value))


_STRATEGIES = {"email": mask_email, "partial": mask_partial, "full": mask_full}


def mask_dataframe(df: pd.DataFrame, columns: Sequence[str] | None = None,
                   strategy: str = "email",
                   per_column: Dict[str, str] | None = None,
                   extra_patterns: Dict[str, str] | None = None,
                   kinds: Sequence[str] | None = None) -> tuple:
    """Return ``(masked_frame, findings)``; the input frame is never mutated.

    ``strategy`` is the default (``email`` means "email-aware": email-looking
    cells use :func:`mask_email`, everything else :func:`mask_partial`).
    ``per_column`` overrides the strategy per column name.
    Only columns with a PII finding are masked.
    """
    if strategy not in ("email", "partial", "full"):
        raise ValueError(f"unknown masking strategy {strategy!r}; "
                         "expected one of: email, partial, full")
    for col, strat in (per_column or {}).items():
        if strat not in ("email", "partial", "full"):
            raise ValueError(f"unknown masking strategy {strat!r} for column {col!r}")
    findings = detect(df, columns=columns, extra_patterns=extra_patterns, kinds=kinds)
    masked = df.copy(deep=True)
    per_column = per_column or {}
    for finding in findings:
        column = finding["column"]
        strat = per_column.get(column, strategy)
        if strat == "email":
            masked[column] = masked[column].map(
                lambda v: v if pd.isna(v) else (
                    mask_email(v) if "@" in str(v) else mask_partial(v)))
        else:
            fn = _STRATEGIES[strat]
            masked[column] = masked[column].map(lambda v: v if pd.isna(v) else fn(v))
    return masked, findings
