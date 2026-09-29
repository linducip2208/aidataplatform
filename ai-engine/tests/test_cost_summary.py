"""Cost dashboard aggregation: totals, per-model and per-day breakdowns."""
from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest


@pytest.fixture()
def cost_warehouse(warehouse):
    """Session warehouse plus router-side tables, emptied tolerantly."""
    import app.ai.cost_tracking  # noqa: F401  (registers ai_usage on Base)
    try:
        import app.main  # noqa: F401  (registers every router-side model)
    except Exception:
        pass
    from sqlalchemy import delete

    from app.database.connection import Base

    Base.metadata.create_all(warehouse)
    with warehouse.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            try:
                conn.execute(delete(table))
            except Exception:
                pass
    return warehouse


@pytest.fixture()
def cost_session(cost_warehouse):
    from app.database.connection import SessionLocal

    session = SessionLocal()
    try:
        yield session
    finally:
        session.rollback()
        session.close()


def _row(db_session, **kw):
    from app.ai.cost_tracking import AIUsage

    args = {"model": "m", "provider": "p", "prompt_tokens": 100,
            "completion_tokens": 50, "total_tokens": 150,
            "estimated_cost": 0.001, "currency": "USD"}
    args.update(kw)
    row = AIUsage(**args)
    db_session.add(row)
    db_session.commit()
    return row


def test_summary_aggregates_models_and_days(cost_session):
    from app.ai.cost_tracking import cost_summary

    _row(cost_session, model="gpt-4o-mini", provider="openrouter",
         prompt_tokens=1000, completion_tokens=250, total_tokens=1250,
         estimated_cost=0.0003)
    _row(cost_session, model="gpt-4o-mini", provider="openrouter",
         prompt_tokens=500, completion_tokens=100, total_tokens=600,
         estimated_cost=0.0002)
    _row(cost_session, model="mystery", provider="local",
         prompt_tokens=10, completion_tokens=5, total_tokens=15,
         estimated_cost=None)

    res = cost_summary(cost_session, days=30)
    assert res["days"] == 30
    assert res["totals"]["turns"] == 3
    assert res["totals"]["total_tokens"] == 1865
    assert res["totals"]["estimated_cost_total"] == 0.0005
    assert res["totals"]["unpriced_rows"] == 1

    models = {m["model"]: m for m in res["by_model"]}
    assert models["gpt-4o-mini"]["turns"] == 2
    assert models["gpt-4o-mini"]["total_tokens"] == 1850
    assert models["mystery"]["estimated_cost_total"] == 0.0

    today = datetime.now(timezone.utc).date().isoformat()
    days = {d["day"]: d for d in res["by_day"]}
    assert days[today]["turns"] == 3


def test_summary_respects_the_window(cost_session):
    from app.ai.cost_tracking import cost_summary

    old = _row(cost_session)
    old.created_at = datetime.now(timezone.utc) - timedelta(days=60)
    cost_session.commit()
    _row(cost_session)

    res = cost_summary(cost_session, days=30)
    assert res["totals"]["turns"] == 1


def test_summary_endpoint(cost_warehouse):
    from fastapi.testclient import TestClient

    from app.core.config import settings
    from app.core.security import SERVICE_KEY_HEADER
    from app.main import create_app

    with TestClient(create_app()) as client:
        res = client.get("/api/v1/ai/usage/summary?days=7",
                         headers={SERVICE_KEY_HEADER: settings.service_api_key})
    assert res.status_code == 200
    body = res.json()
    assert body["success"] is True
    assert body["data"]["days"] == 7
    assert "totals" in body["data"]
    assert "by_model" in body["data"]
