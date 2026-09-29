"""Enterprise quality endpoints: rules CRUD, evaluate, history, run detail.

Envelope contract: ``{"success": true, "data": ...}`` on success,
``{"success": false, "error": {...}}`` with a real HTTP status on refusal
(same split as the sibling routers: 422 malformed body via pydantic, 400 bad
rule configuration, 404 unknown run/job/profile).

Method semantics: GET is read-only (rules list, history, run detail never
write). POST /quality/rules and POST /quality/evaluate compute + persist.
"""
from __future__ import annotations

from typing import Any, Dict, List, Optional

import pandas as pd
from fastapi import APIRouter, Depends, Query
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_logger, get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.quality import history as _history
from app.quality.profiles import get_profile, validate_profile
from app.quality.rules import RuleValidationError, evaluate, validate_rule

log = get_logger("api.quality", "quality")

router = APIRouter(tags=["quality"])


def _ok(data: Any) -> Dict[str, Any]:
    return {"success": True, "data": data}


def _error(status_code: int, operation: str, message: str, *, code: str,
           error_type: str, details: Dict[str, Any] | None = None) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content=build_error_response(
            module="quality",
            operation=operation,
            error_type=error_type,
            code=code,
            message=message,
            request_id=get_request_id(),
            resolution="Periksa kembali payload / nama resource, lalu ulangi.",
            details=details or {},
        ),
    )


def _bad_request(operation: str, message: str, code: str,
                 details: Dict[str, Any] | None = None) -> JSONResponse:
    return _error(422, operation, message, code=code, error_type="validation",
                  details=details)


# --------------------------------------------------------------------------
# bodies
# --------------------------------------------------------------------------

class RuleCreate(BaseModel):
    model_config = {"extra": "forbid"}

    name: str = Field(..., min_length=1, max_length=128)
    dataset_type: str = Field(default="sales", max_length=64)
    column: Optional[str] = Field(default=None, max_length=256)
    rule_type: str = Field(..., max_length=32)
    params: Dict[str, Any] = Field(default_factory=dict)
    severity: str = Field(default="error", max_length=16)
    active: bool = Field(default=True)


class EvaluateRequest(BaseModel):
    model_config = {"extra": "ignore"}

    dataset_ref: Optional[str] = Field(default=None, max_length=256)
    job_id: Optional[int] = Field(default=None)
    profile: Optional[str] = Field(default=None, max_length=128)
    rules: Optional[List[Dict[str, Any]]] = Field(default=None)
    rows: Optional[List[Dict[str, Any]]] = Field(default=None)


# --------------------------------------------------------------------------
# helpers
# --------------------------------------------------------------------------

def _rule_to_dict(row: Any) -> Dict[str, Any]:
    return {
        "id": row.id,
        "name": row.name,
        "dataset_type": row.dataset_type,
        "column": row.column,
        "rule_type": row.rule_type,
        "params": row.params or {},
        "severity": row.severity,
        "active": bool(row.active),
    }


def _load_frame(job_id: int, db: Session) -> pd.DataFrame:
    from app.database.models import ImportJob, RawUpload
    from app.ingestion.reader import read_full

    job = db.query(ImportJob).filter(ImportJob.id == job_id).first()
    if job is None:
        raise KeyError(f"import job {job_id} not found")
    raw = db.query(RawUpload).filter(RawUpload.id == job.upload_id).first() \
        if job.upload_id else None
    if raw is None:
        raise KeyError(f"upload for import job {job_id} not found")
    return read_full(raw.stored_path)


def _resolve_rules(profile: str | None,
                   ad_hoc: List[Dict[str, Any]] | None) -> List[Dict[str, Any]]:
    rules: List[Dict[str, Any]] = []
    if profile:
        try:
            builtin = get_profile(profile)
        except KeyError as exc:
            raise RuleValidationError(str(exc)) from exc
        rules.extend(validate_profile(builtin)["rules"])
    for raw in ad_hoc or []:
        rules.append(validate_rule(raw))
    if not rules:
        raise RuleValidationError("invalid request: provide profile or a non-empty rules list")
    return rules


# --------------------------------------------------------------------------
# routes
# --------------------------------------------------------------------------

