"""Decision engine pydantic payloads (additive new file)."""
from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class SubjectIn(BaseModel):
    dataset_ref: str = Field(default="", max_length=256)
    branch: str = Field(default="", max_length=128)
    period: str = Field(default="", max_length=32)
    granularity: str = Field(default="daily", max_length=16)
    horizon: int = Field(default=7, ge=1, le=365)


class RecommendIn(BaseModel):
    subject: SubjectIn = Field(default_factory=SubjectIn)


class ScenarioRunIn(BaseModel):
    type: str = Field(description="price_change_pct|inventory_change_pct|churn_rise_pp")
    params: Dict[str, Any] = Field(default_factory=dict)
    subject: Optional[SubjectIn] = None


class AuditIn(BaseModel):
    actor: str = Field(default="", max_length=128)
    decision: str = Field(default="", max_length=64)
    rationale: str = Field(default="", max_length=2000)


class CaseOut(BaseModel):
    id: int
    subject: Dict[str, Any] = Field(default_factory=dict)
    status: str = ""
    created_at: str = ""


class RecommendationOut(BaseModel):
    action: str = ""
    expected_impact: Dict[str, Any] = Field(default_factory=dict)
    confidence: float = 0.0
    evidence_ids: List[str] = Field(default_factory=list)
    scenario_ref: Optional[Dict[str, Any]] = None
    score: float = 0.0
    rule: str = ""
    rule_version: str = ""
    explanation: Dict[str, Any] = Field(default_factory=dict)
