"""JSON structured logging with request_id, module, operation.

Two properties this module guarantees:

* **The request_id is live.** A plain ``LoggerAdapter`` snapshots ``extra`` in
  its constructor, so a logger created at import time froze ``request_id`` to
  ``"-"`` for the whole process. :class:`ContextLogger` injects it at emit time.
* **No credential reaches a log line.** Every message and every formatted
  traceback goes through :func:`~app.core.errors.redact_secrets`, so a DSN, a
  ``password=``, a bearer token or a service-key header value is masked even when
  a caller interpolates it into an f-string.
"""
from __future__ import annotations

import json
import logging
import sys
import uuid
from contextvars import ContextVar
from datetime import datetime, timezone
from typing import Any, Dict, MutableMapping, Tuple

from app.core.errors import redact_secrets

_request_id: ContextVar[str] = ContextVar("request_id", default="-")

# Keys logging.LogRecord already owns; passing them via extra raises KeyError.
_RESERVED_RECORD_KEYS = frozenset(
    logging.LogRecord("name", 0, "path", 0, "msg", (), None).__dict__
)
# Optional structured fields copied onto a record when the caller sets them.
_PASSTHROUGH_FIELDS = ("path", "method", "status", "duration_ms", "error_type")


def set_request_id(value: str | None = None) -> str:
    rid = value or uuid.uuid4().hex[:12]
    _request_id.set(rid)
    return rid


def get_request_id() -> str:
    try:
        return _request_id.get()
    except LookupError:
        return "-"


class ContextLogger(logging.LoggerAdapter):
    """LoggerAdapter that resolves the request_id at emit time, not at creation."""

    def process(  # type: ignore[override]
        self, msg: Any, kwargs: MutableMapping[str, Any]
    ) -> Tuple[Any, MutableMapping[str, Any]]:
        supplied = dict(kwargs.get("extra") or {})
        supplied.pop("message", None)
        supplied.pop("asctime", None)
        extra = {
            k: v for k, v in supplied.items() if k not in _RESERVED_RECORD_KEYS
        }
        extra["module_name"] = (self.extra or {}).get("module_name", self.logger.name)
        extra["operation"] = (self.extra or {}).get("operation", "")
        extra["request_id"] = get_request_id()
        kwargs["extra"] = extra
        return msg, kwargs


def get_logger(module: str, operation: str = "") -> ContextLogger:
    base = logging.getLogger(module)
    return ContextLogger(
        base,
        {"module_name": module, "operation": operation},
    )


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload: Dict[str, Any] = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "module": getattr(record, "module_name", record.name),
            "operation": getattr(record, "operation", ""),
            "request_id": getattr(record, "request_id", None) or get_request_id(),
            "message": redact_secrets(record.getMessage()),
        }
        for field in _PASSTHROUGH_FIELDS:
            value = getattr(record, field, None)
            if value is not None:
                payload[field] = value
        if record.exc_info and record.exc_info[0] is not None:
            payload["exc"] = redact_secrets(self.formatException(record.exc_info))
        return json.dumps(payload, ensure_ascii=False, default=str)


def configure_logging(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.handlers.clear()
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    root.addHandler(handler)
    root.setLevel(getattr(logging, str(level).upper(), logging.INFO))
    for noisy in ("uvicorn.access", "uvicorn.error", "sqlalchemy.engine", "celery"):
        logging.getLogger(noisy).setLevel(logging.WARNING)
