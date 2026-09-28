"""FastAPI app factory: request-ID, CORS, rate limiting, errors, metrics, OpenAPI."""
from __future__ import annotations

import time
import uuid

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, PlainTextResponse
from prometheus_client import CONTENT_TYPE_LATEST, Counter, Histogram, generate_latest

from app.api.v1.router import router as v1_router
from app.core.config import settings
from app.core.errors import AppError, build_error_response
from app.core.logging import configure_logging, get_logger, set_request_id

configure_logging(settings.log_level)
log = get_logger("app.main", "startup")

REQUESTS = Counter("http_requests_total", "Total requests", ["method", "path", "status"])
LATENCY = Histogram("http_request_latency_seconds", "Latency", ["path"])


def create_app() -> FastAPI:
    app = FastAPI(
        title="AI/Data Engine",
        description="Enterprise AI platform engine: ingestion, analytics, ML, AI assistant, RAG.",
        version="1.0.0",
        docs_url="/docs",
        redoc_url="/redoc",
        openapi_url="/openapi.json",
    )
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origin_list or ["*"],
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

    @app.middleware("http")
    async def request_id_mw(request: Request, call_next):  # type: ignore[no-untyped-def]
        rid = request.headers.get("X-Request-ID") or uuid.uuid4().hex[:12]
        set_request_id(rid)
        start = time.time()
        try:
            from app.core.security import check_rate_limit

            key = request.headers.get("X-Service-Key", "anon") + ":" + request.url.path
            check_rate_limit(key)
        except AppError as exc:
            return JSONResponse(status_code=exc.status_code,
                                content=build_error_response(
                                    module=exc.module, operation=exc.operation,
                                    error_type=exc.error_type, message=exc.message,
                                    technical=exc.technical, request_id=rid,
                                    resolution=exc.resolution, code=exc.code))
        response = await call_next(request)
        response.headers["X-Request-ID"] = rid
        try:
            REQUESTS.labels(request.method, request.url.path, str(response.status_code)).inc()
            LATENCY.labels(request.url.path).observe(time.time() - start)
        except Exception:
            pass
        return response

    @app.exception_handler(AppError)
    async def app_error_handler(request: Request, exc: AppError):  # type: ignore[no-untyped-def]
        rid = request.headers.get("X-Request-ID", "-")
        return JSONResponse(status_code=exc.status_code,
                            content=build_error_response(
                                module=exc.module, operation=exc.operation,
                                error_type=exc.error_type, message=exc.message,
                                technical=exc.technical, request_id=rid,
                                resolution=exc.resolution, code=exc.code))

    @app.exception_handler(Exception)
    async def unhandled_handler(request: Request, exc: Exception):  # type: ignore[no-untyped-def]
        rid = request.headers.get("X-Request-ID", "-")
        log.error(f"unhandled: {exc}")
        return JSONResponse(status_code=500, content=build_error_response(
            module="app", operation="request", error_type="internal",
            message="Internal server error", technical=str(exc), request_id=rid,
            resolution="Periksa log server lalu ulangi."))

    app.include_router(v1_router)

    @app.get("/metrics")
    def metrics() -> PlainTextResponse:
        return PlainTextResponse(generate_latest().decode("utf-8"), media_type=CONTENT_TYPE_LATEST)

    # root-level aliases (no auth) for ops probes
    @app.get("/health")
    def _health() -> dict:
        return {"status": "ok", "app": settings.app_name, "env": settings.app_env, "version": "1.0.0"}

    @app.get("/readiness")
    def _ready() -> dict:
        return {"ready": True}

    @app.get("/liveness")
    def _live() -> dict:
        return {"alive": True}

    return app


app = create_app()
