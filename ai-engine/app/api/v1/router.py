"""Aggregate all v1 routers under /api/v1."""
from __future__ import annotations

from fastapi import APIRouter

from app.api.v1 import (
    ai, alerts, analytics, anomaly, customers, forecast, health, imports,
    inventory, models, rag, recommendation, training,
)

router = APIRouter(prefix="/api/v1")
router.include_router(health.router)
router.include_router(imports.router)
router.include_router(analytics.router)
router.include_router(forecast.router)
router.include_router(customers.router)
router.include_router(inventory.router)
router.include_router(anomaly.router)
router.include_router(recommendation.router)
router.include_router(models.router)
router.include_router(training.router)
router.include_router(ai.router)
router.include_router(rag.router)
router.include_router(alerts.router)
