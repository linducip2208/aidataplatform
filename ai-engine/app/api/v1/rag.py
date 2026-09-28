"""RAG endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ai import RagIngestRequest, RagQueryRequest

router = APIRouter(tags=["rag"])


@router.post("/rag/ingest")
def ingest(body: RagIngestRequest, db: Session = Depends(get_db),
           _: str = Depends(require_service_auth)) -> dict:
    from app.ai.rag import ingest_text

    return {"success": True, "data": ingest_text(body.title, body.content, body.source, body.doc_type, db)}


@router.post("/rag/query")
def query(body: RagQueryRequest, db: Session = Depends(get_db),
          _: str = Depends(require_service_auth)) -> dict:
    from app.ai import rag as rag_mod

    return {"success": True, "data": rag_mod.query(body.query, body.top_k, db)}
