"""Deterministic rule engine: validate + evaluate data-quality rules on DataFrames.

Rule shape (dict)::

    {"id": "email_present", "column": "email", "type": "required",
     "params": {}, "severity": "error"}

``type`` is one of :data:`RULE_TYPES`, ``severity`` is ``error`` or ``warn``.
``column`` may be ``None`` only for dataset-level rules (``duplicate`` without
``params.columns``, ``completeness`` without a column, ``schema_drift``).

``evaluate(df, rules)`` returns::

    {"results": [per-rule {...}], "score": float, "column_scores": {...},
     "verdict": "pass" | "warn" | "fail"}

Per-rule result::

    {"id": ..., "column": ..., "type": ..., "severity": ...,
     "passed": bool, "failure_count": int, "sample_failures": [...] (<=10)}

``score`` is the plain mean of per-rule pass rates, each
``1 - failure_count / max(1, rows)`` in ``0.0-1.0`` rounded to 4 decimals;
``schema_drift`` uses ``1.0``/``0.0``. ``verdict`` is ``fail`` when any
``error`` rule failed, ``warn`` when only ``warn`` rules failed, else ``pass``.

Chunk-friendly: :class:`ChunkAccumulator` streams chunk results and
:meth:`ChunkAccumulator.finalize` returns the exact same shape as
``evaluate()``. Stateless rules merge additively; stateful ones (``unique``,
``duplicate``, ``consistency``, ``anomaly_ref``) retain the values needed for
an exact global computation, which is documented per rule below.
"""
from __future__ import annotations

import math
import re
from typing import Any, Dict, List, Sequence

import numpy as np
import pandas as pd

RULE_TYPES = frozenset(
    {
        "required",
        "nullable",
        "unique",
        "duplicate",
        "regex",
        "range",
        "enum",
        "datatype",
        "referential",
        "freshness",
        "completeness",
        "consistency",
        "validity",
        "schema_drift",
        "anomaly_ref",
    }
)

SEVERITIES = frozenset({"error", "warn"})

DATATYPE_KINDS = frozenset({"int", "float", "number", "string", "bool", "date", "datetime"})

VALIDITY_CHECKS = frozenset({"no_negative", "parse_date", "parse_number"})

MAX_SAMPLES = 10

# Rules whose outcome depends on the whole frame (not mergeable by counts).
_STATEFUL_TYPES = frozenset({"unique", "duplicate", "consistency", "anomaly_ref"})


class RuleValidationError(ValueError):
    """A rule dict is malformed; message names the field and the reason."""


def _fail(field: str, reason: str) -> RuleValidationError:
    return RuleValidationError(f"invalid rule: {field}: {reason}")


def _as_dict(rule: Any) -> Dict[str, Any]:
    if not isinstance(rule, dict):
        raise _fail("rule", f"expected a dict, got {type(rule).__name__}")
    return rule


def _require_number(params: Dict[str, Any], key: str, rule_id: str) -> float:
    value = params.get(key)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise _fail(f"params.{key}", f"rule {rule_id!r} needs a numeric {key!r}")
    if not math.isfinite(float(value)):
        raise _fail(f"params.{key}", f"rule {rule_id!r} needs a finite {key!r}")
    return float(value)


def _require_ratio(params: Dict[str, Any], key: str, rule_id: str, default: float) -> float:
    value = params.get(key, default)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise _fail(f"params.{key}", f"rule {rule_id!r} needs {key!r} in 0.0-1.0")
    value = float(value)
    if not 0.0 <= value <= 1.0:
        raise _fail(f"params.{key}", f"rule {rule_id!r} needs {key!r} in 0.0-1.0")
    return value


