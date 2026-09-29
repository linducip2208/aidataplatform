"""Service-to-service auth: X-Service-Key + Bearer JWT (fail-closed)."""
from __future__ import annotations

import hashlib
import re
import secrets
import time
from collections import OrderedDict, deque
from typing import Deque, Optional

from fastapi import Depends, Header, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from jose import JWTError, jwt

from app.core.config import settings
from app.core.errors import AppError
from app.core.logging import get_logger

log = get_logger("app.core.security", "auth")

_bearer_scheme = HTTPBearer(auto_error=False)

DEFAULT_SERVICE_KEY_HEADER = "X-Service-Key"

# Values that mean "the operator never configured a secret". They are treated as
# NOT configured, so a deployment that forgets its env file rejects every caller
# instead of accepting anyone who read the repository.
_PLACEHOLDER_SECRETS = frozenset(
    {
        "",
        "change-me",
        "change-me-service-key",
        "change-me-jwt-secret",
        "changeme",
        "secret",
    }
)

# An operator must not be able to point SERVICE_API_KEY_HEADER at a header that
# is already load-bearing, or the credential silently shadows/conflicts with it.
_FORBIDDEN_KEY_HEADERS = frozenset(
    {
        "authorization",
        "proxy-authorization",
        "cookie",
        "set-cookie",
        "host",
        "content-length",
        "content-type",
        "connection",
        "transfer-encoding",
        "accept",
        "user-agent",
        "x-forwarded-for",
        "x-forwarded-host",
        "x-forwarded-proto",
        "x-real-ip",
        "x-request-id",
        "x-client",
    }
)

# RFC 7230 token characters: a header name may not contain spaces, colons or
# control characters, otherwise Header(alias=...) is unusable or ambiguous.
_HEADER_TOKEN_RE = re.compile(r"^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$")

# --- rate limiter (per credential fingerprint + per path) ---
_MAX_RATE_KEYS = 20_000
_SHARED_PATH_FACTOR = 3
_calls: "OrderedDict[str, Deque[float]]" = OrderedDict()
_path_calls: "OrderedDict[str, Deque[float]]" = OrderedDict()


def resolve_service_key_header(raw: Optional[str] = None) -> str:
    """Return a safe header name for the service key, defaulting to X-Service-Key.

    Falls back to the default for blank, non-token or reserved names so a bad
    operator value can never redirect the credential onto a normal header.
    """
    candidate = (raw if raw is not None else settings.service_api_key_header).strip()
    if not candidate:
        return DEFAULT_SERVICE_KEY_HEADER
    if not _HEADER_TOKEN_RE.match(candidate):
        return DEFAULT_SERVICE_KEY_HEADER
    if candidate.lower() in _FORBIDDEN_KEY_HEADERS:
        return DEFAULT_SERVICE_KEY_HEADER
    return candidate


SERVICE_KEY_HEADER = resolve_service_key_header()


def _is_placeholder(value: Optional[str]) -> bool:
    return (value or "").strip().lower() in _PLACEHOLDER_SECRETS


def configured_service_key() -> str:
    """The usable service key, or "" when none is configured (never the default)."""
    if _is_placeholder(settings.service_api_key):
        return ""
    return (settings.service_api_key or "").strip()


def _configured_jwt_secret() -> str:
    if _is_placeholder(settings.jwt_secret):
        return ""
    return (settings.jwt_secret or "").strip()


def _rate_limited() -> AppError:
    return AppError(
        module="core.security",
        operation="rate_limit",
        error_type="rate_limited",
        message="Rate limit exceeded, try again later.",
        status_code=429,
        code="RATE_LIMITED",
    )


