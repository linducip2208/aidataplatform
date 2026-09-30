"""RAG endpoints."""
from __future__ import annotations

from typing import Optional

from fastapi import APIRouter, Depends, Query
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.core.errors import build_error_response
from app.core.logging import get_request_id
from app.core.security import require_service_auth
from app.database.connection import get_db
from app.schemas.ai import RagIngestRequest, RagQueryRequest

router = APIRouter(tags=["rag"])

# `app.ai.rag` caps a document at MAX_INGEST_CHARS, but only after the whole
# body has been parsed and the text embedded. The request is bounded here so an
# oversized upload is refused before either cost is paid.
MAX_INGEST_CHARS = 500_000
MAX_QUERY_CHARS = 2000


@router.post("/rag/ingest")
def ingest(body: RagIngestRequest, db: Session = Depends(get_db),
           _: str = Depends(require_service_auth),
           visibility: Optional[str] = Query(default=None),
           owner: Optional[str] = Query(default=None)):
    from app.ai.rag import ingest_text, normalise_visibility

    if visibility is not None:
        try:
            normalise_visibility(visibility)
        except ValueError as exc:
            return JSONResponse(status_code=422, content=build_error_response(
                module="rag", operation="ingest", error_type="validation",
                code="BAD_VISIBILITY", message=str(exc),
                request_id=get_request_id(),
                resolution="Pakai public, internal, atau confidential.",
                details={"field": "visibility"}))

    if len(body.content) > MAX_INGEST_CHARS:
        return JSONResponse(status_code=413, content=build_error_response(
            module="rag", operation="ingest", error_type="validation",
            code="DOCUMENT_TOO_LARGE",
            message=f"content must be at most {MAX_INGEST_CHARS} characters, "
                    f"got {len(body.content)}",
            request_id=get_request_id(),
            resolution="Kirim dokumen yang lebih kecil, atau pecah menjadi beberapa bagian.",
            details={"field": "content", "max_chars": MAX_INGEST_CHARS}))

    res = ingest_text(body.title, body.content, body.source, body.doc_type, db,
                      visibility=visibility if visibility is not None else "public",
                      owner=owner)
    if res.get("error") == "private_needs_owner":
        return JSONResponse(status_code=422, content=build_error_response(
            module="rag", operation="ingest", error_type="validation",
            code="PRIVATE_NEEDS_OWNER",
            message="private documents require an owner.",
            request_id=get_request_id(),
            resolution="Kirim owner bersama visibility=private.",
            details={"field": "owner"}))
    if res.get("status") == "failed":
        # ingest_text swallows the write error and reports the failure in-band.
        # A 200 with success=false became a 422 in Laravel, so a failed write
        # was indistinguishable from a bad payload.
        return JSONResponse(status_code=500, content=build_error_response(
            module="rag", operation="ingest", error_type="internal",
            code="INGEST_FAILED",
            message="The document could not be stored.",
            request_id=get_request_id(),
            resolution="Periksa log server lalu ulangi.", internal=True))
    return {"success": True, "data": res}


@router.get("/rag/documents")
def list_documents(db: Session = Depends(get_db),
                   _: str = Depends(require_service_auth),
                   limit: int = Query(default=50, ge=1, le=200)):
    """Newest-first document headers for the knowledge-base UI.

    Read-only; visibility/owner come from each document's ``meta`` so the
    caller can render audience badges without fetching chunks.
    """
    from app.ai.rag import doc_visibility
    from app.database.models import RagDocument

    try:
        rows = (db.query(RagDocument).order_by(RagDocument.id.desc())
                .limit(max(1, min(int(limit), 200))).all())
    except Exception:
        return {"success": True, "data": []}
    out = []
    for doc in rows:
        try:
            meta = doc.meta if isinstance(doc.meta, dict) else {}
            out.append({
                "id": int(doc.id),
                "title": str(doc.title or "untitled"),
                "source": str(doc.source or ""),
                "doc_type": str(doc.doc_type or "txt"),
                "visibility": doc_visibility(meta),
                "owner": str(meta.get("owner") or "") or None,
                "n_chunks": int(meta.get("n_chunks") or 0),
                "created_at": doc.created_at.isoformat() if doc.created_at else None,
            })
        except Exception:
            continue
    return {"success": True, "data": out}


@router.post("/rag/query")
def query(body: RagQueryRequest, db: Session = Depends(get_db),
          _: str = Depends(require_service_auth),
          hybrid: bool = True, rerank: bool = True,
          allow: Optional[str] = Query(default=None),
          user_id: Optional[str] = Query(default=None)):
    from app.ai import rag as rag_mod

    if len(body.query) > MAX_QUERY_CHARS:
        return JSONResponse(status_code=422, content=build_error_response(
            module="rag", operation="query", error_type="validation",
            code="QUERY_TOO_LONG",
            message=f"query must be at most {MAX_QUERY_CHARS} characters, "
                    f"got {len(body.query)}",
            request_id=get_request_id(),
            resolution="Potong pertanyaan, lalu ulangi.",
            details={"field": "query", "max_chars": MAX_QUERY_CHARS}))

    # `hybrid`/`rerank` ride as query params so `RagQueryRequest` stays
    # frozen (`tests/test_schemas.py` pins its exact field set). Response is
    # the shaped answer (answer/evidence/citations/confidence/limitations)
    # with the legacy `chunks`/`n_results` keys preserved for older clients.
    return {"success": True, "data": rag_mod.query(
        body.query, body.top_k, db, hybrid=hybrid, rerank=rerank,
        allowed_visibility=allow, user_id=user_id)}
