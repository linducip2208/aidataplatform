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