def validate_rule(rule: Dict[str, Any]) -> Dict[str, Any]:
    """Validate one rule dict and return its normalised form.

    Normalisation fills defaults (``params`` -> ``{}``, ``severity`` ->
    ``"error"``) and type-checks every ``params`` entry so ``evaluate()``
    never raises on a validated rule. Raises :class:`RuleValidationError`.
    """
    raw = _as_dict(rule)
    rule_id = raw.get("id", raw.get("name", ""))
    if not isinstance(rule_id, str) or not rule_id.strip():
        raise _fail("id", "a non-empty string id is required")
    rule_id = rule_id.strip()

    column = raw.get("column")
    if column is not None:
        if not isinstance(column, str) or not column.strip():
            raise _fail("column", f"rule {rule_id!r} needs a column name or null")
        column = column.strip()

    rule_type = raw.get("type", raw.get("rule_type", ""))
    if rule_type not in RULE_TYPES:
        raise _fail("type", f"rule {rule_id!r} has unknown type {rule_type!r}; "
                            f"expected one of: {', '.join(sorted(RULE_TYPES))}")

    severity = raw.get("severity", "error")
    if severity not in SEVERITIES:
        raise _fail("severity", f"rule {rule_id!r} needs severity 'error' or 'warn'")

    params = raw.get("params", {})
    if params is None:
        params = {}
    if not isinstance(params, dict):
        raise _fail("params", f"rule {rule_id!r} needs params as an object")
    params = dict(params)

    _validate_params(rule_id, rule_type, column, params)

    if column is None and rule_type not in ("completeness", "duplicate", "schema_drift"):
        raise _fail("column", f"rule {rule_id!r} of type {rule_type!r} needs a column")

    return {"id": rule_id, "column": column, "type": rule_type,
            "params": params, "severity": severity}


def _validate_params(rule_id: str, rule_type: str,
                     column: str | None, params: Dict[str, Any]) -> None:
    if rule_type == "regex":
        pattern = params.get("pattern")
        if not isinstance(pattern, str) or not pattern:
            raise _fail("params.pattern", f"rule {rule_id!r} needs a regex pattern string")
        try:
            re.compile(pattern)
        except re.error as exc:
            raise _fail("params.pattern", f"rule {rule_id!r} has a bad pattern: {exc}") from exc
    elif rule_type == "range":
        has_min = "min" in params and params["min"] is not None
        has_max = "max" in params and params["max"] is not None
        if not has_min and not has_max:
            raise _fail("params", f"rule {rule_id!r} needs at least one of min/max")
        lo = _require_number(params, "min", rule_id) if has_min else None
        hi = _require_number(params, "max", rule_id) if has_max else None
        if lo is not None and hi is not None and lo > hi:
            raise _fail("params", f"rule {rule_id!r} needs min <= max")
        for flag in ("include_min", "include_max"):
            if flag in params and not isinstance(params[flag], bool):
                raise _fail(f"params.{flag}", f"rule {rule_id!r} needs {flag!r} as a boolean")
    elif rule_type == "enum":
        allowed = params.get("allowed")
        if not isinstance(allowed, list) or not allowed:
            raise _fail("params.allowed", f"rule {rule_id!r} needs a non-empty allowed list")
    elif rule_type == "datatype":
        if params.get("dtype") not in DATATYPE_KINDS:
            raise _fail("params.dtype", f"rule {rule_id!r} needs dtype one of: "
                                       f"{', '.join(sorted(DATATYPE_KINDS))}")
    elif rule_type == "referential":
        ref = params.get("allowed_values", params.get("ref_values"))
        if not isinstance(ref, list) or not ref:
            raise _fail("params.allowed_values",
                        f"rule {rule_id!r} needs a non-empty allowed_values list")
    elif rule_type == "freshness":
        days = params.get("max_age_days")
        if isinstance(days, bool) or not isinstance(days, (int, float)) or float(days) <= 0:
            raise _fail("params.max_age_days",
                        f"rule {rule_id!r} needs max_age_days as a positive number")
        ref = params.get("reference")
        if ref is not None:
            try:
                pd.to_datetime(ref, utc=True)
            except Exception as exc:
                raise _fail("params.reference",
                            f"rule {rule_id!r} has an unparseable reference date") from exc
    elif rule_type == "nullable":
        _require_ratio(params, "max_null_ratio", rule_id, 1.0)
    elif rule_type == "completeness":
        _require_ratio(params, "min_ratio", rule_id, 0.9)
    elif rule_type == "consistency":
        _require_ratio(params, "max_outlier_ratio", rule_id, 0.05)
    elif rule_type == "anomaly_ref":
        sens = params.get("sensitivity", 2.5)
        if isinstance(sens, bool) or not isinstance(sens, (int, float)):
            raise _fail("params.sensitivity",
                        f"rule {rule_id!r} needs sensitivity as a number")
        if not 0.5 <= float(sens) <= 6.0:
            raise _fail("params.sensitivity",
                        f"rule {rule_id!r} needs sensitivity in 0.5-6.0")
        _require_ratio(params, "max_anomaly_ratio", rule_id, 0.05)
    elif rule_type == "validity":
        if params.get("check") not in VALIDITY_CHECKS:
            raise _fail("params.check", f"rule {rule_id!r} needs check one of: "
                                       f"{', '.join(sorted(VALIDITY_CHECKS))}")
    elif rule_type == "schema_drift":
        expected = params.get("expected_columns")
        if not isinstance(expected, list) or not expected or \
                not all(isinstance(c, str) and c for c in expected):
            raise _fail("params.expected_columns",
                        f"rule {rule_id!r} needs a non-empty list of column names")
        if column is not None:
            raise _fail("column", f"rule {rule_id!r} (schema_drift) is dataset-level; "
                                  "column must be null")
        if "allow_extra" in params and not isinstance(params["allow_extra"], bool):
            raise _fail("params.allow_extra",
                        f"rule {rule_id!r} needs allow_extra as a boolean")
    elif rule_type == "duplicate":
        cols = params.get("columns")
        if cols is not None and (not isinstance(cols, list) or not cols or
                                 not all(isinstance(c, str) and c for c in cols)):
            raise _fail("params.columns",
                        f"rule {rule_id!r} needs columns as a non-empty list or null")


