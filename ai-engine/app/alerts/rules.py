"""Rule evaluation: operators, the metric registry, and the alert state machine.

This module is deliberately free of SQLAlchemy, Celery and (at import time)
pandas so the logic that has to be exactly right -- operator semantics and the
fire / still-firing / resolve transition -- can be unit tested with no database
and no warehouse.

Three facts about the schema drive the whole design, all verified against
``app/database/models.py`` and Alembic 0001:

1. ``alert_rules`` has no severity column, so severity is a property of the
   *metric* (see :data:`METRICS`) and is copied onto ``alerts.severity``
   (``String(16)``) when the alert opens. The vocabulary is the one
   ``app/analytics/inventory.py`` already returns for ``stockout_risk``, so no
   new vocabulary is introduced.
2. ``alerts`` has no ``triggered_at`` / ``resolved_at`` column, so the alert
   lifecycle is expressed through ``status`` plus the ``created_at`` /
   ``updated_at`` pair from ``TimestampMixin``: ``created_at`` is when the open
   alert was opened, ``updated_at`` is the last observation of a firing rule or
   the moment it was resolved.
3. There is no unique index on ``alerts (rule_id) WHERE status IN (...)``, so
   "one open alert per rule" is an application invariant. See
   :func:`plan_transition` and :class:`AlertStateMachine`.

The schema also cannot store a per-rule dimension/filter (branch, category) or
a per-rule evaluation window, so both are deployment-wide constants here:
:data:`EVALUATION_WINDOW_DAYS`. That is a schema limitation, not a design
choice, and it is reported in the handoff notes.
"""
from __future__ import annotations

import operator
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any, Callable, Dict, List, Mapping, Optional, Tuple

from app.core.errors import COMMON_RESOLUTIONS, AppError, redact_secrets

__all__ = [
    "AlertConfigurationError",
    "AlertStateMachine",
    "AlertStateMachineError",
    "ACTION_KEEP",
    "ACTION_NONE",
    "ACTION_OPEN",
    "ACTION_RESOLVE",
    "EVALUATION_WINDOW_DAYS",
    "MetricData",
    "MetricSpec",
    "OPEN_STATUSES",
    "OPERATORS",
    "SEVERITIES",
    "STATUS_ACKNOWLEDGED",
    "STATUS_OPEN",
    "STATUS_RESOLVED",
    "Transition",
    "apply_operator",
    "build_metric_data",
    "clip_metric",
    "coerce_threshold",
    "compose_message",
    "metric_catalog",
    "normalize_condition",
    "plan_transition",
    "resolve_metric",
    "resolve_severity",
]

# --------------------------------------------------------------------------
# vocabularies
# --------------------------------------------------------------------------

# ``alerts.severity`` is String(16) with default "medium" and no enum in the
# schema. The values below are exactly the ones ``inventory_health`` already
# returns in ``stockout_risk`` (critical/high/medium/low); the longest is 8
# characters, well inside the column.
SEVERITIES: Tuple[str, ...] = ("low", "medium", "high", "critical")
DEFAULT_SEVERITY = "medium"

# ``alerts.status`` is String(32) with default "open". OPEN_STATUSES are the
# statuses that mean "not yet resolved": an acknowledged alert is still
# un-resolved and must therefore still suppress a duplicate.
STATUS_OPEN = "open"
STATUS_ACKNOWLEDGED = "acknowledged"
STATUS_RESOLVED = "resolved"
OPEN_STATUSES: Tuple[str, ...] = (STATUS_OPEN, STATUS_ACKNOWLEDGED)

EVENT_FIRED = "fired"
EVENT_RESOLVED = "resolved"
EVENT_ACKNOWLEDGED = "acknowledged"
EVENT_NOTIFIED = "notified"

# ``alert_rules.condition`` is String(16) with default ">", so the symbols the
# column already defaults to are canonical, and the spelled-out forms are
# accepted as aliases for rows written by hand or by an older client.
OPERATORS: Dict[str, str] = {
    ">": ">", "gt": ">", "greater": ">",
    ">=": ">=", "gte": ">=", "greater_or_equal": ">=",
    "<": "<", "lt": "<", "less": "<",
    "<=": "<=", "lte": "<=", "less_or_equal": "<=",
    "==": "==", "=": "==", "eq": "==", "equal": "==",
    "!=": "!=", "ne": "!=", "not_equal": "!=", "<>": "!=",
}

_COMPARATORS: Dict[str, Callable[[Any, Any], bool]] = {
    ">": operator.gt,
    ">=": operator.ge,
    "<": operator.lt,
    "<=": operator.le,
    "==": operator.eq,
    "!=": operator.ne,
}

