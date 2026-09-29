"""Business glossary endpoints (read-only).

``GET /semantic/metrics`` publishes the certified metric definitions the AI
uses to ground answers and SQL. No writes exist by design: the glossary is
versioned in ``app/semantic/glossary.py`` and changes ship with the code.
"""
from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.security import require_service_auth
from app.semantic.glossary import GLOSSARY_VERSION, all_metrics

router = APIRouter(tags=["semantic"])

_MODULE = "semantic"


@router.get("/semantic/metrics")
def list_metrics(_: str = Depends(require_service_auth)) -> dict:
    return {"success": True, "data": {"version": GLOSSARY_VERSION, "metrics": all_metrics()}}
