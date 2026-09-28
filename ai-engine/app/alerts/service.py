"""Alerting service: rule CRUD, alert lifecycle, evaluation orchestration.

Thin on purpose. Every function takes a synchronous ``Session`` and returns
plain JSON-safe dicts, so a router is a ~20-line adapter over :func:`list_rules`,
:func:`create_rule`, :func:`update_rule`, :func:`delete_rule`,
:func:`list_alerts`, :func:`acknowledge_alert`, :func:`list_events` and
:func:`catalog`; the periodic Celery task is a one-line call to
:func:`evaluate_all`.

Transaction contract, matching ``app/database/connection.py``:

* From a router, ``db`` comes from ``get_db``, which commits when the route
  returns, rolls back if it raises and always closes. Nothing here commits.
* From a worker, the caller wraps the call in ``session_scope()``, which has the
  same commit/rollback/close contract. See :func:`evaluate_alerts`.
* :func:`evaluate_all` isolates each rule in a SAVEPOINT so one rule that blows
  up cannot roll back the alerts the rules before it already opened.

Only the columns declared in ``app/database/models.py`` are written:
``alert_rules`` (name, metric, condition, threshold, is_active) by the CRUD
functions, ``alerts`` (rule_id, severity, message, status) by the lifecycle
functions, ``alert_events`` (alert_id, event_type, payload) by
:func:`_record_event`.
"""
from __future__ import annotations

import logging
import threading
from datetime import datetime, timezone
from typing import Any, Dict, List, Mapping, Optional

from sqlalchemy.orm import Session

from app.alerts import notifiers as notifier
from app.alerts.rules import (
    ACTION_KEEP,
    ACTION_NONE,
    ACTION_OPEN,
    ACTION_RESOLVE,
    EVALUATION_WINDOW_DAYS,
    EVENT_ACKNOWLEDGED,
    EVENT_FIRED,
    EVENT_NOTIFIED,
    EVENT_RESOLVED,
    OPEN_STATUSES,
    OPERATORS,
    SEVERITIES,
    STATUS_ACKNOWLEDGED,
    STATUS_OPEN,
    STATUS_RESOLVED,
    AlertConfigurationError,
    AlertStateMachine,
    AlertStateMachineError,
    MetricData,
    MetricSpec,
    apply_operator,
    build_metric_data,
    clip_metric,
    coerce_threshold,
    compose_message,
    metric_catalog,
    normalize_condition,
    require_metric,
    resolve_metric,
    resolve_severity,
)
from app.core.errors import redact_secrets
from app.database.models import Alert, AlertEvent, AlertRule
from app.workers.celery_app import celery_app

__all__ = [
    "MAX_LIMIT",
    "RULE_FIELDS",
    "acknowledge_alert",
    "alert_to_dict",
    "catalog",
    "create_rule",
    "delete_rule",
    "evaluate_all",
    "evaluate_alerts",
    "evaluate_rule",
    "event_to_dict",
    "get_rule",
    "list_alerts",
    "list_events",
    "list_rules",
    "rule_to_dict",
    "update_rule",
]

log = logging.getLogger("app.alerts.service")

MAX_LIMIT = 200
# alert_rules.name is String(128); alert_events.payload notes are free text.
RULE_NAME_MAX_CHARS = 128
NOTE_MAX_CHARS = 480
ACTOR_MAX_CHARS = 128
# Keys accepted by update_rule(); anything else is a configuration error rather
# than a silently ignored field.
RULE_FIELDS = ("name", "metric", "condition", "operator", "threshold", "is_active")
ALL_STATUSES = (STATUS_OPEN, STATUS_ACKNOWLEDGED, STATUS_RESOLVED)

# Per-rule mutex. Two evaluations of the same rule inside one process (an
# impatient beat schedule, an operator hitting "run now" while beat is running)
# would otherwise both read "no open alert" and both insert. The database has no
# unique index on (rule_id) WHERE status IN ('open','acknowledged'), so this is
# the only thing standing between them and a duplicate open alert within one
# process.
_RULE_LOCKS: Dict[int, threading.Lock] = {}
_RULE_LOCKS_GUARD = threading.Lock()


def _rule_lock(rule_id: int) -> threading.Lock:
    """Return the process-wide lock for one rule id.

    One entry per rule, pruned in :func:`delete_rule`, so the map cannot outgrow
    the number of rows in ``alert_rules``.
    """
    with _RULE_LOCKS_GUARD:
        lock = _RULE_LOCKS.get(rule_id)
        if lock is None:
            lock = threading.Lock()
            _RULE_LOCKS[rule_id] = lock
        return lock


