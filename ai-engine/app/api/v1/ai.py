"""AI chat + report endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ai import ChatRequest, ChatResponse, ReportRequest

router = APIRouter(tags=["ai"])


@router.post("/ai/chat", response_model=dict)
def chat(body: ChatRequest, db: Session = Depends(get_db),
         _: str = Depends(require_service_auth)) -> dict:
    from app.ai.agent import run_agent

    res = run_agent(body.message, db, body.conversation_id)
    return {"success": True, "data": {"answer": res["answer"],
                                      "conversation_id": res["conversation_id"],
                                      "evidence": res["evidence"], "steps": res["steps"]}}


@router.post("/ai/report")
def report(body: ReportRequest, db: Session = Depends(get_db),
           _: str = Depends(require_service_auth)) -> dict:
    from app.ai.reporting import executive_summary

    data = executive_summary(db, body.period)
    if body.format == "html":
        from fastapi.responses import HTMLResponse

        return HTMLResponse(content=data.get("html", ""))  # type: ignore[return-value]
    return {"success": True, "data": data}
