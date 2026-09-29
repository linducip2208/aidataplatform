"""AI schemas."""
from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class ChatMessage(BaseModel):
    role: str
    content: str


class ChatRequest(BaseModel):
    message: str
    conversation_id: Optional[int] = None
    context: Dict[str, Any] = Field(default_factory=dict)


class EvidenceRow(BaseModel):
    source: str = ""
    data: Dict[str, Any] = Field(default_factory=dict)


class ChatResponse(BaseModel):
    answer: str
    conversation_id: Optional[int] = None
    evidence: List[EvidenceRow] = Field(default_factory=list)
    steps: int = 0


class ReportRequest(BaseModel):
    period: str = Field(default="weekly")
    branch: Optional[str] = None
    format: str = Field(default="json")


class RagIngestRequest(BaseModel):
    title: str = ""
    content: str = ""
    source: str = "api"
    doc_type: str = "txt"


class RagQueryRequest(BaseModel):
    query: str
    top_k: int = Field(default=5, ge=1, le=20)


# --------------------------------------------------------------------------
# Enterprise extensions (additive only — the seven models above are frozen:
# `tests/test_schemas.py` pins their exact field sets, so existing contracts
# gain neither fields nor renames; new surface arrives as new models and as
# FastAPI query params on the routers).
# --------------------------------------------------------------------------

class Citation(BaseModel):
    """Compact RAG citation with within-chunk character offsets.

    ``char_start``/``char_end`` slice the earliest verbatim query-term hit in
    the chunk content (``content[char_start:char_end]`` is the matched term);
    both are ``None`` when the match is purely semantic (vector-only).
    """

    chunk_id: Optional[int] = None
    document_id: Optional[int] = None
    chunk_index: int = 0
    source: str = ""
    title: str = ""
    score: float = 0.0
    char_start: Optional[int] = None
    char_end: Optional[int] = None


class EvidenceItem(BaseModel):
    """Ranked RAG evidence row with the hybrid score breakdown."""

    chunk_id: Optional[int] = None
    content: str = ""
    score: float = 0.0
    vector: float = 0.0
    keyword: float = 0.0
    fused: float = 0.0
    rerank: Optional[float] = None
    features: Dict[str, Any] = Field(default_factory=dict)
    document_id: Optional[int] = None
    chunk_index: int = 0
    source: str = ""
    title: str = ""
    char_start: Optional[int] = None
    char_end: Optional[int] = None


class RagAnswer(BaseModel):
    """Shaped RAG answer: text plus traceable evidence and self-report."""

    answer: str = ""
    evidence: List[EvidenceItem] = Field(default_factory=list)
    citations: List[Citation] = Field(default_factory=list)
    chunks: List[Dict[str, Any]] = Field(default_factory=list)
    n_results: int = 0
    confidence: float = 0.0
    limitations: List[str] = Field(default_factory=list)


class ChatEnrichedResponse(BaseModel):
    """Superset of ``ChatResponse`` returned by ``POST /ai/chat``.

    ``ChatResponse`` itself is untouched; this model documents the additive
    keys the route merges in (``data_sources``, ``metrics``, ``confidence``,
    ``limitations``, ``usage``, ``template``).
    """

    answer: str
    conversation_id: Optional[int] = None
    evidence: List[EvidenceRow] = Field(default_factory=list)
    steps: int = 0
    data_sources: List[str] = Field(default_factory=list)
    metrics: Dict[str, Any] = Field(default_factory=dict)
    confidence: float = 0.0
    limitations: List[str] = Field(default_factory=list)
    usage: Dict[str, Any] = Field(default_factory=dict)
    template: str = "assistant.v1"


class SqlValidateRequest(BaseModel):
    sql: str = ""


class SqlValidateResponse(BaseModel):
    allowed: bool = False
    reason: str = ""
    normalized_sql: str = ""
    limit: int = 200
    limit_enforced: bool = False
    tables: List[str] = Field(default_factory=list)
    note: str = ""


class UsageRecord(BaseModel):
    id: Optional[int] = None
    conversation_id: Optional[int] = None
    model: str = ""
    provider: str = ""
    prompt_tokens: int = 0
    completion_tokens: int = 0
    total_tokens: int = 0
    estimated_cost: Optional[float] = None
    currency: str = "USD"
    cost_note: str = ""
    created_at: Optional[str] = None


class UsageTotals(BaseModel):
    turns: int = 0
    prompt_tokens: int = 0
    completion_tokens: int = 0
    total_tokens: int = 0
    estimated_cost_total: float = 0.0
    currency: str = "USD"
    unpriced_rows: int = 0


class UsageResponse(BaseModel):
    usage: List[UsageRecord] = Field(default_factory=list)
    totals: UsageTotals = Field(default_factory=UsageTotals)