def _now() -> datetime:
    """Current UTC time, matching ``models._utcnow``."""
    return datetime.now(timezone.utc)


def _iso(value: Any) -> Optional[str]:
    """Return ``value`` as an ISO-8601 string, or None when unset."""
    return value.isoformat() if isinstance(value, datetime) else None


def _fmt(value: Any, digits: int = 4) -> float:
    """Return ``value`` as a finite float, falling back to 0.0.

    Guards the JSON payload: ``json.dumps`` happily emits ``NaN`` and
    ``Infinity``, which are not valid JSON and break strict clients.
    """
    try:
        number = float(value)
    except (TypeError, ValueError):
        return 0.0
    if number != number or number in (float("inf"), float("-inf")):
        return 0.0
    return round(number, digits)


def _printable(value: Any, limit: int) -> str:
    """Return ``value`` as a single printable string no longer than ``limit``."""
    return "".join(ch for ch in str(value or "") if ch.isprintable())[:limit]


def _safe_name(name: Any) -> str:
    """Clip and redact the operator-supplied rule name for a JSON payload."""
    return redact_secrets(_printable(name, RULE_NAME_MAX_CHARS))


# --------------------------------------------------------------------------
# serialisation
# --------------------------------------------------------------------------
def rule_to_dict(rule: AlertRule) -> Dict[str, Any]:
    """Return the API shape of an ``alert_rules`` row.

    Keys: id, name, metric, condition, operator, threshold, is_active, severity,
    unit, created_at, updated_at. ``operator`` is the canonical symbol derived
    from ``condition`` (an unreadable stored value is passed through, never
    raised, so one bad row cannot break the rule list). ``severity`` and
    ``unit`` come from the metric registry -- the rule table has no severity
    column -- and are None for a metric the registry does not know.
    """
    spec = resolve_metric(rule.metric)
    condition = str(rule.condition or "")
    return {
        "id": rule.id,
        "name": rule.name,
        "metric": rule.metric,
        "condition": condition,
        "operator": OPERATORS.get(condition.strip().lower(), condition),
        "threshold": _fmt(rule.threshold),
        "is_active": bool(rule.is_active),
        "severity": resolve_severity(spec) if spec else None,
        "unit": spec.unit if spec else None,
        "created_at": _iso(rule.created_at),
        "updated_at": _iso(rule.updated_at),
    }


def alert_to_dict(alert: Alert) -> Dict[str, Any]:
    """Return the API shape of an ``alerts`` row.

    Keys: id, rule_id, severity, message, status, triggered_at, last_observed_at,
    resolved_at, created_at, updated_at.

    The table has no ``triggered_at`` / ``resolved_at`` column, so those two
    names are projections of the ``TimestampMixin`` pair: ``created_at`` is when
    the alert opened, and ``updated_at`` is the resolution moment for a resolved
    alert or the last observation of a still-firing one.
    """
    resolved = alert.status == STATUS_RESOLVED
    return {
        "id": alert.id,
        "rule_id": alert.rule_id,
        "severity": alert.severity,
        "message": alert.message,
        "status": alert.status,
        "triggered_at": _iso(alert.created_at),
        "last_observed_at": _iso(alert.updated_at),
        "resolved_at": _iso(alert.updated_at) if resolved else None,
        "created_at": _iso(alert.created_at),
        "updated_at": _iso(alert.updated_at),
    }


def event_to_dict(event: AlertEvent) -> Dict[str, Any]:
    """Return the API shape of an ``alert_events`` row.

    Keys: id, alert_id, event_type, payload, created_at, updated_at.
    """
    return {
        "id": event.id,
        "alert_id": event.alert_id,
        "event_type": event.event_type,
        "payload": event.payload or {},
        "created_at": _iso(event.created_at),
        "updated_at": _iso(event.updated_at),
    }


# --------------------------------------------------------------------------
# rule CRUD
# --------------------------------------------------------------------------
def list_rules(db: Session, *, active_only: bool = False) -> List[Dict[str, Any]]:
    """Return every rule, oldest first, as :func:`rule_to_dict` dicts.

    ``active_only`` is the filter the evaluation loop applies to itself. An
    unreadable stored condition is surfaced as-is rather than raised, so one
    broken rule does not hide the other rules from the admin list.
    """
    query = db.query(AlertRule)
    if active_only:
        query = query.filter(AlertRule.is_active.is_(True))
    return [rule_to_dict(r) for r in query.order_by(AlertRule.id.asc()).all()]


