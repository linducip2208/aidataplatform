"""Enterprise security pins for app.core.security (A8).

Covers what test_security.py / test_auth_failclosed.py do not:

* every placeholder-shaped secret is "not configured" (parametrised, with the
  padded/cased variants operators actually paste);
* a wrong header name carries no credential (unit + HTTP edge);
* every rejection emits a structured `auth.failed` audit log whose shape is
  pinned and which never contains a secret value.

Never weaken an assertion here to make a test pass. A failure is the finding.
"""
import asyncio
import logging

import pytest
from fastapi import HTTPException


def _rejects(*args, **kwargs) -> HTTPException:
    from app.core.security import require_service_auth

    with pytest.raises(HTTPException) as exc:
        asyncio.run(require_service_auth(*args, **kwargs))
    assert exc.value.status_code == 401, exc.value.detail
    return exc.value


PLACEHOLDER_KEYS = [
    "",
    "change-me",
    "change-me-service-key",
    "change-me-jwt-secret",
    "changeme",
    "secret",
    "  change-me  ",
    "Change-Me-Service-Key",
    "SECRET",
]


@pytest.mark.parametrize("placeholder", PLACEHOLDER_KEYS)
def test_every_placeholder_key_is_not_configured(placeholder):
    from app.core.security import _is_placeholder, configured_service_key
    from app.core.config import settings

    assert _is_placeholder(placeholder) is True

    original = settings.service_api_key
    settings.service_api_key = placeholder
    try:
        assert configured_service_key() == ""
        # Even presenting the exact configured string must not open the door.
        _rejects(placeholder, None)
        _rejects(None, None)
    finally:
        settings.service_api_key = original


@pytest.mark.parametrize("placeholder", ["change-me-jwt-secret", "changeme", ""])
def test_placeholder_jwt_secret_never_verifies(placeholder):
    from app.core.config import settings
    from app.core.security import _configured_jwt_secret

    original = settings.jwt_secret
    settings.jwt_secret = placeholder
    try:
        assert _configured_jwt_secret() == ""
    finally:
        settings.jwt_secret = original


def test_wrong_header_name_carries_no_credential():
    """The header name is configurable; a renamed credential sent under the
    wrong name is the same as no credential at all."""
    from app.core.security import resolve_service_key_header

    assert resolve_service_key_header("Authorization") == "X-Service-Key"
    assert resolve_service_key_header("Cookie") == "X-Service-Key"
    assert resolve_service_key_header("X-Service-Key: evil") == "X-Service-Key"
    assert resolve_service_key_header("not a header") == "X-Service-Key"
    assert resolve_service_key_header("") == "X-Service-Key"

    # Unit edge: nothing presented under any name is still a 401.
    _rejects(None, None)


def test_credential_under_an_unknown_header_is_rejected_over_http(client):
    from app.core.config import settings

    r = client.get(
        "/api/v1/models",
        headers={"X-Wrong-Header": settings.service_api_key},
    )
    assert r.status_code == 401, r.text


def _logged_auth_failures(caplog):
    return [
        r.getMessage()
        for r in caplog.records
        if r.name == "app.core.security" and "auth.failed" in r.getMessage()
    ]


def test_rejection_logs_structured_audit_line_without_secrets(caplog):
    from app.core.config import settings

    presented = "wrong-key-for-test-abcdef"
    with caplog.at_level(logging.WARNING, logger="app.core.security"):
        _rejects(presented, None)

    lines = _logged_auth_failures(caplog)
    assert len(lines) == 1, lines
    line = lines[0]
    assert "auth.failed" in line
    assert "reason=invalid" in line
    assert "has_key=1" in line
    assert presented not in line
    assert settings.service_api_key not in line


def test_missing_credential_logs_missing_reason(caplog):
    with caplog.at_level(logging.WARNING, logger="app.core.security"):
        _rejects(None, None)

    lines = _logged_auth_failures(caplog)
    assert len(lines) == 1, lines
    assert "reason=missing" in lines[0]


def test_unconfigured_engine_logs_unconfigured_reason(caplog):
    from app.core.config import settings

    original = settings.service_api_key
    settings.service_api_key = ""
    try:
        with caplog.at_level(logging.WARNING, logger="app.core.security"):
            _rejects("anything", None)
    finally:
        settings.service_api_key = original

    lines = _logged_auth_failures(caplog)
    assert len(lines) == 1, lines
    assert "reason=unconfigured" in lines[0]
    assert "anything" not in lines[0]


def test_bearer_path_rejection_also_logs_without_the_token(caplog):
    from fastapi.security import HTTPAuthorizationCredentials

    forged = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ4In0.forged"
    creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=forged)
    with caplog.at_level(logging.WARNING, logger="app.core.security"):
        _rejects(None, creds)

    lines = _logged_auth_failures(caplog)
    assert len(lines) == 1, lines
    assert "has_bearer=1" in lines[0]
    assert forged not in lines[0]


def test_accept_path_logs_no_auth_failure(caplog):
    from app.core.config import settings
    from app.core.security import require_service_auth

    with caplog.at_level(logging.WARNING, logger="app.core.security"):
        who = asyncio.run(require_service_auth(settings.service_api_key, None))

    assert who == "service-key"
    assert _logged_auth_failures(caplog) == []
