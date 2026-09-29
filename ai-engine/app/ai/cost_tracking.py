"""Token/cost ledger for AI usage (per conversation + model).

Every assistant turn records one row: prompt/completion token counts and an
estimated cost from :data:`MODEL_RATES`. The counts come from the provider's
``usage`` block when the LLM answered (``llm.chat`` keeps it in ``raw``), and
fall back to :func:`estimate_tokens` otherwise, in which case ``estimated``
is ``True`` so a reader can tell a metered row from a heuristic one.

Costs are estimates in USD per the rate table below, which is a documentation
snapshot, not a live price feed — verify against the provider before billing
anyone. An unknown model records ``estimated_cost=None`` with an explicit
``cost_note`` instead of guessing a price; a guessed price in a ledger is
worse than a blank one.

The table is ``ai_usage`` on the shared ``Base`` (imported from
``app.database.connection``, never redefined here). Alembic revision is owned
by master at integration; the DDL spec is:

.. code-block:: sql

    CREATE TABLE ai_usage (
        id SERIAL PRIMARY KEY,
        conversation_id INTEGER NULL,          -- engine ai_conversations.id
        model VARCHAR(128) NOT NULL DEFAULT '',
        provider VARCHAR(64) NOT NULL DEFAULT '',
        prompt_tokens INTEGER NOT NULL DEFAULT 0,
        completion_tokens INTEGER NOT NULL DEFAULT 0,
        total_tokens INTEGER NOT NULL DEFAULT 0,
        estimated_cost DOUBLE PRECISION NULL, -- USD, NULL when unpriced
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        cost_note VARCHAR(512) NOT NULL DEFAULT '',
        meta JSON NOT NULL DEFAULT '{}',
        created_at TIMESTAMPTZ NULL, updated_at TIMESTAMPTZ NULL
    );
    CREATE INDEX ix_ai_usage_conversation_id ON ai_usage (conversation_id);
    CREATE INDEX ix_ai_usage_model ON ai_usage (model);

Recording never raises: a ledger write that takes down a chat turn would be a
monitoring failure causing a product failure, so every error is logged and the
turn continues with ``recorded=False``.
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

from sqlalchemy import DateTime, Float, Integer, JSON, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.logging import get_logger
from app.database.connection import Base

log = get_logger("ai.cost_tracking", "record")

# USD per 1M tokens, snapshot of public list prices for the models this
# platform ships as defaults. Not live pricing — see module docstring.
# (prompt, completion)
MODEL_RATES: Dict[str, tuple] = {
    "gpt-4o-mini": (0.15, 0.60),
    "gpt-4o": (2.50, 10.00),
    "gpt-4.1-mini": (0.40, 1.60),
    "text-embedding-3-small": (0.02, 0.0),
    "anthropic/claude-3.5-sonnet": (3.00, 15.00),
    "meta-llama/llama-3.1-8b-instruct": (0.06, 0.06),
}

CURRENCY = "USD"
UNKNOWN_MODEL_NOTE = (
    "unknown model: no rate in MODEL_RATES, estimated_cost left null — "
    "do not bill from this row")
ESTIMATED_COUNT_NOTE = "token counts estimated heuristically (chars/4)"
OFFLINE_NOTE = "offline/degraded turn: no provider usage reported"


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class AIUsage(Base):
    """One ledger row per assistant turn (see module docstring for DDL)."""

    __tablename__ = "ai_usage"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    conversation_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True, index=True)
    model: Mapped[str] = mapped_column(String(128), default="")
    provider: Mapped[str] = mapped_column(String(64), default="")
    prompt_tokens: Mapped[int] = mapped_column(Integer, default=0)
    completion_tokens: Mapped[int] = mapped_column(Integer, default=0)
    total_tokens: Mapped[int] = mapped_column(Integer, default=0)
    estimated_cost: Mapped[Optional[float]] = mapped_column(Float, nullable=True)
    currency: Mapped[str] = mapped_column(String(8), default=CURRENCY)
    cost_note: Mapped[str] = mapped_column(String(512), default="")
    meta: Mapped[dict] = mapped_column(JSON, default=dict)
    created_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), default=_utcnow)
    updated_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime(timezone=True), default=_utcnow, onupdate=_utcnow)


def estimate_tokens(text: Any) -> int:
    """Heuristic token count: ``max(1, chars // 4)`` for non-empty text.

    The 1:4 ratio is the documented OpenAI approximation for English prose;
    it over-counts terse Indonesian warehouse JSON and under-counts CJK, but
    it is deterministic and clearly labelled via ``estimated=True`` wherever
    it is used, so a reader never mistakes it for a metered count.
    """
    chars = len(str(text or ""))
    if chars <= 0:
        return 0
    return max(1, chars // 4)


def estimate_cost(model: str, prompt_tokens: int, completion_tokens: int) -> tuple:
    """Return ``(cost_or_None, note)`` for a model and token counts.

    Unknown models yield ``(None, UNKNOWN_MODEL_NOTE)`` — the row stays
    useful for volume accounting while refusing to invent a price.
    """
    rates = MODEL_RATES.get(str(model or "").strip())
    if rates is None:
        return None, UNKNOWN_MODEL_NOTE
    prompt_rate, completion_rate = rates
    cost = prompt_tokens / 1_000_000 * prompt_rate + completion_tokens / 1_000_000 * completion_rate
    return round(cost, 8), ""


def _as_int(value: Any, default: int = 0) -> int:
    try:
        number = int(value)
    except (TypeError, ValueError):
        return default
    return max(0, number)


def record_usage(db_session=None, conversation_id: Optional[int] = None,
                 model: str = "", provider: str = "",
                 prompt_text: str = "", completion_text: str = "",
                 provider_usage: Optional[Dict[str, Any]] = None,
                 offline: bool = False) -> Dict[str, Any]:
    """Persist one ledger row and return its summary mapping.

    Token counts prefer ``provider_usage`` (``prompt_tokens`` /
    ``completion_tokens`` keys, OpenAI shape); missing keys fall back to
    :func:`estimate_tokens` over the prompt/completion text and set
    ``estimated=True``. Never raises: on any failure returns
    ``{"recorded": False, ...}`` with zeroed counts so the chat turn that
    owns the session is unaffected.
    """
    prompt_tokens = completion_tokens = 0
    estimated = False
    notes: List[str] = []
    usage = provider_usage if isinstance(provider_usage, dict) else {}
    if "prompt_tokens" in usage or "completion_tokens" in usage:
        prompt_tokens = _as_int(usage.get("prompt_tokens"))
        completion_tokens = _as_int(usage.get("completion_tokens"))
    else:
        prompt_tokens = estimate_tokens(prompt_text)
        completion_tokens = estimate_tokens(completion_text)
        estimated = True
        notes.append(ESTIMATED_COUNT_NOTE)
    if offline:
        notes.append(OFFLINE_NOTE)
    total = prompt_tokens + completion_tokens
    cost, cost_note = estimate_cost(model or "", prompt_tokens, completion_tokens)
    if cost_note:
        notes.append(cost_note)
    summary: Dict[str, Any] = {
        "recorded": False, "conversation_id": conversation_id, "model": model or "",
        "provider": provider or "", "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens, "total_tokens": total,
        "estimated_cost": cost, "currency": CURRENCY,
        "cost_note": "; ".join(notes), "estimated": estimated,
    }
    if db_session is None:
        return summary
    cid: Optional[int] = None
    if conversation_id is not None:
        try:
            cid = int(conversation_id)
        except (TypeError, ValueError):
            cid = None
    try:
        db_session.add(AIUsage(
            conversation_id=cid, model=str(model or ""), provider=str(provider or ""),
            prompt_tokens=prompt_tokens, completion_tokens=completion_tokens,
            total_tokens=total, estimated_cost=cost, currency=CURRENCY,
            cost_note="; ".join(notes)[:512],
            meta={"estimated": estimated, "offline": bool(offline)}))
        db_session.commit()
        summary["recorded"] = True
    except Exception as exc:
        try:
            db_session.rollback()
        except Exception:
            pass
        log.warning(f"usage not recorded: {type(exc).__name__}")
    return summary


def get_usage(db_session=None, conversation_id: Optional[int] = None) -> Dict[str, Any]:
    """Return per-conversation rows plus totals: ``{"usage": [...], "totals": {...}}``.

    ``totals`` sums only priced rows for ``estimated_cost_total`` and reports
    ``unpriced_rows`` separately, so an unknown-model row can never silently
    drag an invoice total toward zero.
    """
    rows: List[Dict[str, Any]] = []
    if db_session is None:
        return {"usage": rows, "totals": _empty_totals()}
    try:
        query = db_session.query(AIUsage)
        if conversation_id is not None:
            try:
                query = query.filter(AIUsage.conversation_id == int(conversation_id))
            except (TypeError, ValueError):
                return {"usage": [], "totals": _empty_totals()}
        query = query.order_by(AIUsage.id.desc()).limit(500)
        for row in query.all():
            rows.append({
                "id": int(row.id),
                "conversation_id": row.conversation_id,
                "model": row.model or "",
                "provider": row.provider or "",
                "prompt_tokens": int(row.prompt_tokens or 0),
                "completion_tokens": int(row.completion_tokens or 0),
                "total_tokens": int(row.total_tokens or 0),
                "estimated_cost": row.estimated_cost,
                "currency": row.currency or CURRENCY,
                "cost_note": row.cost_note or "",
                "created_at": row.created_at.isoformat() if row.created_at else None,
            })
    except Exception as exc:
        log.warning(f"usage read failed: {type(exc).__name__}")
        return {"usage": [], "totals": _empty_totals()}
    return {"usage": rows, "totals": _sum_totals(rows)}


def _empty_totals() -> Dict[str, Any]:
    return {"turns": 0, "prompt_tokens": 0, "completion_tokens": 0,
            "total_tokens": 0, "estimated_cost_total": 0.0,
            "currency": CURRENCY, "unpriced_rows": 0}


def _sum_totals(rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    totals = _empty_totals()
    totals["turns"] = len(rows)
    for row in rows:
        totals["prompt_tokens"] += int(row.get("prompt_tokens") or 0)
        totals["completion_tokens"] += int(row.get("completion_tokens") or 0)
        totals["total_tokens"] += int(row.get("total_tokens") or 0)
        if row.get("estimated_cost") is None:
            totals["unpriced_rows"] += 1
        else:
            totals["estimated_cost_total"] += float(row["estimated_cost"])
    totals["estimated_cost_total"] = round(totals["estimated_cost_total"], 8)
    return totals
