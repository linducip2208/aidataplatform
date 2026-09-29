"""Tool definitions + implementations.

Every tool is read-only and returns ``{"source": <table or pipeline>, "data": {...}}``.
``data`` is always a mapping so it satisfies the ``EvidenceRow`` contract in
``app/schemas/ai.py``; list-shaped results are wrapped as ``{"rows": [...], "count": n}``.

A tool has exactly three honest outcomes â€” real data, ``{"note": ..., "empty": true}`` when
the window holds no row, or ``{"error": ..., "empty": true}`` when the read failed. None of
them is a zero-filled figure. That distinction is load-bearing: ``revenue: 0.0`` on an
executive dashboard is indistinguishable from a business that stopped selling, and no
downstream reader can tell a failed query from a real measurement.

The result is used three times over: it goes into the API response body, into the
``ai_messages.evidence`` JSON column and into the LLM prompt. Every value is therefore
coerced by :func:`_jsonable` to something ``json.dumps`` accepts, because the analytics and
ML helpers return numpy scalars, ``NaN`` and (for ``ml.segmentation.segment``) live sklearn
estimator objects, none of which serialise.
"""
from __future__ import annotations

import math
import re
from datetime import date, datetime, timedelta
from decimal import Decimal
from typing import Any, Dict, List

import pandas as pd

TOOL_DEFS = [
    {"type": "function", "function": {"name": "get_kpi", "description": "Get sales KPIs with optional filters",
                                      "parameters": {"type": "object", "properties": {"date_from": {"type": "string"}, "date_to": {"type": "string"}, "branch": {"type": "string"}}}}},
    {"type": "function", "function": {"name": "query_sales", "description": "Query sales trend",
                                      "parameters": {"type": "object", "properties": {"granularity": {"type": "string"}}}}},
    {"type": "function", "function": {"name": "query_inventory", "description": "Inventory health",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_customer", "description": "Customer RFM segments",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_product", "description": "Product ABC analysis",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "query_finance", "description": "Finance summary",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "get_forecast", "description": "Sales forecast",
                                      "parameters": {"type": "object", "properties": {"horizon": {"type": "integer"}}}}},
    {"type": "function", "function": {"name": "get_anomaly", "description": "Anomaly detection on revenue series",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "get_customer_segment", "description": "Customer segmentation",
                                      "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {"name": "generate_report", "description": "Executive summary report",
                                      "parameters": {"type": "object", "properties": {"period": {"type": "string"}}}}},
]

# --- limits -----------------------------------------------------------------
FRAME_WINDOW_DAYS = 90
FRAME_ROW_LIMIT = 5000
EVIDENCE_ROW_LIMIT = 60
JSON_ITEM_LIMIT = 60
JSON_KEY_LIMIT = 60
JSON_DEPTH_LIMIT = 6
TEXT_LIMIT = 400
MAX_HORIZON = 365
DEFAULT_HORIZON = 30
NO_DATA_NOTE = f"no rows in the last {FRAME_WINDOW_DAYS} days"

_SECRET_RE = re.compile(
    r"(?i)\b(?:password|passwd|pwd|secret|token|api[_-]?key|authorization|bearer)\b\s*[:=]\s*\S+"
)
_DSN_RE = re.compile(r"(?i)\b(?:postgres(?:ql)?|mysql|mariadb|sqlite|redis|mongodb)(?:\+\w+)?://[^\s'\"]+")
_STATEMENT_RE = re.compile(
    r"(?i)\b(?:select\s+.+\s+from|insert\s+into|update\s+\w+\s+set|delete\s+from|drop\s+table|"
    r"alter\s+table|create\s+table|union\s+select)\b"
)
# SQLAlchemy renders the statement and its bound parameters on their own lines, and
# truncation can cut the leading keyword off a long statement while the tail survives.
_SQL_MARKER_RE = re.compile(r"\[SQL[:\s]|\[parameters:")


def _clip(value: Any, limit: int) -> str:
    """Return ``value`` as text no longer than ``limit`` characters."""
    text = value if isinstance(value, str) else str(value)
    return text if len(text) <= limit else text[:limit] + "..."