# --------------------------------------------------------------------------
# value helpers
# --------------------------------------------------------------------------

def _json_safe(value: Any) -> Any:
    if value is None:
        return None
    if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
        return None
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, (np.floating,)):
        return _json_safe(float(value))
    if isinstance(value, (np.bool_,)):
        return bool(value)
    if isinstance(value, (pd.Timestamp,)):
        return value.isoformat()
    try:
        import datetime as _dt
        if isinstance(value, (_dt.datetime, _dt.date)):
            return value.isoformat()
    except Exception:
        pass
    if isinstance(value, str) and len(value) > 256:
        return value[:256]
    return value


def _is_missing(series: pd.Series, treat_empty_string: bool = True) -> pd.Series:
    mask = series.isna()
    # pandas may infer StringDtype instead of object for text columns, so the
    # check is on the dtype kind, not on ``== object``.
    if treat_empty_string and pd.api.types.is_string_dtype(series):
        mask = mask | (series.astype(str).str.strip() == "")
    return mask


def _non_null_str(series: pd.Series) -> pd.Series:
    return series[~series.isna()].astype(str)


def _str_set(values: Sequence[Any]) -> set:
    return {str(v) for v in values}


def _coerce_number(series: pd.Series) -> pd.Series:
    return pd.to_numeric(series, errors="coerce")


def _matches_dtype(value: Any, dtype: str) -> bool:
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return True  # nulls are not datatype failures; use required/nullable for those
    text = str(value).strip()
    if text == "":
        return True
    if dtype in ("float", "number"):
        try:
            float(text)
            return True
        except ValueError:
            return False
    if dtype == "int":
        try:
            num = float(text)
        except ValueError:
            return False
        return num.is_integer()
    if dtype == "string":
        return True
    if dtype == "bool":
        return text.lower() in {"true", "false", "1", "0", "yes", "no", "y", "n", "t", "f"}
    try:
        parsed = pd.to_datetime(text, errors="coerce")
    except Exception:
        return False
    return not pd.isna(parsed)


def _iqr_outliers(values: pd.Series) -> pd.Series:
    """Boolean mask of 1.5xIQR outliers (same formula as ingestion.quality)."""
    clean = _coerce_number(values).dropna()
    if len(clean) < 8:
        return pd.Series(False, index=values.index)
    q1, q3 = clean.quantile(0.25), clean.quantile(0.75)
    iqr = q3 - q1
    if iqr == 0 or pd.isna(iqr):
        return pd.Series(False, index=values.index)
    numeric = _coerce_number(values)
    return (numeric < q1 - 1.5 * iqr) | (numeric > q3 + 1.5 * iqr)


# --------------------------------------------------------------------------
# per-rule evaluation (single frame)
# --------------------------------------------------------------------------

def _fail_result(rule: Dict[str, Any], count: int, samples: List[Any]) -> Dict[str, Any]:
    return {
        "id": rule["id"],
        "column": rule["column"],
        "type": rule["type"],
        "severity": rule["severity"],
        "passed": count == 0,
        "failure_count": int(count),
        "sample_failures": [_json_safe(v) for v in list(samples)[:MAX_SAMPLES]],
    }