# How many trailing days of ``fact_sales`` a rule is evaluated over. The schema
# has no column for it (see the module docstring), so it is a constant. One day
# matches a per-minute evaluation of a daily-grain metric: re-checking the same
# window is idempotent, and the dedup state machine makes a repeated true result
# update one row instead of creating another.
EVALUATION_WINDOW_DAYS = 1

# ``alerts.message`` is Text but a runaway metric name would still bloat every
# row that copies it, so the composed message is clipped.
MESSAGE_MAX_CHARS = 480
# ``alert_rules.metric`` is String(64); an unvalidated value is clipped to the
# column width before it reaches a log line or a JSON payload, and control
# characters are dropped so a crafted metric key cannot forge a log record.
METRIC_ECHO_MAX_CHARS = 64
# ``alert_rules.name`` is String(128) and is copied into alerts.message.
NAME_ECHO_MAX_CHARS = 128


class AlertConfigurationError(AppError):
    """A rule is misconfigured: unknown metric, unknown operator, bad threshold.

    Carries ``details`` for the API envelope. Deliberately raised instead of
    returning a neutral value -- a rule that cannot be evaluated must never look
    like a rule that evaluated to "fine".
    """

    def __init__(
        self,
        message: str,
        *,
        operation: str,
        error_type: str,
        code: str,
        status_code: int = 400,
        details: Optional[Mapping[str, Any]] = None,
    ) -> None:
        super().__init__(
            module="alerts",
            operation=operation,
            error_type=error_type,
            message=message,
            technical=redact_secrets(message),
            resolution=COMMON_RESOLUTIONS["validation"],
            status_code=status_code,
            code=code,
        )
        self.details: Dict[str, Any] = dict(details or {})


class AlertStateMachineError(AppError):
    """The dedup machine was driven into an impossible sequence."""

    def __init__(self, message: str) -> None:
        super().__init__(
            module="alerts",
            operation="state_machine",
            error_type="invalid_transition",
            message=message,
            technical=redact_secrets(message),
            resolution=COMMON_RESOLUTIONS["validation"],
            status_code=500,
            code="ALERT_STATE_MACHINE",
        )


def clip_metric(metric: Any) -> str:
    """Return ``metric`` as a single-line string no wider than the column."""
    text = "".join(ch for ch in str(metric or "") if ch.isprintable())
    return text[:METRIC_ECHO_MAX_CHARS]


# --------------------------------------------------------------------------
# operators
# --------------------------------------------------------------------------
def normalize_condition(raw: Any, *, rule_id: Optional[int] = None) -> str:
    """Return the canonical symbol for a condition ("gt" and ">" both -> ">").

    Raises :class:`AlertConfigurationError` for anything the column cannot
    meaningfully hold. An unknown operator is a configuration error, never a
    silent pass.
    """
    if raw is None:
        raise AlertConfigurationError(
            "Alert rule has no condition.",
            operation="normalize_condition",
            error_type="unknown_operator",
            code="ALERT_UNKNOWN_OPERATOR",
            details={"rule_id": rule_id, "supported": sorted(set(OPERATORS.values()))},
        )
    key = str(raw).strip().lower()
    symbol = OPERATORS.get(key)
    if symbol is None:
        raise AlertConfigurationError(
            f"Unsupported alert condition {clip_metric(raw)!r}. "
            f"Supported: {', '.join(sorted(set(OPERATORS.values())))}.",
            operation="normalize_condition",
            error_type="unknown_operator",
            code="ALERT_UNKNOWN_OPERATOR",
            details={
                "rule_id": rule_id,
                "condition": clip_metric(raw),
                "supported": sorted(set(OPERATORS.values())),
            },
        )
    return symbol


def coerce_threshold(raw: Any, *, rule_id: Optional[int] = None) -> float:
    """Return ``raw`` as a finite float, raising on anything else.

    ``alert_rules.threshold`` is ``Float NOT NULL``; a NaN threshold would make
    every comparison False forever and look like a healthy rule.
    """
    try:
        value = float(raw)
    except (TypeError, ValueError) as exc:
        raise AlertConfigurationError(
            f"Alert threshold {clip_metric(raw)!r} is not a number.",
            operation="coerce_threshold",
            error_type="invalid_threshold",
            code="ALERT_INVALID_THRESHOLD",
            details={"rule_id": rule_id},
        ) from exc
    if value != value or value in (float("inf"), float("-inf")):
        raise AlertConfigurationError(
            "Alert threshold must be a finite number.",
            operation="coerce_threshold",
            error_type="invalid_threshold",
            code="ALERT_INVALID_THRESHOLD",
            details={"rule_id": rule_id},
        )
    return value


