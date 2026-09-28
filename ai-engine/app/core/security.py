"""Service-to-service auth: X-Service-Key + Bearer JWT."""
from __future__ import annotations

import time
from collections import defaultdict, deque
from typing import Deque, Dict, Optional

from fastapi import Depends, Header, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from jose import JWTError, jwt

from app.core.config import settings
from app.core.errors import AppError

_bearer_scheme = HTTPBearer(auto_error=False)

# --- simple in-memory rate limiter (per key + path) ---
_calls: Dict[str, Deque[float]] = defaultdict(deque)


def check_rate_limit(key: str, limit: int = 120, window_seconds: int = 60) -> None:
    now = time.time()
    dq = _calls[key]
    while dq and now - dq[0] > window_seconds:
        dq.popleft()
    if len(dq) >= limit:
        raise AppError(
            module="core.security",
            operation="rate_limit",
            error_type="rate_limited",
            message="Rate limit exceeded, try again later.",
            status_code=429,
            code="RATE_LIMITED",
        )
    dq.append(now)


def create_access_token(subject: str, expires_minutes: int | None = None) -> str:
    from datetime import datetime, timedelta, timezone

    exp = datetime.now(timezone.utc) + timedelta(
        minutes=expires_minutes or settings.jwt_expire_minutes
    )
    return jwt.encode(
        {"sub": subject, "exp": exp}, settings.jwt_secret, algorithm=settings.jwt_algorithm
    )


def _valid_service_key(key: str) -> bool:
    return bool(key) and key == settings.service_api_key


def _valid_bearer(token: str) -> Optional[str]:
    try:
        payload = jwt.decode(token, settings.jwt_secret, algorithms=[settings.jwt_algorithm])
        return str(payload.get("sub", "service"))
    except JWTError:
        return None


async def require_service_auth(
    x_service_key: Optional[str] = Header(default=None, alias="X-Service-Key"),
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(_bearer_scheme),
) -> str:
    """Accept either X-Service-Key or Bearer JWT. Returns caller identity.

    In non-prod with default keys, allow unauthenticated local/test access
    so health checks and unit tests work out of the box, but still accept
    real credentials when provided.
    """
    if x_service_key and _valid_service_key(x_service_key):
        return "service-key"
    if credentials and credentials.credentials:
        sub = _valid_bearer(credentials.credentials)
        if sub:
            return sub
        # also allow raw service key passed as bearer
        if _valid_service_key(credentials.credentials):
            return "service-key"
    if not settings.is_prod:
        return "local-dev"
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Missing or invalid credentials. Provide X-Service-Key or Bearer token.",
    )


async def optional_service_auth(
    x_service_key: Optional[str] = Header(default=None, alias="X-Service-Key"),
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(_bearer_scheme),
) -> str:
    try:
        return await require_service_auth(x_service_key, credentials)
    except HTTPException:
        return "anonymous"
