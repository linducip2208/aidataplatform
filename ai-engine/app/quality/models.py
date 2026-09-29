"""SQLAlchemy models for enterprise quality (rules, runs, findings).

Registers on the shared ``Base`` from ``app.database.connection``. This module
owns these three tables exclusively; ``app/database/models.py`` is never edited.
"""
from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import JSON, Boolean, DateTime, Float, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.database.connection import Base


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class QualityRule(Base):
    """A stored, reusable rule definition (mirrors the evaluate() rule shape)."""

    __tablename__ = "quality_rules"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(128), unique=True, index=True)
    dataset_type: Mapped[str] = mapped_column(String(64), default="sales", index=True)
    column: Mapped[str | None] = mapped_column(String(256), nullable=True)
    rule_type: Mapped[str] = mapped_column(String(32), index=True)
    params: Mapped[dict] = mapped_column(JSON, default=dict)
    severity: Mapped[str] = mapped_column(String(16), default="error")
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=_utcnow, onupdate=_utcnow
    )


class QualityRun(Base):
    """One persisted evaluate() call: scores + verdict, findings in QualityFinding."""

    __tablename__ = "quality_runs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    dataset_ref: Mapped[str | None] = mapped_column(String(256), nullable=True, index=True)
    job_id: Mapped[int | None] = mapped_column(Integer, nullable=True, index=True)
    profile: Mapped[str | None] = mapped_column(String(128), nullable=True)
    scores: Mapped[dict] = mapped_column(JSON, default=dict)
    verdict: Mapped[str] = mapped_column(String(16), default="pass", index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_utcnow)


class QualityFinding(Base):
    """Per-rule failures of one run. ``rule_id`` is the rule's string id
    (ad-hoc rules have no row in ``quality_rules``, so this is not a FK)."""

    __tablename__ = "quality_findings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    run_id: Mapped[int] = mapped_column(
        ForeignKey("quality_runs.id"), index=True
    )
    rule_id: Mapped[str | None] = mapped_column(String(128), nullable=True)
    column: Mapped[str | None] = mapped_column(String(256), nullable=True)
    sample: Mapped[list] = mapped_column(JSON, default=list)
    count: Mapped[int] = mapped_column(Integer, default=0)