def get_rule(db: Session, rule_id: int) -> Optional[Dict[str, Any]]:
    """Return one rule as a dict, or None when there is no such id."""
    rule = db.query(AlertRule).filter(AlertRule.id == rule_id).first()
    return rule_to_dict(rule) if rule is not None else None


def create_rule(db: Session, *, name: Any, metric: Any, operator: Any,
                threshold: Any, is_active: bool = True) -> Dict[str, Any]:
    """Insert one rule and return it as a dict. The session is not committed.

    Raises :class:`~app.alerts.rules.AlertConfigurationError` for an empty name,
    an unknown metric, an unsupported operator or a non-finite threshold, so a
    rule that could never be evaluated is never stored.
    """
    clean = _printable(name, RULE_NAME_MAX_CHARS)
    if not clean:
        raise AlertConfigurationError(
            "Alert rule name is required.",
            operation="create_rule",
            error_type="invalid_name",
            code="ALERT_INVALID_NAME",
        )
    spec = require_metric(metric)
    rule = AlertRule(
        name=clean,
        metric=spec.key,
        condition=normalize_condition(operator),
        threshold=coerce_threshold(threshold),
        is_active=bool(is_active),
    )
    db.add(rule)
    db.flush()
    return rule_to_dict(rule)


def update_rule(db: Session, rule_id: int,
                changes: Mapping[str, Any]) -> Optional[Dict[str, Any]]:
    """Apply ``changes`` to one rule and return it, or None when it is missing.

    ``changes`` accepts only the keys in :data:`RULE_FIELDS` (``operator`` is an
    alias of ``condition``); an unknown key is a configuration error, not a
    silently dropped field. The session is not committed.
    """
    rule = db.query(AlertRule).filter(AlertRule.id == rule_id).first()
    if rule is None:
        return None
    unknown = sorted(k for k in changes if k not in RULE_FIELDS)
    if unknown:
        raise AlertConfigurationError(
            f"Unknown alert rule field(s): {', '.join(unknown)}.",
            operation="update_rule",
            error_type="unknown_field",
            code="ALERT_UNKNOWN_FIELD",
            details={"rule_id": rule_id, "supported": list(RULE_FIELDS)},
        )
    if "name" in changes:
        clean = _printable(changes["name"], RULE_NAME_MAX_CHARS)
        if not clean:
            raise AlertConfigurationError(
                "Alert rule name is required.",
                operation="update_rule",
                error_type="invalid_name",
                code="ALERT_INVALID_NAME",
                details={"rule_id": rule_id},
            )
        rule.name = clean
    if "metric" in changes:
        rule.metric = require_metric(changes["metric"], rule_id=rule.id).key
    if "condition" in changes or "operator" in changes:
        raw = changes.get("condition", changes.get("operator"))
        rule.condition = normalize_condition(raw, rule_id=rule.id)
    if "threshold" in changes:
        rule.threshold = coerce_threshold(changes["threshold"], rule_id=rule.id)
    if "is_active" in changes:
        rule.is_active = bool(changes["is_active"])
    db.flush()
    return rule_to_dict(rule)


def delete_rule(db: Session, rule_id: int) -> bool:
    """Delete one rule. True when a row was removed, False when absent.

    Refuses while any ``alerts`` row references the rule: the foreign key has no
    ON DELETE CASCADE, so the delete would fail at the database with an opaque
    IntegrityError, and those alert rows are the audit trail. Resolved alerts
    still block it -- the schema cannot detach them, which is a real limitation
    of ``alert_rules``/``alerts`` as migrated.
    """
    rule = db.query(AlertRule).filter(AlertRule.id == rule_id).first()
    if rule is None:
        return False
    linked = db.query(Alert.id).filter(Alert.rule_id == rule_id).count()
    if linked:
        raise AlertConfigurationError(
            f"Alert rule {rule_id} still has {linked} alert(s) and cannot be "
            f"deleted; that history is kept on purpose.",
            operation="delete_rule",
            error_type="rule_in_use",
            code="ALERT_RULE_IN_USE",
            status_code=409,
            details={"rule_id": rule_id, "alerts": linked},
        )
    db.delete(rule)
    db.flush()
    with _RULE_LOCKS_GUARD:
        _RULE_LOCKS.pop(rule_id, None)
    return True


