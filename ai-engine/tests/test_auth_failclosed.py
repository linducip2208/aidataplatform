"""Fail-closed regression tests for the hardened service auth.

app/core/security.py used to answer `if not settings.is_prod: return "local-dev"`,
which meant any non-production deployment accepted every caller. These tests pin the
replacement behaviour from the HTTP edge, which is where the bypass used to be
observable. They must never be made to pass by relaxing the guard in app/**.
"""
import asyncio

import pytest
from fastapi import HTTPException


def _with_service_key(client, path, value, expected):
    """Point the configured service key at `value` for one request, then restore it."""
    from app.core.config import settings

    original = settings.service_api_key
    settings.service_api_key = value
    try:
        r = client.get(path)
    finally:
        settings.service_api_key = original
    assert r.status_code == expected, r.text
    return r


def test_no_configured_key_rejects_an_unauthenticated_caller(client):
    """The regression test for the closed hole: nothing configured means no access."""
    _with_service_key(client, "/api/v1/models", "", 401)


def test_no_configured_key_rejects_a_presented_key(client):
    """Configuring nothing must not degrade into 'accept whatever arrives'."""
    r = _with_service_key(client, "/api/v1/models", "", 401)
    assert "X-Service-Key" in r.text


def test_placeholder_configured_key_rejects_an_unauthenticated_caller(client):
    """A shipped default that was never replaced is not a secret."""
    _with_service_key(client, "/api/v1/models", "change-me-service-key", 401)


def test_dev_env_is_not_a_bypass():
    """The removed bypass keyed off APP_ENV; dev must grant nothing on its own."""
    from app.core.config import settings
    from app.core.security import require_service_auth

    original = settings.app_env
    settings.app_env = "dev"
    try:
        with pytest.raises(HTTPException) as exc:
            asyncio.run(require_service_auth(None, None))
        assert exc.value.status_code == 401
    finally:
        settings.app_env = original


def test_configured_key_still_opens_the_same_endpoint(client, service_headers):
    """Guards against the fail-closed tests passing for the wrong reason (bad path)."""
    r = client.get("/api/v1/models", headers=service_headers)
    assert r.status_code != 401, r.text