def _threshold_result(rule: Dict[str, Any], count: int, samples: List[Any],
                      passed: bool) -> Dict[str, Any]:
    res = _fail_result(rule, count, samples)
    res["passed"] = bool(passed)
    return res


def _missing_column_result(rule: Dict[str, Any], n: int) -> Dict[str, Any]:
    return _fail_result(rule, n, [f"missing column: {rule['column']}" if rule["column"]
                                  else "empty dataset"])


def _evaluate_one(df: pd.DataFrame, rule: Dict[str, Any]) -> Dict[str, Any]:
    rtype = rule["type"]
    params = rule["params"]
    col = rule["column"]
    n = len(df)

    if n == 0 and rtype != "schema_drift":
        return _threshold_result(rule, 0, ["empty dataset"], False)

    if rtype == "schema_drift":
        expected = list(params["expected_columns"])
        actual = [str(c) for c in df.columns]
        missing = [c for c in expected if c not in actual]
        extra = [] if params.get("allow_extra", False) else [c for c in actual if c not in expected]
        problems = [f"missing: {c}" for c in missing] + [f"unexpected: {c}" for c in extra]
        return _threshold_result(rule, len(problems), problems, not problems)

    if rtype == "duplicate":
        subset = params.get("columns")
        if subset is not None:
            absent = [c for c in subset if c not in df.columns]
            if absent:
                return _missing_column_result(rule, n)
        dup = df.duplicated(subset=subset, keep="first")
        idx = df[dup].index.tolist()
        return _fail_result(rule, int(dup.sum()), idx)

    if rtype == "completeness" and col is None:
        total_cells = n * max(1, len(df.columns))
        null_cells = int(df.isna().sum().sum())
        ratio = 1 - (null_cells / total_cells) if total_cells else 1.0
        passed = ratio >= float(params.get("min_ratio", 0.9))
        return _threshold_result(rule, null_cells, [], passed)

    # every remaining rule needs its column present
    if col not in df.columns:
        return _missing_column_result(rule, n)
    series = df[col]

    if rtype == "required":
        mask = _is_missing(series, bool(params.get("treat_empty_string_as_missing", True)))
        return _fail_result(rule, int(mask.sum()), series[mask].index.tolist())

    if rtype == "nullable":
        nulls = int(series.isna().sum())
        ratio = nulls / n if n else 0.0
        return _threshold_result(rule, nulls, series[series.isna()].index.tolist(),
                                 ratio <= float(params.get("max_null_ratio", 1.0)))

    if rtype == "unique":
        mask = series[~series.isna()].duplicated(keep=False)
        vals = series[~series.isna()][mask].tolist()
        return _fail_result(rule, int(mask.sum()), vals)

    if rtype == "regex":
        pattern = re.compile(str(params["pattern"]))
        vals = _non_null_str(series)
        bad = vals[~vals.str.fullmatch(pattern, na=False)]
        return _fail_result(rule, len(bad), bad.tolist())

    if rtype == "range":
        lo = params.get("min")
        hi = params.get("max")
        include_min = bool(params.get("include_min", True))
        include_max = bool(params.get("include_max", True))
        numeric = _coerce_number(series)
        non_null = ~series.isna()
        bad_unparseable = non_null & numeric.isna()
        bad = bad_unparseable.copy()
        if lo is not None:
            bad = bad | (non_null & ~numeric.isna() &
                         (numeric < float(lo) if include_min else numeric <= float(lo)))
        if hi is not None:
            bad = bad | (non_null & ~numeric.isna() &
                         (numeric > float(hi) if include_max else numeric >= float(hi)))
        return _fail_result(rule, int(bad.sum()), series[bad].tolist())

    if rtype == "enum":
        allowed = _str_set(params["allowed"])
        vals = series[~series.isna()]
        bad = vals[~vals.astype(str).isin(allowed)]
        return _fail_result(rule, len(bad), bad.tolist())

    if rtype == "datatype":
        dtype = str(params["dtype"])
        vals = series[~series.isna()]
        bad = [v for v in vals.tolist() if not _matches_dtype(v, dtype)]
        return _fail_result(rule, len(bad), bad)

    if rtype == "referential":
        ref = params.get("allowed_values", params.get("ref_values"))
        allowed = _str_set(ref or [])
        vals = series[~series.isna()]
        bad = vals[~vals.astype(str).isin(allowed)]
        return _fail_result(rule, len(bad), bad.tolist())

    if rtype == "freshness":
        parsed = pd.to_datetime(series, errors="coerce", utc=True)
        valid = parsed.dropna()
        if valid.empty:
            return _threshold_result(rule, n, ["no parseable date value"], False)
        ref = params.get("reference")
        now = pd.to_datetime(ref, utc=True) if ref is not None else pd.Timestamp.now(tz="UTC")
        cutoff = now - pd.Timedelta(days=float(params["max_age_days"]))
        stale = valid < cutoff
        max_date = valid.max()
        passed = bool(max_date >= cutoff)
        return _threshold_result(rule, int(stale.sum()), valid[stale].index.tolist(), passed)

    if rtype == "completeness":
        mask = _is_missing(series)
        ratio = 1 - (int(mask.sum()) / n) if n else 1.0
        return _threshold_result(rule, int(mask.sum()), series[mask].index.tolist(),
                                 ratio >= float(params.get("min_ratio", 0.9)))

    if rtype == "consistency":
        mask = _iqr_outliers(series).fillna(False).astype(bool)
        ratio = int(mask.sum()) / n if n else 0.0
        return _threshold_result(rule, int(mask.sum()), series[mask].index.tolist(),
                                 ratio <= float(params.get("max_outlier_ratio", 0.05)))

    if rtype == "validity":
        check = str(params.get("check"))
        if check == "no_negative":
            numeric = _coerce_number(series)
            non_null = ~series.isna()
            bad = (non_null & numeric.isna()) | (numeric < 0)
            bad = bad.fillna(False).astype(bool)
            return _fail_result(rule, int(bad.sum()), series[bad].tolist())
        if check == "parse_date":
            parsed = pd.to_datetime(series, errors="coerce")
            bad = ~series.isna() & parsed.isna()
            return _fail_result(rule, int(bad.sum()), series[bad].tolist())
        numeric = _coerce_number(series)  # parse_number
        bad = ~series.isna() & numeric.isna()
        return _fail_result(rule, int(bad.sum()), series[bad].tolist())

    if rtype == "anomaly_ref":
        sensitivity = float(params.get("sensitivity", 2.5))
        numeric = _coerce_number(series).dropna()
        if len(numeric) < 3 or numeric.std() in (0, None) or pd.isna(numeric.std()):
            return _threshold_result(rule, 0, [], True)
        z = (numeric - numeric.mean()) / numeric.std()
        bad_idx = numeric[z.abs() > sensitivity].index.tolist()
        ratio = len(bad_idx) / n if n else 0.0
        return _threshold_result(rule, len(bad_idx), series.loc[bad_idx].tolist(),
                                 ratio <= float(params.get("max_anomaly_ratio", 0.05)))

    raise RuleValidationError(f"invalid rule: type: unknown type {rtype!r}")  # pragma: no cover