def _fingerprint(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8", "replace")).hexdigest()[:32]


def _split_key(key: str) -> tuple[str, str]:
    """Split the "<credential>:<path>" key built by the middleware."""
    credential, sep, path = key.rpartition(":")
    if not sep:
        return "", key
    return credential, path


def _evict(store: "OrderedDict[str, Deque[float]]", now: float, window_seconds: int) -> None:
    if len(store) <= _MAX_RATE_KEYS:
        return
    for stale in [k for k, dq in store.items() if not dq or now - dq[-1] > window_seconds]:
        store.pop(stale, None)
    while len(store) > _MAX_RATE_KEYS:
        store.popitem(last=False)


def _hit(
    store: "OrderedDict[str, Deque[float]]",
    key: str,
    limit: int,
    window_seconds: int,
    now: float,
) -> None:
    dq = store.get(key)
    if dq is None:
        dq = deque()
        store[key] = dq
    else:
        store.move_to_end(key)
    while dq and now - dq[0] > window_seconds:
        dq.popleft()
    if len(dq) >= limit:
        _evict(store, now, window_seconds)
        raise _rate_limited()
    dq.append(now)
    _evict(store, now, window_seconds)


def check_rate_limit(key: str, limit: int = 120, window_seconds: int = 60) -> None:
    """Sliding-window limiter. Fails closed: any internal error becomes a 429.

    Enforced twice: once per credential fingerprint and once per path regardless
    of credential, so an attacker cannot mint a fresh bucket by varying a
    header value it does not know. The credential is only ever stored hashed.
    """
    try:
        now = time.time()
        credential, path = _split_key(key or "")
        if not path:
            path = "unknown"
        _hit(_calls, _fingerprint(credential) + "|" + path, limit, window_seconds, now)
        _hit(_path_calls, path, limit * _SHARED_PATH_FACTOR, window_seconds, now)
    except AppError:
        raise
    except Exception as exc:  # pragma: no cover - defensive
        log.warning(f"rate limiter failure, failing closed: {type(exc).__name__}")
        raise _rate_limited() from exc


def create_access_token(subject: str, expires_minutes: int | None = None) -> str:
    from datetime import datetime, timedelta, timezone

    secret = _configured_jwt_secret()
    if not secret:
        raise ValueError("JWT secret is not configured; refusing to mint a token.")
    exp = datetime.now(timezone.utc) + timedelta(
        minutes=expires_minutes or settings.jwt_expire_minutes
    )
    return jwt.encode({"sub": subject, "exp": exp}, secret, algorithm=settings.jwt_algorithm)


def _valid_service_key(key: Optional[str]) -> bool:
    """Fail-closed constant-time comparison. No configured key means no access."""
    configured = configured_service_key()
    if not configured:
        return False
    if not key:
        return False
    # Both sides encoded to UTF-8 bytes first: secrets.compare_digest raises
    # TypeError on str operands containing non-ASCII, which would turn a wrong
    # key into a 500 instead of a 401 (pinned by
    # test_non_ascii_configured_key_compares_through_utf8_bytes).
    return secrets.compare_digest(key.encode("utf-8"), configured.encode("utf-8"))


def log_auth_failure(
    reason: str,
    *,
    header: Optional[str] = None,
    has_key: bool = False,
    has_bearer: bool = False,
) -> None:
    """Structured audit record for a rejected service-auth attempt.

    The log carries the REASON and the shape of the attempt only — never the
    presented value, never the configured secret, never a fingerprint that
    could be replayed. `reason` is one of: `unconfigured`, `missing`, or
    `invalid`. The JSON formatter in app.core.logging redacts anything
    credential-shaped that a caller interpolates, so even a buggy reason
    string cannot leak the key.
    """
    allowed = {"unconfigured", "missing", "invalid"}
    safe_reason = reason if reason in allowed else "invalid"
    log.warning(
        "auth.failed reason=%s header=%s has_key=%d has_bearer=%d",
        safe_reason,
        header or SERVICE_KEY_HEADER,
        1 if has_key else 0,
        1 if has_bearer else 0,
    )


def _valid_bearer(token: str) -> Optional[str]:
    secret = _configured_jwt_secret()
    if not secret:
        return None
    try:
        payload = jwt.decode(token, secret, algorithms=[settings.jwt_algorithm])
    except JWTError:
        return None
    sub = payload.get("sub")
    return str(sub) if sub else "service"


async def require_service_auth(
    x_service_key: Optional[str] = Header(default=None, alias=SERVICE_KEY_HEADER),
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(_bearer_scheme),
) -> str:
    """Accept a valid service key or a valid Bearer JWT. Returns caller identity.

    There is no environment in which a missing or placeholder secret accepts a
    request: with nothing configured every guarded endpoint answers 401.
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
    # Audit hook (A8): every rejection is logged with reason + attempt shape,
    # never with a secret value. Branches are ordered so the reason names the
    # actual failure: unconfigured first (nothing could succeed), then
    # missing, then invalid.
    if not configured_service_key():
        log_auth_failure(
            "unconfigured",
            header=SERVICE_KEY_HEADER,
            has_key=bool(x_service_key),
            has_bearer=bool(credentials and credentials.credentials),
        )
    elif not x_service_key and not (credentials and credentials.credentials):
        log_auth_failure("missing", header=SERVICE_KEY_HEADER)
    else:
        log_auth_failure(
            "invalid",
            header=SERVICE_KEY_HEADER,
            has_key=bool(x_service_key),
            has_bearer=bool(credentials and credentials.credentials),
        )
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail=(
            "Missing or invalid credentials. Provide "
            f"{SERVICE_KEY_HEADER} or Bearer token."
        ),
    )


async def optional_service_auth(
    x_service_key: Optional[str] = Header(default=None, alias=SERVICE_KEY_HEADER),
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(_bearer_scheme),
) -> str:
    """Same as require_service_auth but degrades to "anonymous" on rejection."""
    try:
        return await require_service_auth(x_service_key, credentials)
    except HTTPException:
        return "anonymous"