def redact(message: Any, limit: int = 240) -> str:
    """Reduce an exception message to a loggable, response-safe string.

    SQLAlchemy exception text embeds the failing statement and its bound parameters, and
    a connection error can embed a DSN. Neither belongs in an API response, an
    ``ai_messages`` row or a prompt, so a statement-shaped message is dropped entirely.

    Detection runs on the *whole* message and the result is clipped afterwards. Clipping
    first defeated the check: a 240-character cut lands in the middle of the statement, so
    the leading ``SELECT`` is gone, ``_STATEMENT_RE`` no longer matches, and the excerpt
    that survives is exactly the schema and the bound values the redaction exists to keep
    out of the response.
    """
    text = _DSN_RE.sub("<redacted-dsn>", str(message))
    text = _SECRET_RE.sub("<redacted-secret>", text)
    if _STATEMENT_RE.search(text) or _SQL_MARKER_RE.search(text):
        return "tool_error: <statement text redacted>"
    return _clip(text, limit)


class ToolError(RuntimeError):
    """A tool could not read the warehouse.

    Carries an already-redacted message: the class exists so a *failed* read is
    distinguishable from a window that is genuinely empty. Conflating the two is how a
    broken query turns into a business dashboard full of confident zeros.
    """


def _jsonable(value: Any, depth: int = 0) -> Any:
    """Return a copy of ``value`` that ``json.dumps`` can always handle.

    numpy scalars become Python scalars, ``NaN``/``inf`` become ``None``, and any object
    with no scalar representation (a scikit-learn estimator, a DataFrame) becomes a
    ``<TypeName>`` marker rather than its ``repr``, which can carry internal state.
    """
    if value is None or isinstance(value, (str, bool, int)):
        return value
    if isinstance(value, float):
        return value if math.isfinite(value) else None
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if depth >= JSON_DEPTH_LIMIT:
        return _clip(value, TEXT_LIMIT)
    if isinstance(value, dict):
        return {str(k)[:64]: _jsonable(v, depth + 1)
                for k, v in list(value.items())[:JSON_KEY_LIMIT]}
    if isinstance(value, (list, tuple, set, frozenset)):
        return [_jsonable(v, depth + 1) for v in list(value)[:JSON_ITEM_LIMIT]]
    tolist = getattr(value, "tolist", None)  # numpy array
    if callable(tolist):
        try:
            return _jsonable(tolist(), depth)
        except Exception:
            pass
    item = getattr(value, "item", None)  # numpy scalar
    if callable(item):
        try:
            return _jsonable(item(), depth)
        except Exception:
            pass
    return f"<{type(value).__name__}>"


def evidence_data(data: Any) -> Dict[str, Any]:
    """Coerce a tool result into the ``EvidenceRow.data`` mapping the schema declares.

    Returns the mapping unchanged, or ``{"rows": [...], "count": n}`` for a list result.
    """
    safe = _jsonable(data)
    if isinstance(safe, dict):
        return safe
    if isinstance(safe, list):
        return {"rows": safe[:EVIDENCE_ROW_LIMIT], "count": len(safe)}
    return {"value": safe}


def _load_frame(table: str, db_session, window_days: int = FRAME_WINDOW_DAYS) -> pd.DataFrame:
    """Load a warehouse frame for ``table`` as a DataFrame of plain Python values.

    The table is selected by literal comparison, never interpolated, and every
    filter this layer needs is expressed as a SQLAlchemy condition, so the dialect
    binds the values.

    The window and the ordering matter. Previously this was an unfiltered
    ``LIMIT 5000`` with no ``ORDER BY``, so it scanned the whole fact table and
    returned an *arbitrary* 5000 rows: the same question could be answered with
    different numbers on two consecutive calls, and the evidence persisted
    alongside the answer was not reproducible. It is now a bounded, ordered
    window â€” most recent ``window_days`` days only, served by the
    ``transaction_date`` index â€” so the numbers are stable and the cost is
    proportional to the window, not to the table.

    A failed read yields an **empty** frame, as it always has. Several analytics
    routes call this helper directly and render from it, so the lenient contract
    is part of their interface. Evidence-producing tools must not use it: for them an
    empty frame is ambiguous, and they use :func:`_load_frame_strict`, which raises
    :class:`ToolError` instead so a broken read is never reported as a window with
    nothing in it.
    """
    try:
        return _load_frame_strict(table, db_session, window_days)
    except ToolError:
        return pd.DataFrame()


