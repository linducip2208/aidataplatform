"""Enterprise data-quality package: rule engine, profiles, history, PII.

Deterministic, pandas-based, no external services. The legacy four-check
profiler in ``app.ingestion.quality`` is untouched; this package adds
configurable per-column rules, named profiles, run history/trend and PII
detection/masking on top of it.
"""
from __future__ import annotations