def _pass_rate(result: Dict[str, Any], n: int, df: pd.DataFrame) -> float:
    # An empty frame fails every row rule with count 0, which would otherwise
    # read as a perfect rate; mirror ingestion.quality and score it 0.0.
    if n == 0 or result["type"] == "schema_drift":
        return 1.0 if result["passed"] else 0.0
    denom = max(1, n)
    if result["type"] == "completeness" and result["column"] is None:
        denom = max(1, n * max(1, len(df.columns)))
    return max(0.0, 1 - (result["failure_count"] / denom))


def _aggregate(df: pd.DataFrame, results: List[Dict[str, Any]]) -> Dict[str, Any]:
    n = len(df)
    rates = [_pass_rate(r, n, df) for r in results]
    score = round(float(sum(rates) / len(rates)) if rates else 1.0, 4)
    column_scores: Dict[str, float] = {}
    by_column: Dict[str, List[float]] = {}

    for result, rate in zip(results, rates):
        key = result["column"] if result["column"] is not None else "__dataset__"
        by_column.setdefault(key, []).append(rate)
    for key, values in by_column.items():
        column_scores[key] = round(float(sum(values) / len(values)), 4)
    if any(r["severity"] == "error" and not r["passed"] for r in results):
        verdict = "fail"
    elif any(not r["passed"] for r in results):
        verdict = "warn"
    else:
        verdict = "pass"
    return {"results": results, "score": score,
            "column_scores": column_scores, "verdict": verdict}


