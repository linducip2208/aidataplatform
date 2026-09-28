"""FastAPI app factory: request-ID, CORS, rate limiting, errors, metrics, OpenAPI."""
from __future__ import annotations

import hashlib
import ipaddress
import time
import uuid

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, PlainTextResponse
from prometheus_client import CONTENT_TYPE_LATEST, Counter, Histogram, generate_latest

from app.api.v1.router import router as v1_router
from app.core.config import settings
from app.core.errors import AppError, build_error_response, build_internal_error_response
from app.core.logging import configure_logging, get_logger, get_request_id, set_request_id
from app.core.security import SERVICE_KEY_HEADER, check_rate_limit

configure_logging(settings.log_level)
log = get_logger("app.main", "startup")

REQUESTS = Counter("http_requests_total", "Total requests", ["method", "path", "status"])
LATENCY = Histogram("http_request_latency_seconds", "Latency", ["path"])

# Ops probes and the Prometheus scrape. The container healthcheck hits
# /api/v1/health, nginx hits /health, Prometheus hits /metrics; none of them
# carry the service key, and none of them may ever be answered with a 429.
_UNRATE_LIMITED_PATHS = frozenset(
    {
        "/health",
        "/readiness",
        "/liveness",
        "/metrics",
        "/docs",
        "/redoc",
        "/openapi.json",
        "/api/v1/health",
        "/api/v1/readiness",
        "/api/v1/liveness",
    }
)


def _credential_fingerprint(credential: str) -> str:
    """Hash the presented credential.

    The raw secret must never be used as a limiter key (it would end up as a
    Redis key or a log line). An empty credential shares one anonymous bucket.
    """
    return hashlib.sha256((credential or "anon").encode("utf-8", "replace")).hexdigest()[:32]


def _is_internal_client(request: Request) -> bool:
    """True unless the peer is a routable public address.

    Compose publishes the engine on the host, so /metrics is reachable from
    outside unless it is gated. The peer address is the socket peer, not a
    header, so this cannot be spoofed. A non-IP peer (unix socket, in-process
    test client) is treated as internal.
    """
    client = request.client
    host = (client.host if client else "") or ""
    if not host:
        return True
    try:
        ip = ipaddress.ip_address(host)
    except ValueError:
        return True
    return ip.is_private or ip.is_loopback or ip.is_link_local


def create_app() -> FastAPI:
    app = FastAPI(
        title="AI/Data Engine",
        description="Enterprise AI platform engine: ingestion, analytics, ML, AI assistant, RAG.",
        version="1.0.0",
        docs_url="/docs" if settings.docs_enabled else None,
        redoc_url="/redoc" if settings.docs_enabled else None,
        openapi_url="/openapi.json" if settings.docs_enabled else None,
    )

    origins = settings.cors_origin_list
    if origins:
        app.add_middleware(
            CORSMiddleware,
            allow_origins=origins,
            # Server-to-server only: Laravel is the sole caller and it sends the
            # service key in a header, never a cookie. Credentials are off, so
            # the browser never attaches anything, and "*" is never used.
            allow_credentials=False,
            allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
            allow_headers=["Content-Type", "Accept", "X-Request-ID", SERVICE_KEY_HEADER],
        )
    else:
        # An empty CORS_ORIGINS must fail closed. Falling back to "*" would make
        # every origin acceptable on a service-authenticated API.
        log.warning("CORS_ORIGINS is empty; CORS middleware is disabled.")

    @app.middleware("http")
    async def request_id_mw(request: Request, call_next):  # type: ignore[no-untyped-def]
        rid = request.headers.get("X-Request-ID") or uuid.uuid4().hex[:12]
        set_request_id(rid)
        start = time.time()
        try:
            if request.url.path not in _UNRATE_LIMITED_PATHS:
                # Same resolved header name the auth dependency reads, so a
                # renamed SERVICE_API_KEY_HEADER buckets per credential again.
                credential = request.headers.get(SERVICE_KEY_HEADER, "")
                key = _credential_fingerprint(credential) + ":" + request.url.path
                check_rate_limit(key, limit=settings.rate_limit_per_minute)
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
        rid = get_request_id()
        return JSONResponse(status_code=exc.status_code,
                            content=build_error_response(
                                module=exc.module, operation=exc.operation,
                                error_type=exc.error_type, message=exc.message,
                                technical=exc.technical, request_id=rid,
                                resolution=exc.resolution, code=exc.code,
                                internal=exc.status_code >= 500))

    @app.exception_handler(Exception)
    async def unhandled_handler(request: Request, exc: Exception):  # type: ignore[no-untyped-def]
        rid = get_request_id()
        # Full detail stays server-side, redacted; str(exc) on a SQLAlchemy
        # connection error contains the DSN and the password.
        log.error(
            "unhandled error",
            extra={"path": request.url.path, "method": request.method,
                   "error_type": type(exc).__name__},
            exc_info=exc,
        )
        return JSONResponse(status_code=500,
                            content=build_internal_error_response(
                                module="app", operation="request", request_id=rid))

    app.include_router(v1_router)

    if settings.metrics_enabled:

        @app.get("/metrics")
        def metrics(request: Request) -> PlainTextResponse:
            if not settings.metrics_allow_public and not _is_internal_client(request):
                # 404 rather than 403: a public scrape path should not be
                # advertised to whoever is scanning for it.
                return PlainTextResponse("Not Found", status_code=404)
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
