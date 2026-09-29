"""Decision engine SQLAlchemy models (Agent 7 owned).

Shared ``Base`` is imported, never redefined. Master generates the single
alembic revision from the DDL spec in ``docs/decision-engine.md``.

Tables:

* ``decision_cases`` — one row per ``recommend()`` call (subject + status).
* ``decision_recommendations`` — one row per fired rule.
* ``decision_audits`` — human/system decisions recorded against a case.
"""
from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import JSON, Float, ForeignKey, Integer, String, Text
from sqlalchemy import DateTime as SADateTime
from sqlalchemy.orm import Mapped, mapped_column

from app.database.connection import Base

__all__ = ["DecisionCase", "DecisionRecommendation", "DecisionAudit"]


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class DecisionCase(Base):
    __tablename__ = "decision_cases"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    subject: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(32), default="open", index=True)
    created_at: Mapped[datetime] = mapped_column(SADateTime(timezone=True), default=_utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        SADateTime(timezone=True), default=_utcnow, onupdate=_utcnow
    )


class DecisionRecommendation(Base):
    __tablename__ = "decision_recommendations"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    case_id: Mapped[int] = mapped_column(
        ForeignKey("decision_cases.id", ondelete="CASCADE"), index=True)
    action: Mapped[str] = mapped_column(String(512), default="")
    impact: Mapped[dict] = mapped_column(JSON, default=dict)
    confidence: Mapped[float] = mapped_column(Float, default=0.0)
    evidence: Mapped[dict] = mapped_column(JSON, default=dict)
    explanation: Mapped[dict] = mapped_column(JSON, default=dict)
    score: Mapped[float] = mapped_column(Float, default=0.0)
    rule: Mapped[str] = mapped_column(String(128), default="")
    created_at: Mapped[datetime] = mapped_column(SADateTime(timezone=True), default=_utcnow)


class DecisionAudit(Base):
    __tablename__ = "decision_audits"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    case_id: Mapped[int] = mapped_column(
        ForeignKey("decision_cases.id", ondelete="CASCADE"), index=True)
    actor: Mapped[str] = mapped_column(String(128), default="")
    decision: Mapped[str] = mapped_column(String(64), default="")
    rationale: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime] = mapped_column(SADateTime(timezone=True), default=_utcnow)