def apply_operator(value: float, condition: str, threshold: float) -> bool:
    """Return whether ``value <condition> threshold`` holds.

    ``condition`` is either a canonical symbol or any alias accepted by
    :func:`normalize_condition`; boundaries follow the operator, so ``>=`` and
    ``<=`` include equality and ``>``/``<`` do not.
    """
    symbol = normalize_condition(condition)
    left = float(value)
    right = float(threshold)
    if left != left or right != right:  # NaN
        return False
    return bool(_COMPARATORS[symbol](left, right))


# --------------------------------------------------------------------------
# metric registry
# --------------------------------------------------------------------------
@dataclass(frozen=True)
class MetricData:
    """The frames a metric is computed from.

    ``sales`` is already filtered to the evaluation window by
    :func:`build_metric_data`; ``inventory`` is a stock snapshot and is not
    windowed (nothing in ``inventory_health`` accepts a date bound).
    """

    sales: Any
    inventory: Any
    window_days: int = EVALUATION_WINDOW_DAYS


@dataclass(frozen=True)
class MetricSpec:
    """One evaluatable metric: how to compute it and how loudly it speaks."""

    key: str
    source: str
    unit: str
    severity: str
    description: str
    compute: Callable[[MetricData], float]
    windowed: bool = True


def _kpi(ctx: MetricData) -> Mapping[str, Any]:
    from app.analytics import sales as sa

    return sa.sales_kpi(ctx.sales)


def _m_sales_revenue(ctx: MetricData) -> float:
    return float(_kpi(ctx).get("revenue", 0.0))


def _m_sales_orders(ctx: MetricData) -> float:
    return float(_kpi(ctx).get("orders", 0))


def _m_sales_units(ctx: MetricData) -> float:
    return float(_kpi(ctx).get("units", 0.0))


def _m_sales_aov(ctx: MetricData) -> float:
    return float(_kpi(ctx).get("aov", 0.0))


def _m_sales_growth(ctx: MetricData) -> float:
    return float(_kpi(ctx).get("growth_pct", 0.0))


def _branch_rows(ctx: MetricData) -> List[Dict[str, Any]]:
    from app.analytics import branches as ba

    rows = ba.branch_kpi(ctx.sales)
    return rows if isinstance(rows, list) else []


def _m_branch_revenue_max(ctx: MetricData) -> float:
    """Revenue of the top branch; 0.0 when the frame has no branch column."""
    rows = _branch_rows(ctx)
    return float(rows[0]["revenue"]) if rows else 0.0


def _m_branch_count(ctx: MetricData) -> float:
    return float(len(_branch_rows(ctx)))


def _inventory_rows(ctx: MetricData) -> List[Dict[str, Any]]:
    from app.analytics import inventory as ia

    sales = ctx.sales if ctx.sales is not None and not ctx.sales.empty else None
    rows = ia.inventory_health(ctx.inventory, sales)
    return rows if isinstance(rows, list) else []


def _m_inventory_stockout_count(ctx: MetricData) -> float:
    """Products at critical or high stockout risk -- ``inventory_health``'s own
    bands, so the number inherits the analytics module's meaning."""
    return float(sum(1 for r in _inventory_rows(ctx)
                     if r.get("stockout_risk") in ("critical", "high")))


def _m_inventory_dead_stock_count(ctx: MetricData) -> float:
    return float(sum(1 for r in _inventory_rows(ctx) if r.get("dead_stock")))


def _m_inventory_min_days(ctx: MetricData) -> float:
    """Smallest days_of_stock across products; 0.0 when there is no stock."""
    rows = _inventory_rows(ctx)
    if not rows:
        return 0.0
    return float(min(float(r.get("days_of_stock", 0.0)) for r in rows))


def _finance(ctx: MetricData) -> Mapping[str, Any]:
    from app.analytics import finance as fa

    return fa.finance_summary(ctx.sales)


def _m_finance_net_profit(ctx: MetricData) -> float:
    return float(_finance(ctx).get("net_profit", 0.0))


def _m_finance_margin(ctx: MetricData) -> float:
    return float(_finance(ctx).get("margin_pct", 0.0))


