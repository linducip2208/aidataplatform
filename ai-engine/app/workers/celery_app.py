"""Celery app with Redis broker, routes, retry."""
from __future__ import annotations

import os

try:
    from celery import Celery  # type: ignore
    _HAS_CELERY = True
except Exception:  # pragma: no cover
    _HAS_CELERY = False
    Celery = None  # type: ignore

BROKER = os.getenv("REDIS_URL", "redis://localhost:6379/0")
BACKEND = BROKER

if _HAS_CELERY:
    celery_app = Celery("ai_engine", broker=BROKER, backend=BACKEND)
    celery_app.conf.update(
        task_serializer="json",
        result_serializer="json",
        accept_content=["json"],
        task_acks_late=True,
        worker_prefetch_multiplier=1,
        task_routes={
            "app.workers.tasks.import_file": {"queue": "ingestion"},
            "app.workers.tasks.train_model": {"queue": "ml"},
        },
        task_default_retry_delay=10,
        task_max_retries=3,
    )
else:  # offline stub so imports never fail
    class _Stub:  # type: ignore
        conf: dict = {}

        def task(self, *a, **k):
            def deco(fn):
                fn.delay = fn  # type: ignore
                fn.apply_async = lambda *aa, **kk: fn(*aa[1:] if len(aa) > 1 else [])  # type: ignore
                fn.update_state = lambda *aa, **kk: None  # type: ignore
                return fn

            if a and callable(a[0]):
                return deco(a[0])
            return deco

    celery_app = _Stub()  # type: ignore
