"""AI chat + report endpoints."""
from __future__ import annotations

from typing import Any, Dict, Optional

from fastapi import APIRouter, Depends, Query
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ai import (
    ChatRequest, ChatResponse, ReportRequest, SqlValidateRequest,
    SqlValidateResponse, UsageResponse,
)

router = APIRouter(tags=["ai"])

# Laravel validates `message` as `max:4000` before proxying, so anything larger
# is a caller that skipped that check. `app.ai.agent` clips to its own 8000
# limit, but the request body itself is unbounded here, so a single request can
# carry megabytes of text through the JSON parser and into the session.
MAX_MESSAGE_CHARS = 4000
MAX_PERIOD_CHARS = 20
ALLOWED_PERIODS = frozenset({"daily", "weekly", "monthly"})


@router.post("/ai/chat")
def chat(body: ChatRequest, db: Session = Depends(get_db),
         _: str = Depends(require_service_auth),
         template: Optional[str] = Query(default=None, max_length=64)):
    from app.ai.agent import run_agent

    if len(body.message) > MAX_MESSAGE_CHARS:
        return JSONResponse(status_code=422, content=build_error_response(
            module="ai", operation="chat", error_type="validation",
            code="MESSAGE_TOO_LONG",
            message=f"message must be at most {MAX_MESSAGE_CHARS} characters, "
                    f"got {len(body.message)}",
            request_id=get_request_id(),
            resolution="Potong pertanyaan, lalu ulangi.",
            details={"field": "message", "max_chars": MAX_MESSAGE_CHARS}))

    # `template` arrives as a query param so `ChatRequest` stays frozen; older
    # clients that embedded it in `context` keep working via the fallback.
    chosen = template
    if chosen is None and isinstance(body.context, dict):
        context_template = body.context.get("template")
        if isinstance(context_template, str) and context_template.strip():
            chosen = context_template.strip()[:64]
    res = run_agent(body.message, db, body.conversation_id, template=chosen)
    # Built through ChatResponse so the declared schema is what Laravel reads:
    # a missing key here surfaces as a 500 at serialisation time rather than as
    # a silently absent `data.answer`, which is a blank page in the UI.
    chat_res = ChatResponse(
        answer=res.get("answer") or "",
        conversation_id=res.get("conversation_id"),
        evidence=res.get("evidence") or [],
        steps=int(res.get("steps") or 0),
    )
    payload: Dict[str, Any] = chat_res.model_dump()
    # Additive contract: ANSWER / EVIDENCE / SOURCES / METRICS / CONFIDENCE /
    # LIMITATIONS plus the usage ledger summary for the turn. Legacy readers
    # that pick the first four keys are unaffected.
    payload["data_sources"] = res.get("data_sources") or []
    payload["metrics"] = res.get("metrics") or {}
    payload["confidence"] = res.get("confidence", 0.0)
    payload["limitations"] = res.get("limitations") or []
    payload["usage"] = res.get("usage") or {}
    payload["template"] = res.get("template") or "assistant.v1"
    return {"success": True, "data": payload}


@router.post("/ai/report")
def report(body: ReportRequest, db: Session = Depends(get_db),
           _: str = Depends(require_service_auth)):
    from app.ai.reporting import executive_summary

    if len(body.period) > MAX_PERIOD_CHARS or body.period not in ALLOWED_PERIODS:
        return JSONResponse(status_code=422, content=build_error_response(
            module="ai", operation="report", error_type="validation",
            code="INVALID_PERIOD",
            message=f"period must be one of: " + ", ".join(sorted(ALLOWED_PERIODS)),
            request_id=get_request_id(),
            resolution="Gunakan periode yang didukung.",
            details={"field": "period"}))

    data = executive_summary(db, body.period)
    if body.format == "html":
        from fastapi.responses import HTMLResponse

        return HTMLResponse(content=data.get("html", ""))  # type: ignore[return-value]
    return {"success": True, "data": data}


@router.get("/ai/usage")
def usage(db: Session = Depends(get_db),
          _: str = Depends(require_service_auth),
          conversation_id: Optional[int] = Query(default=None)):
    """Return the token/cost ledger: per-turn rows plus summed totals.

    Optional ``conversation_id`` scopes to one engine conversation; without it
    the latest 500 rows across conversations are summarised. Costs are
    estimates (see ``app.ai.cost_tracking``); unpriced rows are counted, never
    zero-filled, in ``totals.unpriced_rows``.
    """
    from app.ai import cost_tracking

    data = cost_tracking.get_usage(db, conversation_id)
    return {"success": True, "data": UsageResponse(**data).model_dump()}


@router.get("/ai/usage/summary")
def usage_summary(db: Session = Depends(get_db),
                  _: str = Depends(require_service_auth),
                  days: int = Query(default=30)):
    """Cost dashboard aggregation: totals plus per-model and per-day
    breakdowns over the last ``days`` days (1-365). All numbers come from
    the ``ai_usage`` ledger; estimates stay labelled and unpriced rows are
    counted, never zero-filled.
    """
    from app.ai import cost_tracking

    return {"success": True, "data": cost_tracking.cost_summary(db, days)}


@router.post("/ai/sql/validate")
def sql_validate(body: SqlValidateRequest,
                 _: str = Depends(require_service_auth)):
    """Check a SQL statement against the guardrails without executing it.

    Returns the ``SqlValidateResponse`` verdict (allowed / reason /
    normalised SQL / enforced limit / tables). Nothing here touches the
    database by design: validation is the safe half of the SQL path, and the
    executing half (``tools.execute_sql``) re-validates anyway.
    """
    from app.ai import sql_guard

    validation = sql_guard.validate_sql(body.sql)
    return {"success": True, "data": SqlValidateResponse(
        allowed=bool(validation.get("allowed")),
        reason=str(validation.get("reason") or ""),
        normalized_sql=str(validation.get("normalized_sql") or ""),
        limit=int(validation.get("limit") or 200),
        limit_enforced=bool(validation.get("limit_enforced")),
        tables=list(validation.get("tables") or []),
        note=str(validation.get("note") or ""),
    ).model_dump()}
