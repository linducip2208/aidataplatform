"""RAG: ingestion (PDF/DOCX/TXT/MD/HTML), chunking, embeddings, retrieval."""
from __future__ import annotations

import hashlib
import re
from pathlib import Path
from typing import Any, Dict, List

import numpy as np

from app.ai import llm as llm_client


def read_document(path: str | Path) -> Dict[str, Any]:
    p = Path(path)
    suffix = p.suffix.lower()
    text = ""
    if suffix == ".pdf":
        try:
            from pypdf import PdfReader

            reader = PdfReader(str(p))
            text = "\n".join([(pg.extract_text() or "") for pg in reader.pages])
        except Exception:
            text = ""
    elif suffix == ".docx":
        try:
            import docx

            doc = docx.Document(str(p))
            text = "\n".join([para.text for para in doc.paragraphs])
        except Exception:
            text = ""
    elif suffix in (".html", ".htm"):
        try:
            raw = p.read_text(encoding="utf-8", errors="ignore")
            text = re.sub(r"<[^>]+>", " ", raw)
        except Exception:
            text = ""
    else:
        try:
            text = p.read_text(encoding="utf-8", errors="ignore")
        except Exception:
            text = ""
    return {"title": p.name, "source": str(p), "doc_type": suffix.lstrip(".") or "txt", "content": text}


def chunk_text(text: str, size: int = 800, overlap: int = 120) -> List[str]:
    text = re.sub(r"\s+", " ", text or "").strip()
    if not text:
        return []
    chunks = []
    start = 0
    while start < len(text):
        end = min(len(text), start + size)
        # try break at sentence boundary
        if end < len(text):
            dot = text.rfind(". ", start, end)
            if dot > start + size // 2:
                end = dot + 1
        chunks.append(text[start:end].strip())
        if end >= len(text):
            break
        start = max(end - overlap, start + 1)
    return [c for c in chunks if c]


def _cosine(a: List[float], b: List[float]) -> float:
    va, vb = np.array(a, dtype=float), np.array(b, dtype=float)
    denom = (np.linalg.norm(va) * np.linalg.norm(vb)) or 1.0
    return float(np.dot(va, vb) / denom)


def ingest_text(title: str, content: str, source: str = "api", doc_type: str = "txt", db_session=None) -> Dict[str, Any]:
    chunks = chunk_text(content)
    embeddings = llm_client.embed(chunks) if chunks else []
    doc_id = None
    if db_session is not None:
        try:
            from app.database.models import RagChunk, RagDocument

            doc = RagDocument(source=source, title=title, doc_type=doc_type, meta={"n_chunks": len(chunks)})
            db_session.add(doc)
            db_session.commit()
            db_session.refresh(doc)
            doc_id = doc.id
            for i, ch in enumerate(chunks):
                emb = embeddings[i] if i < len(embeddings) else []
                try:
                    db_session.add(RagChunk(document_id=doc_id, chunk_index=i, content=ch,
                                            embedding=emb if isinstance(emb, list) else {"v": emb}))
                except Exception:
                    # pgvector expects list; fallback JSON handled by model variant
                    db_session.add(RagChunk(document_id=doc_id, chunk_index=i, content=ch,
                                            embedding={"v": []}))
            db_session.commit()
        except Exception:
            try:
                db_session.rollback()
            except Exception:
                pass
    return {"document_id": doc_id, "n_chunks": len(chunks)}


def query(query_text: str, top_k: int = 5, db_session=None) -> List[Dict[str, Any]]:
    q_emb = llm_client.embed([query_text])[0] if query_text else []
    if db_session is None:
        return []
    try:
        from app.database.models import RagChunk

        rows = db_session.query(RagChunk).limit(2000).all()
    except Exception:
        return []
    scored = []
    for r in rows:
        emb = r.embedding
        if isinstance(emb, dict):
            emb = emb.get("v", [])
        try:
            if not emb or not q_emb:
                # keyword fallback
                score = 1.0 if query_text.lower() in (r.content or "").lower() else 0.0
            else:
                n = min(len(emb), len(q_emb))
                score = _cosine(list(emb)[:n], list(q_emb)[:n])
                # small keyword boost (rerank)
                if query_text.lower() in (r.content or "").lower():
                    score += 0.05
            scored.append((score, r))
        except Exception:
            continue
    scored.sort(key=lambda x: x[0], reverse=True)
    return [{"content": r.content, "score": round(float(s), 4),
             "document_id": r.document_id, "chunk_index": r.chunk_index} for s, r in scored[:top_k]]
