"""Celery app with Redis broker, routes, beat schedule, retry.

The compose services run:
    celery -A app.workers.celery_app.celery_app worker -Q default,imports,quality,ml,agent,rag
    celery -A app.workers.celery_app.celery_app beat --scheduler redbeat.RedBeatScheduler
so the `celery_app` symbol below is the `-A` target and every routed queue must
appear in that `-Q` list.
"""
from __future__ import annotations

import os
from typing import Any, Dict

try:
    from celery import Celery  # type: ignore
    from celery.schedules import crontab  # type: ignore

    _HAS_CELERY = True
except Exception:  # pragma: no cover
    _HAS_CELERY = False
    Celery = None  # type: ignore
    crontab = None  # type: ignore

# The broker/result URLs carry the Redis password: never log them.
BROKER = os.getenv("CELERY_BROKER_URL") or os.getenv("REDIS_URL", "redis://localhost:6379/0")
BACKEND = os.getenv("CELERY_RESULT_BACKEND") or BROKER

# Must stay a subset of the worker's -Q list.
QUEUES = ("default", "imports", "quality", "ml", "agent", "rag")

TASK_ROUTES: Dict[str, Dict[str, str]] = {
    "app.workers.tasks.import_file": {"queue": "imports"},
    "app.workers.tasks.validate_dataset": {"queue": "quality"},
    "app.workers.tasks.transform_dataset": {"queue": "imports"},
    "app.workers.tasks.train_model": {"queue": "ml"},
    "app.workers.tasks.generate_forecast": {"queue": "ml"},
    "app.workers.tasks.calculate_customer_features": {"queue": "ml"},
    "app.workers.tasks.anomaly_detection": {"queue": "ml"},
    "app.workers.tasks.generate_ai_report": {"queue": "agent"},
    "app.workers.tasks.generate_embeddings": {"queue": "rag"},
    "app.workers.tasks.scheduled_data_sync": {"queue": "default"},
    # Registered in app/alerts/service.py, which is only reached through
    # `include`. Routed explicitly rather than left on task_default_queue so
    # that changing the default cannot silently strand a per-minute task on a
    # queue the worker does not consume -- the failure that made an earlier
    # `import_file` invisible.
    "app.alerts.service.evaluate_alerts": {"queue": "agent"},
}

if _HAS_CELERY:
    celery_app = Celery(
        "ai_engine",
        broker=BROKER,
        backend=BACKEND,
        include=["app.workers.tasks", "app.alerts.service"],
    )
    _beat_schedule: Dict[str, Dict[str, Any]] = {}
    if crontab is not None:
        _beat_schedule["nightly-data-sync"] = {
            "task": "app.workers.tasks.scheduled_data_sync",
            "schedule": crontab(hour=1, minute=15),
        }
        _beat_schedule["hourly-ai-report"] = {
            "task": "app.workers.tasks.generate_ai_report",
            "schedule": crontab(minute=0),
        }
        # Metric threshold rules are cheap and time-sensitive; one pass a minute
        # keeps the open/resolved state machine's transition window short.
        _beat_schedule["alert-evaluation"] = {
            "task": "app.alerts.service.evaluate_alerts",
            "schedule": crontab(minute="*"),
        }
    celery_app.conf.update(
        task_serializer="json",
        result_serializer="json",
        accept_content=["json"],
        task_acks_late=True,
        task_reject_on_worker_lost=True,
        worker_prefetch_multiplier=1,
        broker_connection_retry_on_startup=True,
        timezone=os.getenv("TZ", "Asia/Jakarta"),
        enable_utc=True,
        task_default_queue="default",
        task_create_missing_queues=True,
        task_routes=TASK_ROUTES,
        task_default_retry_delay=10,
        task_max_retries=3,
        task_track_started=True,
        result_expires=3600,
        beat_schedule=_beat_schedule,
    )
else:  # offline stub so imports never fail
    class _Stub:  # type: ignore
        conf: dict = {"task_routes": TASK_ROUTES, "task_default_queue": "default"}

        def task(self, *a: Any, **k: Any) -> Any:
            def deco(fn: Any) -> Any:
                fn.delay = fn  # type: ignore
                fn.apply_async = lambda *aa, **kk: fn(*aa[1:] if len(aa) > 1 else [])  # type: ignore
                fn.update_state = lambda *aa, **kk: None  # type: ignore
                fn.retry = lambda *aa, **kk: None  # type: ignore
                return fn

            if a and callable(a[0]):
                return deco(a[0])
            return deco

    celery_app = _Stub()  # type: ignore