# --------------------------------------------------------------------------
# alert listing / acknowledgement
# --------------------------------------------------------------------------
def list_alerts(db: Session, *, status: Optional[str] = None,
                rule_id: Optional[int] = None, limit: int = 50) -> List[Dict[str, Any]]:
    """Return alerts newest first, as :func:`alert_to_dict` dicts.

    ``status`` must be open/acknowledged/resolved or None; anything else is a
    configuration error. ``limit`` is clamped to [1, ``MAX_LIMIT``].
    """
    query = db.query(Alert)
    if status is not None:
        wanted = str(status).strip().lower()
        if wanted not in ALL_STATUSES:
            raise AlertConfigurationError(
                f"Unknown alert status {clip_metric(status)!r}.",
                operation="list_alerts",
                error_type="unknown_status",
                code="ALERT_UNKNOWN_STATUS",
                details={"supported": list(ALL_STATUSES)},
            )
        query = query.filter(Alert.status == wanted)
    if rule_id is not None:
        query = query.filter(Alert.rule_id == rule_id)
    try:
        capped = max(1, min(int(limit), MAX_LIMIT))
    except (TypeError, ValueError):
        capped = 50
    rows = query.order_by(Alert.id.desc()).limit(capped).all()
    return [alert_to_dict(a) for a in rows]


def list_events(db: Session, alert_id: int) -> List[Dict[str, Any]]:
    """Return one alert's history oldest first. Empty list for an unknown id."""
    rows = (db.query(AlertEvent)
            .filter(AlertEvent.alert_id == alert_id)
            .order_by(AlertEvent.id.asc())
            .all())
    return [event_to_dict(e) for e in rows]


def acknowledge_alert(db: Session, alert_id: int, *, actor: str,
                      note: str = "") -> Optional[Dict[str, Any]]:
    """Mark an open alert acknowledged and return it, or None when absent.

    Acknowledging is not resolving: the alert stays un-resolved, so
    :data:`~app.alerts.rules.OPEN_STATUSES` still matches it and the next
    evaluation updates that same row instead of opening a second one. Raises a
    409 :class:`~app.alerts.rules.AlertConfigurationError` when the alert exists
    but is already resolved, so a router can map "missing" to 404 and "not
    open" to 409 without a second query.
    """
    alert = db.query(Alert).filter(Alert.id == alert_id).first()
    if alert is None:
        return None
    if alert.status not in OPEN_STATUSES:
        raise AlertConfigurationError(
            f"Alert {alert_id} is already {alert.status} and cannot be acknowledged.",
            operation="acknowledge_alert",
            error_type="alert_not_open",
            code="ALERT_NOT_OPEN",
            status_code=409,
            details={"alert_id": alert_id, "status": alert.status},
        )
    before = alert.status
    now = _now()
    alert.status = STATUS_ACKNOWLEDGED
    alert.updated_at = now
    _record_event(db, alert, EVENT_ACKNOWLEDGED, {
        "actor": _printable(actor, ACTOR_MAX_CHARS),
        "note": _printable(note, NOTE_MAX_CHARS),
        "status_before": before,
        "status_after": STATUS_ACKNOWLEDGED,
        "at": _iso(now),
    })
    db.flush()
    return alert_to_dict(alert)


# --------------------------------------------------------------------------
# evaluation
# --------------------------------------------------------------------------
def _open_alert(db: Session, rule_id: int) -> Optional[Alert]:
    """Return the single un-resolved alert of a rule, or None.

    Ordered by id and locked FOR UPDATE so two evaluations in different
    processes serialise on the row instead of both writing it. There is at most
    one by construction; should duplicates exist anyway, the oldest wins and the
    rest are left visible for the operator rather than deleted by the evaluator.
    """
    return (db.query(Alert)
            .filter(Alert.rule_id == rule_id, Alert.status.in_(OPEN_STATUSES))
            .order_by(Alert.id.asc())
            .with_for_update()
            .first())


def _record_event(db: Session, alert: Alert, event_type: str,
                  payload: Mapping[str, Any]) -> AlertEvent:
    """Append one row to ``alert_events`` and return it (not flushed)."""
    event = AlertEvent(alert_id=alert.id, event_type=event_type, payload=dict(payload))
    db.add(event)
    return event


