"""Structured application errors.

The envelope is a contract with ``application/app/Services/AiEngineClient.php``,
which reads ``error.message`` first, then ``detail``, then ``message``. The key
set, key order and the fact that ``error.message`` is always a non-empty string
are therefore fixed: ``build_error_response`` may gain keyword arguments, it may
not change the shape of the returned dict.

An internal failure must never carry a DSN, a password, a bearer token or a
stack trace to the client. :func:`redact_secrets` is applied to ``message``,
``technical`` and every string inside ``details``, and ``internal=True``
additionally replaces the message with a fixed hint, so only the server log ever
sees the exception. The leak that matters is the one nobody writes on purpose:
``AppError(..., message=str(exc))``, where a driver error string embeds the
connection string.
"""
from __future__ import annotations

import re
from typing import Any, Dict, Optional

from app.core.config import settings

INTERNAL_ERROR_TYPE = "internal"
INTERNAL_ERROR_MESSAGE = "Internal server error"
INTERNAL_ERROR_CODE = "INTERNAL_ERROR"
INTERNAL_ERROR_TECHNICAL = "Detail suppressed; correlate on request_id in the engine log."
# `error.message` must never be empty: the client reads it first and an empty
# string falls through to a generic status message the operator cannot act on.
FALLBACK_ERROR_MESSAGE = "Permintaan tidak dapat diproses."
REDACTED = "***"

# scheme://user:password@host -- the user part is optional because the Redis
# DSN this project ships is `redis://:<password>@cache:6379/0`.
_URL_CREDENTIALS_RE = re.compile(
    r"(?P<scheme>[A-Za-z][A-Za-z0-9+.\-]*://)(?P<user>[^/\s:@]*)"
    r"(?::(?P<pw>[^/\s@]*))?@"
)
# The key may be a compound env-var name (DB_PASSWORD, X-Service-Key,
# AWS_SECRET_ACCESS_KEY, CLIENT_SECRET), so leading words are consumed before
# the secret word, and the value may be quoted (`password = "hunter2"`).
_SECRET_NAME = (
    r"pass(?:word|wd)?s?|pwds?|secrets?|tokens?|authorizations?|credentials?"
    r"|access[\-_]?key(?:[\-_]?id)?"
    r"|(?:api|access|secret|service|private|client|app|auth|session|signing"
    r"|encryption|db)[\-_]?(?:key|token|secret|password|pwd)s?"
)
_KV_SECRET_RE = re.compile(
    r"(?i)(?<![A-Za-z0-9])(?P<prefix>(?:[A-Za-z0-9]*[\-_])*?)"
    rf"(?P<name>{_SECRET_NAME})(?![A-Za-z0-9])"
    r"(?P<sep>\s*[:=]\s*)"
    r"(?P<value>\"[^\"]*\"|'[^']*'|[^\s,;)\]}\"']+)"
)
# A field name on its own carries no `key=value` context, so `details` entries
# are matched on the name: `{"api_key": ...}` redacts on the key, not the value.
_SECRET_FIELD_RE = re.compile(
    r"(?i)(?<![A-Za-z0-9])(?:[A-Za-z0-9]*[\-_])*?"
    rf"(?:{_SECRET_NAME})(?![A-Za-z0-9])"
)
# Authorization: Bearer <jwt>
_BEARER_RE = re.compile(r"(?i)\b(bearer|basic)\s+([A-Za-z0-9\-._~+/=]{8,})")
# "X-Service-Key: secret" style header dumps, one per line
_HEADER_LINE_RE = re.compile(
    r"(?im)^(?P<name>[A-Za-z0-9\-_]*(?:service[\-_]?key|authorization|cookie|token|"
    r"secret|api[\-_]?key|password)[A-Za-z0-9\-_]*)(?P<sep>\s*:\s*)(?P<value>.+)$"
)


def redact_secrets(value: Optional[str]) -> str:
    """Remove credentials from a string that is about to leave the process.

    Order matters: the bearer rule has to run before the key/value rule,
    otherwise `Authorization: Bearer <token>` loses the word "Bearer" first and
    the token itself survives.
    """
    if not value:
        return ""
    text = str(value)
    # A URL without a password carries no credential, so it is left readable.
    text = _URL_CREDENTIALS_RE.sub(
        lambda m: f"{m.group('scheme')}{m.group('user')}:{REDACTED}@"
        if m.group("pw")
        else m.group(0),
        text,
    )
    text = _BEARER_RE.sub(lambda m: f"{m.group(1)} {REDACTED}", text)
    text = _KV_SECRET_RE.sub(
        lambda m: f"{m.group('prefix')}{m.group('name')}{m.group('sep')}{REDACTED}", text
    )
    text = _HEADER_LINE_RE.sub(
        lambda m: f"{m.group('name')}{m.group('sep')}{REDACTED}", text
    )
    return text


