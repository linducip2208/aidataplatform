"""Alerting endpoints: threshold rules, the alerts they open, and their history.

A thin adapter over :mod:`app.alerts.service`. The service owns the transition
logic and returns plain JSON-safe dicts; nothing here queries the ORM directly
or decides a status code beyond the documented 404/409 cases the service signals
through :class:`~app.alerts.rules.AlertConfigurationError`.

Status codes are split by *kind* of problem, which is the contract
``docs/api.md`` documents:

* **422** -- the request body is the wrong shape (not an object, a required
  field missing, a field of the wrong type). FastAPI produces these from the
  body models below, before any service call.
* **400** -- the body is well formed but the rule is a configuration error:
  unknown metric, unsupported operator, non-finite threshold. These come from
  the service and are the caller's to fix by changing the values.
* **404** -- the addressed rule or alert does not exist.
* **409** -- the addressed row exists but is not in a state that allows the
  operation (deleting a rule that has alerts, acknowledging a resolved alert).
* **401** -- no service key.
"""
from __future__ import annotations

from typing import Any, Dict, Optional

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Response, status
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.alerts import service
from app.alerts.rules import AlertConfigurationError
from app.core.errors import build_error_response
from app.core.security import require_service_auth
from app.database.connection import get_db

router = APIRouter(tags=["alerts"], prefix="/alerts")


def _ok(data: Any, code: int = status.HTTP_200_OK) -> Dict[str, Any]:
    return {"success": True, "data": data}


class RuleCreate(BaseModel):
    """Body of ``POST /alerts/rules``.

    Declared as a model rather than a bare ``dict`` so a missing or mistyped
    field is a 422 from FastAPI and never reaches the service. ``condition`` is
    accepted as an alias of ``operator``; whichever is present wins, and
    ``operator`` is required either way, because a rule with no comparison is not
    a rule.

    ``extra="forbid"`` is load-bearing: this build has no column for a per-rule
    ``severity``, ``branch`` or ``window_days``, and a silently ignored key
    would be read as "applied". Forbidding extras turns that into a 422 naming
    the field. PATCH keeps extras and routes them through the service, which
    gives the richer ``ALERT_UNSUPPORTED_FIELD`` / ``ALERT_UNKNOWN_FIELD``
    distinction ``docs/api.md`` documents.
    """

    model_config = {"extra": "forbid"}

    name: str = Field(..., min_length=1, max_length=128,
                      description="Human label, copied into alerts.message.")
    metric: str = Field(..., max_length=64, description="Metric key from /alerts/metrics.")
    operator: Optional[str] = Field(default=None, max_length=16,
                                    description=">, >=, <, <=, ==, != (aliases accepted).")
    condition: Optional[str] = Field(default=None, max_length=16,
                                     description="Alias of operator.")
    threshold: float = Field(..., description="Comparison value; must be finite.")
    is_active: bool = Field(default=True)

    def operator_symbol(self) -> str:
        """Return whichever of operator/condition the client actually sent."""
        return self.operator if self.operator is not None else self.condition


class RuleUpdate(BaseModel):
    """Body of ``PATCH /alerts/rules/{id}``.

    Every field is optional, but the payload must not be empty: a no-op PATCH
    that reports success is indistinguishable from a bug. An unknown key is
    rejected by the service (:data:`~app.alerts.service.RULE_FIELDS`), so a
    caller who sent ``severity`` is told the table has no such column instead of
    watching it be ignored.
    """

    model_config = {"extra": "allow"}

    name: Optional[str] = Field(default=None, max_length=128)
    metric: Optional[str] = Field(default=None, max_length=64)
    operator: Optional[str] = Field(default=None, max_length=16)
    condition: Optional[str] = Field(default=None, max_length=16)
    threshold: Optional[float] = None
    is_active: Optional[bool] = None