def _observation(rule: AlertRule, spec: MetricSpec, value: float, condition: str,
                 threshold: float, window_days: int) -> Dict[str, Any]:
    """Return the JSON-safe detail stored in an event payload.

    Carries the rule identity, the comparison and the observed value, which is
    what "who was paged, about what, and when" needs. The rule name is clipped
    and redacted because it is free-text operator input.
    """
    return {
        "rule_id": rule.id,
        "rule_name": _safe_name(rule.name),
        "metric": spec.key,
        "metric_source": spec.source,
        "condition": condition,
        "threshold": _fmt(threshold),
        "value": _fmt(value),
        "severity": resolve_severity(spec),
        "window_days": window_days,
    }


def _deliver(db: Session, alert: Alert, event_name: str,
             detail: Mapping[str, Any]) -> Dict[str, Any]:
    """Notify a channel and record the attempt.

    An ``alert_events`` row of type ``notified`` is written only when a
    delivery actually succeeded -- a channel that is off or a webhook that
    failed must never leave a row claiming a page went out. A failure is logged
    with the metric name only and never raises into the evaluation loop.
    """
    result = notifier.notify(event_name, detail)
    if result.sent:
        _record_event(db, alert, EVENT_NOTIFIED, {
            "channel": result.channel,
            "detail": result.detail,
            "at": _iso(_now()),
        })
        db.flush()
    elif result.detail == notifier.FAILED:
        log.warning("alert %s (%s) could not be delivered: %s",
                    alert.id, detail.get("metric"), result.detail)
    return result.as_payload()


def evaluate_rule(db: Session, rule: AlertRule, *,
                  data: Optional[MetricData] = None) -> Dict[str, Any]:
    """Evaluate one rule against the warehouse and apply the state machine.

    Returns a dict with keys: rule_id, metric, operator, threshold, value, fired,
    action, alert_id, event_id, delivered, delivery. ``action`` is one of
    open/keep/resolve/none (see :func:`~app.alerts.rules.plan_transition`).

    Raises :class:`~app.alerts.rules.AlertConfigurationError` when the rule's
    metric or operator is unknown -- the caller decides whether to record the
    failure and continue (as :func:`evaluate_all` does) or surface it.
    """
    spec = require_metric(rule.metric, rule_id=rule.id)
    condition = normalize_condition(rule.condition, rule_id=rule.id)
    threshold = coerce_threshold(rule.threshold, rule_id=rule.id)
    frame = data if data is not None else build_metric_data(db)
    value = float(spec.compute(frame))
    fired = apply_operator(value, condition, threshold)
    window_days = int(frame.window_days)

    with _rule_lock(rule.id):
        current = _open_alert(db, rule.id)
        machine = AlertStateMachine(current.id if current is not None else None)
        transition = machine.observe(fired)
        now = _now()
        detail = _observation(rule, spec, value, condition, threshold, window_days)
        detail["action"] = transition.action
        detail["at"] = _iso(now)
        message = compose_message(rule.name, spec, value, condition, threshold,
                                  window_days)

        alert: Optional[Alert] = current
        event: Optional[AlertEvent] = None
        delivery: Optional[Dict[str, Any]] = None

        if transition.action == ACTION_OPEN:
            alert = Alert(rule_id=rule.id, severity=resolve_severity(spec),
                          message=message, status=STATUS_OPEN)
            db.add(alert)
            db.flush()
            machine.bind(alert.id)
            event = _record_event(db, alert, EVENT_FIRED, detail)
            delivery = _deliver(db, alert, EVENT_FIRED, detail)
        elif transition.action == ACTION_KEEP:
            # Still true: refresh the one open row so its message and
            # updated_at reflect the latest observation, and write no event. An
            # event per evaluation would turn the history table into the
            # firehose the dedup exists to prevent.
            alert = _require_alert(current, transition)
            alert.message = message
            alert.updated_at = now
        elif transition.action == ACTION_RESOLVE:
            alert = _require_alert(current, transition)
            detail["status_before"] = alert.status
            alert.status = STATUS_RESOLVED
            alert.updated_at = now
            event = _record_event(db, alert, EVENT_RESOLVED, detail)
            delivery = _deliver(db, alert, EVENT_RESOLVED, detail)
        else:  # ACTION_NONE: nothing was open and nothing fired
            detail["alert_id"] = None

        db.flush()
        return {
            "rule_id": rule.id,
            "metric": spec.key,
            "operator": condition,
            "threshold": _fmt(threshold),
            "value": _fmt(value),
            "fired": bool(fired),
            "action": transition.action,
            "alert_id": alert.id if alert is not None else None,
            "event_id": event.id if event is not None else None,
            "delivered": bool(delivery["sent"]) if delivery else False,
            "delivery": delivery,
        }


