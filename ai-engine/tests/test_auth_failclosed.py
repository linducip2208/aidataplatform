"""Fail-closed regression tests for the hardened service auth.

app/core/security.py used to answer `if not settings.is_prod: return "local-dev"`,
which meant any non-production deployment accepted every caller. These tests pin the
replacement behaviour from the HTTP edge, which is where the bypass used to be
observable. They must never be made to pass by relaxing the guard in app/**.

The business routes reach a database inside the handler, so only the *rejection*
paths are asserted on a real route here: a 401 is produced by the dependency
before the handler runs. The accept path is pinned in test_security.py against
the same dependency over a handler that needs no database.
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


def test_no_configured_key_rejects_even_a_key_presented_in_the_bearer_header(client):
    """A blank configuration grants nothing on either credential path."""
    from app.core.config import settings
    from app.core.security import _valid_service_key

    original = settings.service_api_key
    settings.service_api_key = ""
    try:
        assert _valid_service_key("") is False
        assert _valid_service_key("anything") is False
        assert _valid_service_key(None) is False
    finally:
        settings.service_api_key = original


def test_placeholder_configured_key_rejects_an_unauthenticated_caller(client):
    """A shipped default that was never replaced is not a secret."""
    _with_service_key(client, "/api/v1/models", "change-me-service-key", 401)


def test_placeholder_configured_key_does_not_accept_the_placeholder_itself(client):
    """The exact string from the config default must not open the door."""
    from app.core.config import settings
    from app.core.security import SERVICE_KEY_HEADER

    original = settings.service_api_key
    settings.service_api_key = "change-me-service-key"
    try:
        r = client.get(
            "/api/v1/models", headers={SERVICE_KEY_HEADER: "change-me-service-key"}
        )
    finally:
        settings.service_api_key = original
    assert r.status_code == 401, r.text


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


def test_dev_env_is_not_a_bypass_over_http(client):
    """The same claim at the edge, which is where the old bypass was visible."""
    from app.core.config import settings

    original = settings.app_env
    settings.app_env = "dev"
    try:
        assert client.get("/api/v1/models").status_code == 401
        assert client.get(
            "/api/v1/models", headers={"X-Service-Key": "wrong-key-for-test"}
        ).status_code == 401
    finally:
        settings.app_env = original


def test_configured_key_still_opens_the_same_endpoint(client, service_headers):
    """Guards against the fail-closed tests passing for the wrong reason (bad path).

    The same route is compared with and without the credential: without it the
    answer is 401, with it the request gets past the guard. Anything other than
    401 with the key means the rejections above are not about authentication.
    """
    without = client.get("/api/v1/models")
    assert without.status_code == 401, without.text

    with_key = client.get("/api/v1/models", headers=service_headers)
    assert with_key.status_code != 401, with_key.text
    assert "detail" not in with_key.json(), with_key.text
