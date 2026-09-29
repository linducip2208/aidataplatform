"""AI layer: LLM client, business assistant agent, RAG and executive reporting.

Submodules are imported explicitly (``from app.ai import llm``) rather than re-exported
here, so importing the package never pulls in pandas, SQLAlchemy, numpy or httpx.
"""
from __future__ import annotations

__all__ = ["agent", "cost_tracking", "llm", "rag", "reporting", "sql_guard", "tools"]