# The registry is total over the metric namespace: a rule's ``metric`` column is
# either a key here or a configuration error raised by resolve_metric().
METRICS: Dict[str, MetricSpec] = {
    spec.key: spec
    for spec in (
        MetricSpec("sales.revenue", "fact_sales", "IDR", "high",
                   "Total sales revenue in the evaluation window.",
                   _m_sales_revenue),
        MetricSpec("sales.orders", "fact_sales", "count", "medium",
                   "Number of sales transactions in the evaluation window.",
                   _m_sales_orders),
        MetricSpec("sales.units", "fact_sales", "pcs", "medium",
                   "Units sold in the evaluation window.", _m_sales_units),
        MetricSpec("sales.aov", "fact_sales", "IDR", "low",
                   "Average order value in the evaluation window.", _m_sales_aov),
        MetricSpec("sales.growth_pct", "fact_sales", "%", "medium",
                   "Second-half vs first-half revenue change inside the window.",
                   _m_sales_growth),
        MetricSpec("branch.revenue_max", "fact_sales:dim_branch", "IDR", "high",
                   "Revenue of the highest-earning branch.", _m_branch_revenue_max),
        MetricSpec("branch.count", "fact_sales:dim_branch", "count", "low",
                   "Number of branches with sales in the window.", _m_branch_count),
        MetricSpec("inventory.stockout_count", "fact_inventory", "count", "critical",
                   "Products at critical or high stockout risk.",
                   _m_inventory_stockout_count, windowed=False),
        MetricSpec("inventory.dead_stock_count", "fact_inventory", "count", "medium",
                   "Products with stock but no sales in the window.",
                   _m_inventory_dead_stock_count, windowed=False),
        MetricSpec("inventory.min_days_of_stock", "fact_inventory", "days", "high",
                   "Fewest days of stock left on any product.",
                   _m_inventory_min_days, windowed=False),
        MetricSpec("finance.net_profit", "finance", "IDR", "high",
                   "Net profit from the sales frame in the window.",
                   _m_finance_net_profit),
        MetricSpec("finance.margin_pct", "finance", "%", "high",
                   "Net margin percentage for the window.", _m_finance_margin),
    )
}


def resolve_metric(metric: Any) -> Optional[MetricSpec]:
    """Return the :class:`MetricSpec` for ``metric``, or None when unknown.

    Matching is case-insensitive and ignores surrounding whitespace, because
    ``metric`` is a free-form ``String(64)`` written by an operator.
    """
    if metric is None:
        return None
    return METRICS.get(str(metric).strip().lower())


def require_metric(metric: Any, *, rule_id: Optional[int] = None) -> MetricSpec:
    """Return the spec for ``metric`` or raise a clear configuration error."""
    spec = resolve_metric(metric)
    if spec is None:
        raise AlertConfigurationError(
            f"Unknown alert metric {clip_metric(metric)!r}. "
            f"Supported: {', '.join(sorted(METRICS))}.",
            operation="resolve_metric",
            error_type="unknown_metric",
            code="ALERT_UNKNOWN_METRIC",
            details={
                "rule_id": rule_id,
                "metric": clip_metric(metric),
                "supported": sorted(METRICS),
            },
        )
    return spec


def resolve_severity(spec: MetricSpec) -> str:
    """Return the severity to stamp on an alert for ``spec``.

    ``alert_rules`` has no severity column, so the metric decides. An out-of-
    vocabulary severity in the registry falls back to the model default rather
    than inventing a value.
    """
    severity = (spec.severity or "").strip().lower()
    return severity if severity in SEVERITIES else DEFAULT_SEVERITY


def metric_catalog() -> List[Dict[str, Any]]:
    """Return the API-facing metric list: one dict per supported metric."""
    return [
        {
            "metric": spec.key,
            "source": spec.source,
            "unit": spec.unit,
            "severity": resolve_severity(spec),
            "windowed": spec.windowed,
            "description": spec.description,
        }
        for spec in sorted(METRICS.values(), key=lambda s: s.key)
    ]


def build_metric_data(db: Any, window_days: int = EVALUATION_WINDOW_DAYS) -> MetricData:
    """Load the warehouse frames once for a whole evaluation run.

    Returns :class:`MetricData` with ``sales`` restricted to the trailing
    ``window_days`` days and ``inventory`` unfiltered (a stock snapshot has no
    date bound in the analytics layer). Frame loading is the one the routers and
    the AI tools already use, so an alert and ``/analytics/kpi`` cannot disagree
    about what the data means.
    """
    from app.ai.tools import _load_frame
    from app.analytics import sales as sa

    sales_df = _load_frame("sales", db)
    if window_days and sales_df is not None and not sales_df.empty:
        # Naive datetimes on purpose: fact_sales.transaction_date is a DATE
        # column, so a tz-aware bound would raise on comparison.
        end = datetime.now(timezone.utc).replace(tzinfo=None)
        sales_df = sa.apply_filters(sales_df, end - timedelta(days=window_days), end)
    inventory_df = _load_frame("inventory", db)
    return MetricData(sales=sales_df, inventory=inventory_df, window_days=window_days)


