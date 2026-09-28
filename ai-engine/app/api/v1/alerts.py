"""Alerting endpoints: threshold rules, the alerts they open, and their history.

A thin adapter over :mod:`app.alerts.service`. The service owns the transition
logic and returns plain JSON-safe dicts; nothing here queries the ORM directly
or decides a status code beyond the documented 404/409 cases the service signals
through :class:`~app.alerts.rules.AlertConfigurationError`.
"""
from __future__ import annotations

from typing import Any, Dict, Optional

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Response, status
from sqlalchemy.orm import Session

from app.alerts import service
from app.alerts.rules import AlertConfigurationError
from app.core.errors import build_error_response
from app.core.security import require_service_auth
from app.database.connection import get_db

router = APIRouter(tags=["alerts"], prefix="/alerts")


def _ok(data: Any, code: int = status.HTTP_200_OK) -> Dict[str, Any]:
    return {"success": True, "data": data}


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
    payload: Dict[str, Any] = Body(...),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    """Create one rule. An unknown metric or operator is a 400, not a stored rule."""
    return _ok(_guard(lambda: service.create_rule(
        db,
        name=payload.get("name"),
        metric=payload.get("metric"),
        operator=payload.get("operator", payload.get("condition")),
        threshold=payload.get("threshold"),
        is_active=bool(payload.get("is_active", True)),
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
    payload: Dict[str, Any] = Body(...),
    db: Session = Depends(get_db),
    _: str = Depends(require_service_auth),
) -> Dict[str, Any]:
    return _ok(_guard(lambda: service.update_rule(db, rule_id, payload)) or {})


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
