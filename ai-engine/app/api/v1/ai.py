"""AI chat + report endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ai import ChatRequest, ChatResponse, ReportRequest

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
         _: str = Depends(require_service_auth)):
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

    res = run_agent(body.message, db, body.conversation_id)
    # Built through ChatResponse so the declared schema is what Laravel reads:
    # a missing key here surfaces as a 500 at serialisation time rather than as
    # a silently absent `data.answer`, which is a blank page in the UI.
    chat_res = ChatResponse(
        answer=res.get("answer") or "",
        conversation_id=res.get("conversation_id"),
        evidence=res.get("evidence") or [],
        steps=int(res.get("steps") or 0),
    )
    return {"success": True, "data": chat_res.model_dump()}


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
