"""Decision engine endpoints.

* ``POST /decision/recommend {subject}`` — compute + persist a case.
* ``GET /decision/cases`` — read-only history headers.
* ``GET /decision/cases/{id}`` — read-only case detail.
* ``POST /decision/scenarios/run {type, params, subject}`` — compute only.
* ``POST /decision/cases/{id}/audit {actor, decision, rationale}`` — append.

Service-key auth, engine envelope ``{"success": true, "data": ...}``,
``AppError`` on failures. GETs never write.
"""
from __future__ import annotations

from typing import Any, Dict, Optional

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.core.errors import AppError
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.decision import AuditIn, RecommendIn, ScenarioRunIn, SubjectIn

import app.decision.models  # noqa: F401  (register mappers on Base)

router = APIRouter(tags=["decision"])

_MODULE = "decision"


def _subject_dict(subject: SubjectIn | Dict[str, Any] | None) -> Dict[str, Any]:
    if subject is None:
        return {}
    if isinstance(subject, dict):
        return dict(subject)
    return subject.model_dump()


@router.post("/decision/recommend")
def recommend_case(body: RecommendIn, db: Session = Depends(get_db),
                   _: str = Depends(require_service_auth)) -> dict:
    from app.decision import recommendations as rec

    try:
        result = rec.recommend(_subject_dict(body.subject), db_session=db)
    except ValueError as exc:
        raise AppError(module=_MODULE, operation="recommend",
                       error_type="validation", message=str(exc),
                       status_code=422, code="DECISION_VALIDATION") from exc
    return {"success": True, "data": result}


@router.get("/decision/cases")
def list_decision_cases(limit: int = Query(default=50, ge=1, le=200),
                        db: Session = Depends(get_db),
                        _: str = Depends(require_service_auth)) -> dict:
    from app.decision import recommendations as rec

    return {"success": True, "data": rec.list_cases(db, limit)}


@router.get("/decision/cases/{case_id}")
def get_decision_case(case_id: int, db: Session = Depends(get_db),
                      _: str = Depends(require_service_auth)) -> dict:
    from app.decision import recommendations as rec

    detail = rec.get_case(db, case_id)
    if detail is None:
        raise AppError(module=_MODULE, operation="get_case",
                       error_type="not_found", message=f"decision case {case_id} not found",
                       status_code=404, code="DECISION_NOT_FOUND")
    return {"success": True, "data": detail}


@router.post("/decision/scenarios/run")
def run_decision_scenario(body: ScenarioRunIn, db: Session = Depends(get_db),
                          _: str = Depends(require_service_auth)) -> dict:
    from app.decision import scenarios as sc

    try:
        result = sc.run_scenario(body.type, dict(body.params or {}),
                                 _subject_dict(body.subject), db_session=db)
    except ValueError as exc:
        raise AppError(module=_MODULE, operation="run_scenario",
                       error_type="validation", message=str(exc),
                       status_code=422, code="DECISION_VALIDATION") from exc
    return {"success": True, "data": result}


@router.post("/decision/cases/{case_id}/audit", status_code=201)
def audit_decision_case(case_id: int, body: AuditIn,
                        db: Session = Depends(get_db),
                        _: str = Depends(require_service_auth)) -> dict:
    from app.decision import recommendations as rec

    try:
        row = rec.add_audit(db, case_id, body.actor, body.decision, body.rationale)
    except ValueError as exc:
        raise AppError(module=_MODULE, operation="audit_case",
                       error_type="validation", message=str(exc),
                       status_code=422, code="DECISION_VALIDATION") from exc
    if row is None:
        raise AppError(module=_MODULE, operation="audit_case",
                       error_type="not_found", message=f"decision case {case_id} not found",
                       status_code=404, code="DECISION_NOT_FOUND")
    return {"success": True, "data": row}


@router.get("/decision/rules")
def list_decision_rules(_: str = Depends(require_service_auth)) -> dict:
    from app.decision import rules as ru

    return {"success": True, "data": {
        "version": ru.RULES_VERSION, "rules": ru.list_rules()}}