def _load_frame_strict(table: str, db_session, window_days: int = FRAME_WINDOW_DAYS) -> pd.DataFrame:
    """As :func:`_load_frame`, but a failed read raises :class:`ToolError`.

    This is the split that keeps a fabricated zero out of the evidence. The two states
    an analytics route can afford to confuse â€” "no rows" and "the query failed" â€” both
    render as an empty page. A business number cannot: ``revenue: 0.0`` is
    indistinguishable from a business that stopped selling, so a tool must be able to
    tell the caller which one it has.

    Returns an empty frame only when there is no session or the window genuinely holds
    no row.
    """
    if db_session is None:
        return pd.DataFrame()
    try:
        cutoff = date.today() - timedelta(days=max(1, int(window_days)))
        if table == "sales":
            from app.database.models import DimBranch, DimCustomer, DimProduct, FactSales

            q = db_session.query(FactSales.revenue, FactSales.quantity, FactSales.transaction_date,
                                 DimCustomer.customer_name, DimProduct.product_name,
                                 DimBranch.branch_name, DimProduct.category).outerjoin(
                DimCustomer, FactSales.customer_id == DimCustomer.id).outerjoin(
                DimProduct, FactSales.product_id == DimProduct.id).outerjoin(
                DimBranch, FactSales.branch_id == DimBranch.id).filter(
                FactSales.transaction_date >= cutoff).order_by(
                FactSales.transaction_date.desc().nullslast()).limit(FRAME_ROW_LIMIT)
            rows = [{"revenue": r[0] or 0, "quantity": r[1] or 0, "transaction_date": r[2],
                     "customer_name": r[3] or "", "product_name": r[4] or "",
                     "branch_name": r[5] or "", "category": r[6] or ""} for r in q.all()]
            return pd.DataFrame(rows)
        if table == "inventory":
            from app.database.models import DimProduct, FactInventory

            q = db_session.query(FactInventory.stock_qty, DimProduct.product_name).outerjoin(
                DimProduct, FactInventory.product_id == DimProduct.id).filter(
                FactInventory.snapshot_date >= cutoff).order_by(
                FactInventory.snapshot_date.desc().nullslast()).limit(FRAME_ROW_LIMIT)
            return pd.DataFrame([{"product_name": r[1] or "", "stock_qty": r[0] or 0} for r in q.all()])
    except ToolError:
        raise
    except Exception as exc:
        raise ToolError(redact(exc)) from exc
    return pd.DataFrame()


def _result(source: str, data: Any) -> Dict[str, Any]:
    """Build the ``{"source": ..., "data": ...}`` evidence envelope for one tool."""
    return {"source": source, "data": evidence_data(data)}


def _no_data(source: str) -> Dict[str, Any]:
    """Evidence for a source whose window is genuinely empty â€” a marker, and no numbers.

    This is the whole point of :class:`ToolError`: a zero-filled KPI block
    (``revenue: 0.0, orders: 0``) is indistinguishable on a dashboard from a business
    that genuinely stopped selling, so a warehouse with no rows in the window must say
    so and stay silent about the figures. The system prompt already tells the model to
    answer "data belum tersedia" when a source has no data.
    """
    return {"source": source, "data": {"note": NO_DATA_NOTE, "empty": True}}


def _failed(source: str, exc: BaseException) -> Dict[str, Any]:
    """Evidence for a source whose query failed: a redacted error and no numbers."""
    return {"source": source, "data": {"error": redact(exc), "empty": True}}


def _horizon(args: Dict[str, Any]) -> int:
    """Read a forecast horizon, defaulting to 30 and capping it at 365 days."""
    raw = (args or {}).get("horizon", DEFAULT_HORIZON)
    try:
        value = int(raw)
    except (TypeError, ValueError):
        return DEFAULT_HORIZON
    return max(1, min(MAX_HORIZON, value))


