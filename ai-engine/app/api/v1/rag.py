"""RAG endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends
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
           _: str = Depends(require_service_auth)):
    from app.ai.rag import ingest_text

    if len(body.content) > MAX_INGEST_CHARS:
        return JSONResponse(status_code=413, content=build_error_response(
            module="rag", operation="ingest", error_type="validation",
            code="DOCUMENT_TOO_LARGE",
            message=f"content must be at most {MAX_INGEST_CHARS} characters, "
                    f"got {len(body.content)}",
            request_id=get_request_id(),
            resolution="Kirim dokumen yang lebih kecil, atau pecah menjadi beberapa bagian.",
            details={"field": "content", "max_chars": MAX_INGEST_CHARS}))

    res = ingest_text(body.title, body.content, body.source, body.doc_type, db)
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


@router.post("/rag/query")
def query(body: RagQueryRequest, db: Session = Depends(get_db),
          _: str = Depends(require_service_auth),
          hybrid: bool = True, rerank: bool = True):
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
        body.query, body.top_k, db, hybrid=hybrid, rerank=rerank)}