@router.get("/metrics")
def list_metrics(_: str = Depends(require_service_auth)) -> Dict[str, Any]:
    """The metrics a rule can be written against, with their operators."""
    return _ok(service.catalog())


@router.get("/rules")
def list_rules(
    active_only: bool = Query(default=False),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    return _ok(service.list_rules(db, active_only=active_only))


@router.post("/rules", status_code=status.HTTP_201_CREATED)
def create_rule(
    payload: RuleCreate,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    """Create one rule. An unknown metric or operator is a 400, not a stored rule."""
    return _ok(_guard(lambda: service.create_rule(
        db,
        name=payload.name,
        metric=payload.metric,
        operator=payload.operator_symbol(),
        threshold=payload.threshold,
        is_active=payload.is_active,
    )))


@router.get("/rules/{rule_id}")
def get_rule(
    rule_id: int,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    rule = service.get_rule(db, rule_id)
    if rule is None:
        raise HTTPException(status_code=404, detail=f"Alert rule {rule_id} not found")
    return _ok(rule)


@router.patch("/rules/{rule_id}")
def update_rule(
    rule_id: int,
    payload: RuleUpdate = Body(...),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    """Apply a partial update. A missing rule is a 404, not an empty 200."""
    changes = payload.model_dump(exclude_unset=True, exclude_none=True)
    updated = _guard(lambda: service.update_rule(db, rule_id, changes))
    if updated is None:
        raise HTTPException(status_code=404, detail=f"Alert rule {rule_id} not found")
    return _ok(updated)


@router.delete("/rules/{rule_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_rule(
    rule_id: int,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Response:
    if not _guard(lambda: service.delete_rule(db, rule_id)):
        raise HTTPException(status_code=404, detail=f"Alert rule {rule_id} not found")
    return Response(status_code=status.HTTP_204_NO_CONTENT)


@router.get("")
def list_alerts(
    status_filter: Optional[str] = Query(default=None, alias="status"),
    rule_id: Optional[int] = Query(default=None),
    limit: int = Query(default=50),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    return _ok(_guard(lambda: service.list_alerts(db, status=status_filter, rule_id=rule_id, limit=limit)))


@router.get("/alerts", include_in_schema=False)
def list_alerts_unprefixed(
    status_filter: Optional[str] = Query(default=None, alias="status"),
    rule_id: Optional[int] = Query(default=None),
    limit: int = Query(default=50),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    """Alias for clients that reach for ``/alerts/alerts``."""
    return _ok(_guard(lambda: service.list_alerts(db, status=status_filter, rule_id=rule_id, limit=limit)))


@router.get("/alerts/{alert_id}/events")
def list_alert_events(
    alert_id: int,
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    return _ok(service.list_events(db, alert_id))


@router.post("/alerts/{alert_id}/ack")
def acknowledge_alert(
    alert_id: int,
    payload: Dict[str, Any] = Body(default_factory=dict),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    """Acknowledge an open alert. Acknowledging is not resolving: the alert stays
    open to the state machine, so the next evaluation updates the same row."""
    alert = _guard(lambda: service.acknowledge_alert(
        db, alert_id, actor="api", note=str(payload.get("note") or "")))
    if alert is None:
        raise HTTPException(status_code=404, detail=f"Alert {alert_id} not found")
    return _ok(alert)


def _guard(call):
    """Map a configuration error onto its declared status inside the envelope.

    A 404/409 raised as a bare ``HTTPException`` would be serialised as
    ``{"detail": ...}``, which breaks the ``{"success": false, "error": {...}}``
    contract every other engine endpoint keeps.
    """
    try:
        return call()
    except AlertConfigurationError as exc:
        raise HTTPException(
            status_code=exc.status_code,
            detail=build_error_response(
                module="alerts",
                operation=exc.operation,
                error_type=exc.error_type,
                code=exc.code,
                message=exc.message,
                technical=exc.technical,
                details=exc.details,
            ),
        ) from None