def _daily_revenue(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Collapse a sales frame into ``[{"date": str, "y": float}, ...]`` for the ML helpers."""
    if df.empty or "transaction_date" not in df.columns:
        return []
    g = df.groupby(pd.to_datetime(df["transaction_date"]).dt.date)["revenue"].sum()
    return [{"date": str(k), "y": float(v)} for k, v in g.items()]


def execute_tool(name: str, args: Dict[str, Any], db_session=None) -> Dict[str, Any]:
    """Run one read-only tool and return ``{"source": str, "data": dict}``.

    ``data`` is always a JSON-serialisable mapping. Analytics and ML imports are local so
    one missing optional dependency cannot take the whole tool set down with it, and the
    resulting values are passed through :func:`evidence_data` so nothing that cannot be
    serialised reaches the response, the JSON column or the prompt.

    Three outcomes, and only three: real data, an explicit *no rows in the window*
    marker, or an error. There is no path that returns a zero-filled figure. A read that
    failed is reported as an error, never as ``revenue: 0.0`` â€” a fabricated zero in an
    executive dashboard is worse than an error, because nothing downstream can tell the
    two apart. Any other exception propagates to the caller, which is the single place
    that turns a raised tool into an evidence row.
    """
    args = args if isinstance(args, dict) else {}

    if name == "get_kpi":
        from app.analytics import sales as sa

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("fact_sales", exc)
        return _no_data("fact_sales") if df.empty else _result("fact_sales", sa.sales_kpi(df))
    if name == "query_sales":
        from app.analytics import sales as sa

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("fact_sales", exc)
        if df.empty:
            return _no_data("fact_sales")
        return _result("fact_sales", sa.sales_trend(df, str(args.get("granularity") or "daily"))[:60])
    if name == "query_inventory":
        from app.analytics import inventory as ia

        try:
            df = _load_frame_strict("inventory", db_session)
            sdf = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("fact_inventory", exc)
        return _no_data("fact_inventory") if df.empty else _result("fact_inventory", ia.inventory_health(df, sdf)[:50])
    if name == "query_customer":
        from app.analytics import customers as ca

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("fact_sales:rfm", exc)
        return _no_data("fact_sales:rfm") if df.empty else _result("fact_sales:rfm", ca.rfm(df)[:50])
    if name == "query_product":
        from app.analytics import products as pa

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("fact_sales:abc", exc)
        return _no_data("fact_sales:abc") if df.empty else _result("fact_sales:abc", pa.abc_analysis(df)[:50])
    if name == "query_finance":
        from app.analytics import finance as fa

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("finance", exc)
        return _no_data("finance") if df.empty else _result("finance", fa.finance_summary(df))
    if name == "get_forecast":
        from app.ml.forecasting import forecast as _fc

        try:
            history = _daily_revenue(_load_frame_strict("sales", db_session))
        except ToolError as exc:
            return _failed("ml.forecast", exc)
        return _no_data("ml.forecast") if not history else _result("ml.forecast", _fc(history, _horizon(args)))
    if name == "get_anomaly":
        from app.ml.anomaly import detect_anomalies

        try:
            history = _daily_revenue(_load_frame_strict("sales", db_session))
        except ToolError as exc:
            return _failed("ml.anomaly", exc)
        if not history:
            return _no_data("ml.anomaly")
        return _result("ml.anomaly", detect_anomalies([{"date": r["date"], "value": r["y"]} for r in history]))
    if name == "get_customer_segment":
        from app.ml.features import customer_features
        from app.ml.segmentation import segment

        try:
            df = _load_frame_strict("sales", db_session)
        except ToolError as exc:
            return _failed("ml.segment", exc)
        if df.empty:
            return _no_data("ml.segment")
        feats = _jsonable(customer_features(df).to_dict("records"))
        return _result("ml.segment", segment(feats))
    if name == "generate_report":
        from app.ai.reporting import executive_summary

        period = str(args.get("period") or "weekly")
        return _result("reporting", executive_summary(db_session, period=period))
    return {"source": name, "data": {"error": f"unknown_tool: {_clip(name, 64)}", "empty": True}}


def failed_tool_result(name: str, exc: BaseException) -> Dict[str, Any]:
    """Build the evidence row for a tool that raised.

    ``data.error`` keeps the documented shape but carries a redacted, length-capped
    message: the raw ``str(exc)`` of a SQLAlchemy error carries the failing statement.
    """
    return {"source": name, "data": {"error": redact(exc)}}