def compose_message(name: Any, spec: MetricSpec, value: float, condition: str,
                    threshold: float, window_days: int) -> str:
    """Return the ``alerts.message`` text for an observation.

    One line, clipped to :data:`MESSAGE_MAX_CHARS` and passed through
    :func:`~app.core.errors.redact_secrets`, because ``name`` is operator-supplied
    free text and the column is copied onto every subsequent update.
    """
    unit = f" {spec.unit}" if spec.unit else ""
    safe_name = redact_secrets(
        "".join(ch for ch in str(name or "") if ch.isprintable())[:NAME_ECHO_MAX_CHARS]
    )
    text = (
        f"[{resolve_severity(spec).upper()}] {safe_name}: "
        f"{spec.key} = {value:.2f}{unit} {condition} {float(threshold):.2f} "
        f"(window {window_days}d)"
    )
    return redact_secrets(text)[:MESSAGE_MAX_CHARS]


# --------------------------------------------------------------------------
# dedup state machine
# --------------------------------------------------------------------------
ACTION_OPEN = "open"
ACTION_KEEP = "keep"
ACTION_RESOLVE = "resolve"
ACTION_NONE = "none"


@dataclass(frozen=True)
class Transition:
    """The decision for one evaluation of one rule.

    ``action`` is one of :data:`ACTION_OPEN` (insert one alert), :data:`ACTION_KEEP`
    (update the existing open alert, create nothing), :data:`ACTION_RESOLVE`
    (close the existing open alert) or :data:`ACTION_NONE` (nothing to do).
    """

    action: str
    fires: bool
    has_open_alert: bool

    @property
    def inserts(self) -> bool:
        return self.action == ACTION_OPEN


def plan_transition(has_open_alert: bool, fires: bool) -> Transition:
    """Return the transition for a single evaluation. Pure and total.

    The four cases, in order:
        fires, no open alert   -> open
        fires, open alert      -> keep
        not fires, open alert  -> resolve
        not fires, no alert    -> none
    A rule that stays true therefore never yields ``open`` twice without an
    intervening ``resolve``.
    """
    if fires and not has_open_alert:
        return Transition(ACTION_OPEN, True, False)
    if fires:
        return Transition(ACTION_KEEP, True, True)
    if has_open_alert:
        return Transition(ACTION_RESOLVE, False, True)
    return Transition(ACTION_NONE, False, False)


# Sentinel for "an alert row has been reserved for insert but has no id yet".
# It is not None, so ``has_open_alert`` is already True on the very next
# observe() -- the machine cannot be talked into opening a second alert.
_PENDING_OPEN = -1


class AlertStateMachine:
    """Tracks the single open alert of one rule across evaluations.

    Holds no database handle and no clock: feed it the boolean result of an
    evaluation and it tells the caller what to do. The only way to get a second
    :data:`ACTION_OPEN` out is to pass through :data:`ACTION_RESOLVE` (or start
    over), which is exactly the invariant the service relies on.

    After an :data:`ACTION_OPEN` the caller inserts the row and calls
    :meth:`bind` with the new id.
    """

    def __init__(self, open_alert_id: Optional[int] = None) -> None:
        self._open_alert_id: Optional[int] = (
            None if open_alert_id is None else int(open_alert_id)
        )

    @property
    def has_open_alert(self) -> bool:
        """True while a row is open, including one reserved but not yet bound."""
        return self._open_alert_id is not None

    @property
    def current_alert_id(self) -> Optional[int]:
        """The id of the open alert, or None when none is open or pending."""
        if self._open_alert_id is None or self._open_alert_id == _PENDING_OPEN:
            return None
        return self._open_alert_id

    def observe(self, fires: bool) -> Transition:
        """Apply one evaluation result and return the transition it implies."""
        transition = plan_transition(self.has_open_alert, fires)
        if transition.action == ACTION_OPEN:
            self._open_alert_id = _PENDING_OPEN
        elif transition.action == ACTION_RESOLVE:
            self._open_alert_id = None
        return transition

    def bind(self, alert_id: int) -> None:
        """Attach the real id of the alert row just inserted for ACTION_OPEN."""
        if self._open_alert_id != _PENDING_OPEN:
            raise AlertStateMachineError(
                "bind() is only valid immediately after an open transition."
            )
        self._open_alert_id = int(alert_id)