def evaluate(df: pd.DataFrame, rules: Sequence[Dict[str, Any]]) -> Dict[str, Any]:
    """Evaluate validated-or-raw rules on the whole frame (deterministic).

    Raw dicts are normalised with :func:`validate_rule` first, so unknown
    types and bad params raise :class:`RuleValidationError` instead of
    evaluating. Rule order is preserved in ``results``.
    """
    normalised = [validate_rule(rule) for rule in rules or []]
    results = [_evaluate_one(df, rule) for rule in normalised]
    return _aggregate(df, results)


class ChunkAccumulator:
    """Streaming evaluator: ``add_chunk`` per chunk, ``finalize`` for the verdict.

    Chunks must keep their original index labels (``df.iloc`` slices do), so
    sample row indexes match a single-pass ``evaluate()`` exactly and no
    offset arithmetic is needed.

    Pure count rules (``required``, ``regex``, ``range``, ``enum``,
    ``datatype``, ``referential``, ``validity``) merge additively: counts
    summed, samples concatenated in chunk order and re-capped at 10, ``passed``
    as the AND of chunk passes. Threshold rules (``nullable``,
    ``completeness``) also sum counts but recompute ``passed`` against the
    global row count at finalize. ``schema_drift`` is identical on every
    chunk, so the last chunk's result is reused verbatim. Global rules
    (``unique``, ``duplicate``, ``consistency``, ``anomaly_ref``,
    ``freshness``) retain the one column they need and are recomputed over
    the concatenated values at finalize -- exact, at the cost of holding one
    column in memory per such rule.
    """

    _GLOBAL_TYPES = frozenset({"unique", "duplicate", "consistency",
                               "anomaly_ref", "freshness"})

    def __init__(self, rules: Sequence[Dict[str, Any]]) -> None:
        self.rules = [validate_rule(rule) for rule in rules or []]
        self._n = 0
        self._counts: List[int] = [0] * len(self.rules)
        self._samples: List[List[Any]] = [[] for _ in self.rules]
        self._passed: List[bool] = [True] * len(self.rules)
        self._state: List[Dict[str, Any]] = [{} for _ in self.rules]
        self._columns: List[str] = []

    def add_chunk(self, chunk: pd.DataFrame) -> None:
        """Fold one chunk in (original index labels required, see class doc)."""
        if self._n == 0:
            self._columns = [str(c) for c in chunk.columns]
        if len(chunk) == 0:
            return
        self._n += len(chunk)
        for i, rule in enumerate(self.rules):
            self._fold(i, rule, chunk)

    def _fold(self, i: int, rule: Dict[str, Any], chunk: pd.DataFrame) -> None:
        rtype = rule["type"]
        col = rule["column"]
        state = self._state[i]
        if rtype in self._GLOBAL_TYPES:
            present = col is None or col in chunk.columns
            if rtype == "duplicate" and rule["params"].get("columns") is not None:
                present = all(c in chunk.columns
                              for c in rule["params"]["columns"])
            if not present:
                state["missing"] = state.get("missing", 0) + len(chunk)
            elif rtype == "unique":
                state.setdefault("values", []).extend(
                    chunk[col][~chunk[col].isna()].tolist())
            elif rtype == "duplicate":
                subset = rule["params"].get("columns")
                frame = chunk[list(subset)] if subset else chunk
                state.setdefault("keys", []).extend(
                    frame.apply(lambda r: tuple(str(v) for v in r.tolist()),
                                axis=1).tolist())
                state.setdefault("index", []).extend(chunk.index.tolist())
            else:  # consistency, anomaly_ref, freshness: retain raw values
                state.setdefault("values", []).extend(chunk[col].tolist())
                state.setdefault("index", []).extend(chunk.index.tolist())
            return
        if rtype == "schema_drift":
            state["last"] = _evaluate_one(chunk, rule)
            return
        partial = _evaluate_one(chunk, rule)
        self._counts[i] += partial["failure_count"]
        if len(self._samples[i]) < MAX_SAMPLES:
            need = MAX_SAMPLES - len(self._samples[i])
            self._samples[i].extend(partial["sample_failures"][:need])
        if not partial["passed"]:
            self._passed[i] = False

    def finalize(self) -> Dict[str, Any]:
        """Return the exact ``evaluate()`` shape over all chunks added."""
        if self._n == 0:
            df = pd.DataFrame({c: [] for c in self._columns})
            return evaluate(df, self.rules)
        results = [self._merge_result(i, rule)
                   for i, rule in enumerate(self.rules)]
        fake = pd.DataFrame({c: range(self._n) for c in self._columns}
                            or {"_": range(self._n)})
        return _aggregate(fake, results)

    def _merge_result(self, i: int, rule: Dict[str, Any]) -> Dict[str, Any]:
        rtype = rule["type"]
        params = rule["params"]
        if rtype in self._GLOBAL_TYPES:
            return self._finalize_global(rule, self._state[i])
        if rtype == "schema_drift":
            last = self._state[i].get("last")
            if last is None:  # pragma: no cover - defensive
                return _threshold_result(rule, 0, [], True)
            return last
        if rtype == "nullable":
            ratio = self._counts[i] / self._n if self._n else 0.0
            return _threshold_result(rule, self._counts[i], self._samples[i],
                                     ratio <= float(params.get("max_null_ratio", 1.0)))
        if rtype == "completeness":
            if rule["column"] is None:
                denom = self._n * max(1, len(self._columns))
                ratio = 1 - (self._counts[i] / denom) if denom else 1.0
            else:
                ratio = 1 - (self._counts[i] / self._n) if self._n else 1.0
            return _threshold_result(rule, self._counts[i], self._samples[i],
                                     ratio >= float(params.get("min_ratio", 0.9)))
        res = _fail_result(rule, self._counts[i], self._samples[i])
        res["passed"] = self._passed[i]
        return res

    def _finalize_global(self, rule: Dict[str, Any], state: Dict[str, Any]) -> Dict[str, Any]:
        rtype = rule["type"]
        if state.get("missing"):
            return _missing_column_result(rule, self._n)
        if rtype == "unique":
            series = pd.Series(state.get("values", []))
            mask = series.duplicated(keep=False)
            return _fail_result(rule, int(mask.sum()), series[mask].tolist())
        if rtype == "duplicate":
            dup = pd.Series(state.get("keys", [])).duplicated(keep="first")
            labels = state.get("index", [])
            idx = [label for flag, label in zip(dup.tolist(), labels) if flag]
            return _fail_result(rule, int(dup.sum()), idx)
        values = pd.Series(state.get("values", []),
                           index=state.get("index",
                                           range(len(state.get("values", [])))))
        if rtype == "consistency":
            mask = _iqr_outliers(values).fillna(False).astype(bool)
            ratio = int(mask.sum()) / self._n if self._n else 0.0
            return _threshold_result(rule, int(mask.sum()), values[mask].tolist(),
                                     ratio <= float(rule["params"].get(
                                         "max_outlier_ratio", 0.05)))
        if rtype == "freshness":
            probe = values.to_frame("v")
            probe_rule = {"id": rule["id"], "column": "v", "type": "freshness",
                          "params": rule["params"], "severity": rule["severity"]}
            tmp = _evaluate_one(probe, probe_rule)
            return _threshold_result(rule, tmp["failure_count"],
                                     tmp["sample_failures"], tmp["passed"])
        probe = values.to_frame("v")  # anomaly_ref
        probe_rule = {"id": rule["id"], "column": "v", "type": "anomaly_ref",
                      "params": rule["params"], "severity": rule["severity"]}
        tmp = _evaluate_one(probe, probe_rule)
        # The probe denominator is len(values) == rows seen, same basis the
        # single-pass ratio uses, so count/passed/samples transfer verbatim.
        return _threshold_result(rule, tmp["failure_count"],
                                 tmp["sample_failures"], tmp["passed"])


def evaluate_in_chunks(df: pd.DataFrame, rules: Sequence[Dict[str, Any]],
                       chunksize: int = 10000) -> Dict[str, Any]:
    """Split ``df`` into ``chunksize`` row blocks and evaluate via accumulator."""
    acc = ChunkAccumulator(rules)
    size = max(1, int(chunksize))
    for start in range(0, len(df), size):
        acc.add_chunk(df.iloc[start:start + size])
    if len(df) == 0:
        acc.add_chunk(df)
    return acc.finalize()