"""Metric-threshold alerting.

Modules:
    rules       -- operators, the metric registry, the dedup state machine.
    notifiers   -- delivery (webhook, off by default).
    service     -- CRUD, evaluation orchestration, the periodic Celery task.

Nothing is imported here on purpose. ``app.alerts.rules`` must stay importable
without SQLAlchemy, Celery or pandas so the operator semantics and the alert
state machine can be unit tested in isolation; heavier modules are imported
explicitly by name.

The three tables this package owns (``alert_rules``, ``alerts``,
``alert_events``) are created by Alembic revision 0001 and declared in
``app/database/models.py``. This package is the first and only writer for them.
"""
from __future__ import annotations

__all__ = ["notifiers", "rules", "service"]