def _require_alert(alert: Optional[Alert], transition: Any) -> Alert:
    """Return ``alert``, or raise when the transition claimed there was one."""
    if alert is None:
        raise AlertStateMachineError(
            f"Transition {transition.action} requires an open alert row."
        )
    return alert


def _redacted_error(exc: BaseException) -> str:
    """Return a log-safe one-line description of ``exc``.

    Reuses the AI tool layer's redactor, which drops embedded SQL statements
    (they carry bound parameters) and anything DSN-shaped.
    """
    from app.ai.tools import redact

    return redact(f"{type(exc).__name__}: {exc}")


def evaluate_all(db: Session, *, window_days: int = EVALUATION_WINDOW_DAYS) -> Dict[str, Any]:
    """Evaluate every active rule once. Returns a summary dict.

    Keys: evaluated_at, window_days, evaluated, opened, kept, resolved, noops,
    inactive, rules, errors. ``rules`` holds one :func:`evaluate_rule` result
    per rule that ran; ``errors`` holds one entry per rule that could not be
    evaluated (rule_id, metric, code, message).

    One set of warehouse frames is loaded for the whole run rather than per
    rule. Each rule runs inside a SAVEPOINT, so a rule that raises cannot roll
    back the alerts the rules before it already opened. A rule that cannot be
    evaluated -- an unknown metric left behind by a hand-edited row -- is
    recorded in ``errors`` and skipped: never counted as a pass, never allowed
    to stop the others.
    """
    started = _now()
    rules = (db.query(AlertRule)
             .filter(AlertRule.is_active.is_(True))
             .order_by(AlertRule.id.asc())
             .all())
    inactive = db.query(AlertRule).filter(AlertRule.is_active.is_(False)).count()
    data = build_metric_data(db, window_days) if rules else None

    results: List[Dict[str, Any]] = []
    errors: List[Dict[str, Any]] = []
    counts: Dict[str, int] = {ACTION_OPEN: 0, ACTION_KEEP: 0,
                              ACTION_RESOLVE: 0, ACTION_NONE: 0}
    for rule in rules:
        savepoint = db.begin_nested()
        try:
            result = evaluate_rule(db, rule, data=data)
        except Exception as exc:  # one broken rule must not stop the run
            savepoint.rollback()
            entry = {
                "rule_id": rule.id,
                "metric": clip_metric(rule.metric),
                "code": (exc.code if isinstance(exc, AlertConfigurationError)
                         else "ALERT_EVALUATION_FAILED"),
                "message": (exc.message if isinstance(exc, AlertConfigurationError)
                            else _redacted_error(exc)),
            }
            errors.append(entry)
            log.warning("alert rule %s skipped: %s", rule.id, entry["message"])
            continue
        savepoint.commit()
        results.append(result)
        counts[result["action"]] = counts.get(result["action"], 0) + 1

    return {
        "evaluated_at": _iso(started),
        "window_days": int(window_days),
        "evaluated": len(results),
        "opened": counts[ACTION_OPEN],
        "kept": counts[ACTION_KEEP],
        "resolved": counts[ACTION_RESOLVE],
        "noops": counts[ACTION_NONE],
        "inactive": int(inactive),
        "rules": results,
        "errors": errors,
    }


def catalog() -> Dict[str, Any]:
    """Return everything a client needs to build a valid rule form.

    Keys: metrics, operators, severities, statuses, window_days. No database
    access, so it is safe to cache.
    """
    return {
        "metrics": metric_catalog(),
        "operators": sorted(set(OPERATORS.values())),
        "severities": list(SEVERITIES),
        "statuses": list(ALL_STATUSES),
        "window_days": EVALUATION_WINDOW_DAYS,
    }


# --------------------------------------------------------------------------
# periodic job
# --------------------------------------------------------------------------
@celery_app.task(name="app.alerts.service.evaluate_alerts")
def evaluate_alerts() -> Dict[str, Any]:
    """Celery entry point: evaluate every active rule once.

    ``session_scope`` opens the session, commits on success, rolls back on
    failure and always closes it. Returns the :func:`evaluate_all` summary, so a
    run is inspectable in the result backend.
    """
    from app.database.connection import session_scope

    with session_scope() as db:
        return evaluate_all(db)
