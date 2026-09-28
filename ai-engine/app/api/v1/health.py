"""Health endpoints."""
from __future__ import annotations

from fastapi import APIRouter

from app.core.config import settings
from app.schemas.common import HealthResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    return HealthResponse(status="ok", app=settings.app_name, env=settings.app_env, version="1.0.0")


@router.get("/readiness")
def readiness() -> dict:
    checks = {"db": "unknown", "redis": "unknown"}
    try:
        from app.database.connection import engine
        from sqlalchemy import text

        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        checks["db"] = "up"
    except Exception as exc:
        checks["db"] = f"down: {exc}"
    try:
        import redis  # type: ignore

        r = redis.from_url(settings.redis_url, socket_connect_timeout=2)
        r.ping()
        checks["redis"] = "up"
    except Exception as exc:
        checks["redis"] = f"down: {exc}"
    ok = checks["db"] == "up"
    return {"ready": ok, "checks": checks}


@router.get("/liveness")
def liveness() -> dict:
    return {"alive": True}