@router.post("/quality/rules", status_code=201)
def create_rule(body: RuleCreate, db: Session = Depends(get_db),
                _: str = Depends(require_service_auth)):
    from app.quality.models import QualityRule

    _history.ensure_tables(db)
    try:
        normalised = validate_rule({"id": body.name.strip(), "column": body.column,
                                    "type": body.rule_type, "params": body.params,
                                    "severity": body.severity})
    except RuleValidationError as exc:
        return _bad_request("rules.create", str(exc), "QUALITY_RULE_INVALID")
    existing = db.query(QualityRule).filter(QualityRule.name == normalised["id"]).first()
    if existing is not None:
        return _error(409, "rules.create",
                      f"quality rule {normalised['id']!r} already exists",
                      code="QUALITY_RULE_EXISTS", error_type="conflict")
    row = QualityRule(
        name=normalised["id"],
        dataset_type=(body.dataset_type or "sales").strip() or "sales",
        column=normalised["column"],
        rule_type=normalised["type"],
        params=normalised["params"],
        severity=normalised["severity"],
        active=bool(body.active),
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    log.info(f"quality rule created: {row.name}")
    return JSONResponse(status_code=201, content=_ok(_rule_to_dict(row)))


@router.get("/quality/rules")
def list_rules(dataset_type: Optional[str] = Query(default=None),
               active: Optional[bool] = Query(default=None),
               rule_type: Optional[str] = Query(default=None),
               db: Session = Depends(get_db),
               _: str = Depends(require_service_auth)) -> dict:
    """Read-only: list stored rules. Never writes."""
    from app.quality.models import QualityRule

    _history.ensure_tables(db)
    query = db.query(QualityRule).order_by(QualityRule.id.asc())
    if dataset_type:
        query = query.filter(QualityRule.dataset_type == dataset_type)
    if active is not None:
        query = query.filter(QualityRule.active == active)
    if rule_type:
        query = query.filter(QualityRule.rule_type == rule_type)
    return _ok([_rule_to_dict(row) for row in query.all()])


@router.post("/quality/evaluate")
def evaluate_dataset(body: EvaluateRequest, db: Session = Depends(get_db),
                     _: str = Depends(require_service_auth)):
    if body.rows is not None:
        if not isinstance(body.rows, list) or not body.rows:
            return _bad_request("evaluate", "rows must be a non-empty list of objects",
                                "QUALITY_EVALUATE_EMPTY_ROWS")
        try:
            df = pd.DataFrame(body.rows)
        except Exception as exc:
            return _bad_request("evaluate", f"rows could not form a table: {exc}",
                                "QUALITY_EVALUATE_BAD_ROWS")
    elif body.job_id is not None:
        try:
            df = _load_frame(int(body.job_id), db)
        except KeyError as exc:
            return _error(404, "evaluate", str(exc), code="QUALITY_JOB_NOT_FOUND",
                          error_type="not_found")
        except Exception as exc:
            log.error(f"quality evaluate: cannot read job {body.job_id}: {type(exc).__name__}")
            return _error(422, "evaluate", f"cannot read dataset for job {body.job_id}",
                          code="QUALITY_READ_FAILED", error_type="validation")
    else:
        return _bad_request("evaluate", "provide rows or job_id",
                            "QUALITY_EVALUATE_NO_SOURCE")

    try:
        rules = _resolve_rules(body.profile, body.rules)
    except RuleValidationError as exc:
        return _bad_request("evaluate", str(exc), "QUALITY_EVALUATE_BAD_RULES")
    except ValueError as exc:
        return _bad_request("evaluate", str(exc), "QUALITY_PROFILE_INVALID")

    outcome = evaluate(df, rules)
    run = _history.save_run(
        db,
        dataset_ref=body.dataset_ref,
        job_id=body.job_id,
        profile=body.profile,
        scores={"score": outcome["score"], "column_scores": outcome["column_scores"]},
        verdict=outcome["verdict"],
    )
    _history.save_findings(db, run.id, outcome["results"])
    db.commit()
    log.info(f"quality evaluate: run {run.id} verdict={outcome['verdict']} "
             f"score={outcome['score']}")
    return _ok({
        "run_id": run.id,
        "dataset_ref": body.dataset_ref,
        "job_id": body.job_id,
        "profile": body.profile,
        "score": outcome["score"],
        "column_scores": outcome["column_scores"],
        "verdict": outcome["verdict"],
        "results": outcome["results"],
    })


@router.get("/quality/history")
def quality_history(dataset_ref: Optional[str] = Query(default=None),
                    limit: int = Query(default=50, ge=1, le=200),
                    db: Session = Depends(get_db),
                    _: str = Depends(require_service_auth)) -> dict:
    """Read-only: newest-first runs plus the score trend. Never writes."""
    runs = _history.get_history(db, dataset_ref=dataset_ref, limit=limit)
    payload: Dict[str, Any] = {"runs": runs}
    if dataset_ref:
        payload["trend"] = _history.get_trend(db, dataset_ref, limit=min(limit, 20))
    return _ok(payload)


@router.get("/quality/runs/{run_id}")
def quality_run(run_id: int, db: Session = Depends(get_db),
                _: str = Depends(require_service_auth)):
    """Read-only: one run with its findings. Never writes."""
    detail = _history.get_run_detail(db, run_id)
    if detail is None:
        return _error(404, "runs.show", f"quality run {run_id} not found",
                      code="QUALITY_RUN_NOT_FOUND", error_type="not_found")
    return _ok(detail)