def _redact_value(value: Any) -> Any:
    """Recursively redact a structured payload (`details`).

    `details` is assembled by the call site from whatever the failing layer had
    to hand, so it is redacted on the way out rather than trusted.
    """
    if isinstance(value, str):
        return redact_secrets(value)
    if isinstance(value, dict):
        return {
            key: REDACTED
            if isinstance(key, str) and _SECRET_FIELD_RE.search(key)
            else _redact_value(item)
            for key, item in value.items()
        }
    if isinstance(value, (list, tuple, set)):
        return [_redact_value(item) for item in value]
    return value


class AppError(Exception):
    def __init__(
        self,
        module: str,
        operation: str,
        error_type: str,
        message: str,
        technical: str = "",
        resolution: str = "",
        status_code: int = 400,
        code: str = "APP_ERROR",
        details: Optional[Dict[str, Any]] = None,
    ) -> None:
        super().__init__(message)
        self.module = module
        self.operation = operation
        self.error_type = error_type
        self.message = message
        self.technical = technical or message
        self.resolution = resolution
        self.status_code = status_code
        self.code = code
        self.details = dict(details or {})


def build_error_response(
    *,
    module: str,
    operation: str,
    error_type: str,
    message: str,
    technical: str = "",
    request_id: str = "-",
    resolution: str = "",
    code: str = "APP_ERROR",
    details: Optional[Dict[str, Any]] = None,
    internal: bool = False,
) -> Dict[str, Any]:
    """Build the `{"success": false, "error": {...}}` envelope.

    ``internal=True`` is for unexpected server-side failures: the message is
    replaced by a fixed hint rather than merely redacted, because a redacted
    `str(exc)` still describes internals -- a driver error carries the DSN, and
    an exception raised around a request carries the tenant's file path.
    ``request_id`` is the only correlation handle offered.

    ``technical`` is kept in the body: it is redacted, it is a fixed constant on
    every 5xx, and the Laravel client never reads it, so nothing is disclosed by
    its presence.
    """
    safe_message = redact_secrets(message) if message else ""
    safe_technical = redact_secrets(technical) if technical else ""
    if internal:
        safe_message = INTERNAL_ERROR_MESSAGE
        safe_technical = INTERNAL_ERROR_TECHNICAL
    safe_message = safe_message or FALLBACK_ERROR_MESSAGE
    return {
        "success": False,
        "error": {
            "module": module,
            "operation": operation,
            "error_type": error_type,
            "code": code,
            "message": safe_message,
            "technical": safe_technical or safe_message,
            "request_id": request_id,
            "resolution": resolution,
            "details": _redact_value(details) if details else {},
        },
    }


def build_internal_error_response(
    *,
    module: str,
    operation: str,
    request_id: str = "-",
    message: str = INTERNAL_ERROR_MESSAGE,
) -> Dict[str, Any]:
    """Envelope for an unhandled exception. Carries no exception detail.

    ``message`` is accepted for call-site compatibility only: ``internal=True``
    overrides it, so passing ``str(exc)`` here cannot leak one.
    """
    return build_error_response(
        module=module,
        operation=operation,
        error_type=INTERNAL_ERROR_TYPE,
        message=message,
        technical=INTERNAL_ERROR_TECHNICAL,
        request_id=request_id,
        resolution="Periksa log server lalu ulangi.",
        code=INTERNAL_ERROR_CODE,
        internal=True,
    )


COMMON_RESOLUTIONS = {
    "validation": "Periksa kembali payload / format file, lalu ulangi permintaan.",
    # Header name is operator-configurable (SERVICE_API_KEY_HEADER); read it from
    # settings so the hint never contradicts the header the engine expects.
    "auth": f"Pastikan {settings.service_api_key_header} atau Bearer token valid.",
    "not_found": "Pastikan ID / nama resource benar.",
    "db": "Periksa koneksi DATABASE_URL dan migrasi alembic.",
    "llm": "Periksa LLM_API_KEY / LLM_BASE_URL lalu coba lagi.",
}
