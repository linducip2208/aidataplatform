"""Alert delivery.

Honest by construction: with no channel configured the notifier does nothing and
says so, and it never records a delivery it did not make. There is no SMTP,
Twilio or Slack client here -- ``requirements.txt`` is owned elsewhere and
``httpx`` is the only HTTP dependency the engine already has.

The channel is a single optional webhook URL read from the environment, OFF by
default:

    ALERT_WEBHOOK_URL   https://hooks.example/...   empty or unset = disabled

No entry needs to be added to ``app/core/config.py``; the value is read with
``os.environ`` here so this package stays self-contained. If the project wants it
in the settings object, the field to add is::

    alert_webhook_url: str = Field(default="")

Delivery failures never propagate: an unreachable webhook must not abort an
evaluation run or lose the alert row that was already written. The failure is
logged (URL redacted, body never logged) and returned as
``DeliveryResult(sent=False, ...)``.
"""
from __future__ import annotations

import logging
import os
from dataclasses import dataclass
from typing import Any, Mapping, Optional
from urllib.parse import urlsplit

from app.core.errors import redact_secrets

__all__ = [
    "CHANNEL_NONE",
    "CHANNEL_WEBHOOK",
    "DeliveryResult",
    "WEBHOOK_ENV",
    "WEBHOOK_TIMEOUT_SECONDS",
    "notify",
    "webhook_configured",
    "webhook_url",
]

log = logging.getLogger("app.alerts.notifiers")

WEBHOOK_ENV = "ALERT_WEBHOOK_URL"
WEBHOOK_TIMEOUT_SECONDS = 5.0
CHANNEL_WEBHOOK = "webhook"
CHANNEL_NONE = "none"

NOT_CONFIGURED = "webhook_not_configured"
FAILED = "delivery_failed"


@dataclass(frozen=True)
class DeliveryResult:
    """Outcome of one delivery attempt.

    ``channel`` is ``"webhook"`` when a webhook is configured and ``"none"``
    otherwise; ``sent`` is True only on a 2xx response; ``detail`` is a short,
    redacted, non-sensitive string safe to store in ``alert_events.payload``.
    """

    channel: str
    sent: bool
    detail: str

    def as_payload(self) -> dict:
        """Return the JSON-safe dict to embed in an ``alert_events.payload``."""
        return {"channel": self.channel, "sent": self.sent, "detail": self.detail}


def webhook_url() -> str:
    """Return the configured webhook URL, or "" when disabled.

    Only http/https is accepted: a ``file://`` or ``ftp://`` value would make
    ``httpx`` reach for a transport nobody intends.
    """
    raw = (os.environ.get(WEBHOOK_ENV) or "").strip()
    if not raw:
        return ""
    try:
        parts = urlsplit(raw)
    except ValueError:
        return ""
    if parts.scheme not in ("http", "https") or not parts.netloc:
        log.warning("%s is set but is not an http(s) URL; alert delivery is off.",
                    WEBHOOK_ENV)
        return ""
    return raw


def webhook_configured() -> bool:
    """True when a delivery channel exists."""
    return bool(webhook_url())


def _safe_label(url: str) -> str:
    """Return host + path of ``url`` with any credentials redacted.

    A webhook URL frequently carries a token in the path or as basic-auth user
    info, so it is reduced to scheme://host/path-with-credentials-removed before
    it can reach a log line.
    """
    try:
        parts = urlsplit(url)
    except ValueError:  # pragma: no cover - defensive
        return "<webhook>"
    if parts.username or parts.password:
        host = parts.hostname or ""
        if parts.port:
            host = f"{host}:{parts.port}"
        return f"{parts.scheme}://{host}{redact_secrets(parts.path)}"
    return f"{parts.scheme}://{parts.netloc}{parts.path}"


def _post(url: str, body: Mapping[str, Any]) -> DeliveryResult:
    """POST ``body`` as JSON. Returns a result; never raises."""
    try:
        import httpx
    except Exception as exc:  # pragma: no cover - httpx is a hard dependency
        log.warning("httpx unavailable (%s); alert not delivered.", type(exc).__name__)
        return DeliveryResult(CHANNEL_NONE, False, "httpx_unavailable")

    try:
        response = httpx.post(url, json=dict(body), timeout=WEBHOOK_TIMEOUT_SECONDS)
    except Exception as exc:
        # httpx exception text can carry the URL; redact before logging.
        log.warning("alert webhook %s failed: %s", _safe_label(url),
                    redact_secrets(f"{type(exc).__name__}: {exc}")[:200])
        return DeliveryResult(CHANNEL_WEBHOOK, False, FAILED)

    if 200 <= response.status_code < 300:
        log.info("alert webhook %s accepted the notification (HTTP %s)",
                 _safe_label(url), response.status_code)
        return DeliveryResult(CHANNEL_WEBHOOK, True, f"http_{response.status_code}")
    log.warning("alert webhook %s returned HTTP %s", _safe_label(url),
                response.status_code)
    return DeliveryResult(CHANNEL_WEBHOOK, False, f"http_{response.status_code}")


def notify(event: str, payload: Mapping[str, Any], *,
           url: Optional[str] = None) -> DeliveryResult:
    """Deliver one alert event, or do nothing.

    Returns a :class:`DeliveryResult` describing what happened. With no
    configured channel the result is ``(channel="none", sent=False,
    detail="webhook_not_configured")`` -- an explicit "nothing was sent" rather
    than a delivery that never happened. The payload is only serialised when a
    channel exists, and it is never logged.
    """
    target = url if url is not None else webhook_url()
    if not target:
        return DeliveryResult(CHANNEL_NONE, False, NOT_CONFIGURED)
    return _post(target, {"event": event, "payload": dict(payload or {})})
