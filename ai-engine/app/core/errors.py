"""Structured application errors.

The envelope is a contract with ``application/app/Services/AiEngineClient.php``,
which reads ``error.message`` first, then ``detail``, then ``message``. The key
set, key order and the fact that ``error.message`` is always a non-empty string
are therefore fixed: ``build_error_response`` may gain keyword arguments, it may
not change the shape of the returned dict.

An internal failure must never carry a DSN, a password, a bearer token or a
stack trace to the client. ``redact_secrets`` is applied to every ``technical``
string, and ``internal=True`` additionally replaces it with a fixed hint, so
only the server log ever sees the exception.
"""
from __future__ import annotations

import re
from typing import Any, Dict, Optional

from app.core.config import settings

INTERNAL_ERROR_TYPE = "internal"
INTERNAL_ERROR_MESSAGE = "Internal server error"
INTERNAL_ERROR_CODE = "INTERNAL_ERROR"
INTERNAL_ERROR_TECHNICAL = "Detail suppressed; correlate on request_id in the engine log."
REDACTED = "***"

# scheme://user:password@host
_URL_CREDENTIALS_RE = re.compile(
    r"(?P<scheme>[A-Za-z][A-Za-z0-9+.\-]*://)(?P<user>[^/\s:@]+)(?::(?P<pw>[^/\s@]*))?@"
)
# password=..., "api_key": "...", secret: ..., token=...
_KV_SECRET_RE = re.compile(
    r"(?i)\b(pass(?:word|wd)?|pwd|secret|token|api[\-_]?key|access[\-_]?key|"
    r"service[\-_]?key|authorization)\b(\s*[:=]\s*|\"\s*:\s*\")([^\s,;\"'}}\]]+)"
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
    text = _URL_CREDENTIALS_RE.sub(
        lambda m: f"{m.group('scheme')}{m.group('user')}:{REDACTED}@", str(value)
    )
    text = _BEARER_RE.sub(lambda m: f"{m.group(1)} {REDACTED}", text)
    text = _KV_SECRET_RE.sub(lambda m: f"{m.group(1)}{m.group(2)}{REDACTED}", text)
    text = _HEADER_LINE_RE.sub(
        lambda m: f"{m.group('name')}{m.group('sep')}{REDACTED}", text
    )
    return text


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

    ``internal=True`` is for unexpected server-side failures: the detail is
    dropped entirely rather than redacted, because even a redacted message can
    describe internals. ``request_id`` is the only correlation handle offered.
    """
    safe_technical = redact_secrets(technical) if technical else ""
    if internal:
        safe_technical = INTERNAL_ERROR_TECHNICAL
        message = message or INTERNAL_ERROR_MESSAGE
    return {
        "success": False,
        "error": {
            "module": module,
            "operation": operation,
            "error_type": error_type,
            "code": code,
            "message": message,
            "technical": safe_technical or redact_secrets(message) or message,
            "request_id": request_id,
            "resolution": resolution,
            "details": details or {},
        },
    }


def build_internal_error_response(
    *,
    module: str,
    operation: str,
    request_id: str = "-",
    message: str = INTERNAL_ERROR_MESSAGE,
) -> Dict[str, Any]:
    """Envelope for an unhandled exception. Carries no exception detail."""
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
