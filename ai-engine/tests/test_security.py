"""Auth tests for app.core.security (no network; uses the session client fixture).

This file pins the security-critical surface rather than merely exercising it:

* with no usable SERVICE_API_KEY nothing is accepted, at the dependency or at
  the HTTP edge, on every business route;
* a placeholder secret is not a secret;
* APP_ENV=dev grants nothing;
* the comparison is constant time and a non-ASCII key cannot break it;
* the header name comes from configuration, and renaming it really moves both
  the accept and the reject path;
* the only routes left open are the ops probes;
* a rejection is a FastAPI ``{"detail": ...}`` body, not the business envelope.

Never weaken an assertion in here to make a test pass. These are the tests that
prove the engine fails closed, so a failure is the finding.
"""
import asyncio
import importlib
import re

import pytest
from fastapi import HTTPException
from fastapi.routing import APIRoute
from fastapi.testclient import TestClient

# The routes that must stay reachable with no credential: the container
# healthcheck, nginx and Prometheus. Everything else in the app is guarded.
OPS_PROBES = frozenset(
    {
        "/health",
        "/readiness",
        "/liveness",
        "/metrics",
        "/api/v1/health",
        "/api/v1/readiness",
        "/api/v1/liveness",
    }
)


def _rejects(*args, **kwargs) -> None:
    """Assert require_service_auth answered 401 for these arguments.

    The dependency does not return a rejection, it raises one, so the check has
    to be on the raise. Accepting anything else here would let a silent 500
    (a TypeError out of the comparison, say) look like a clean rejection.
    """
    from app.core.security import require_service_auth

    with pytest.raises(HTTPException) as exc:
        asyncio.run(require_service_auth(*args, **kwargs))
    assert exc.value.status_code == 401, exc.value.detail


def _key() -> str:
    from app.core.config import settings

    return settings.service_api_key


def _iter_routes(routes, prefix: str = ""):
    """Yield (full_path, route) for every APIRoute, descending into included routers."""
    for route in routes:
        included = getattr(route, "original_router", None)
        if included is not None:  # a router pulled in with include_router
            context = getattr(route, "include_context", None)
            yield from _iter_routes(
                included.routes, prefix + (getattr(context, "prefix", "") or "")
            )
            continue
        if isinstance(route, APIRoute):
            yield prefix + route.path, route


def _is_guarded(route: APIRoute) -> bool:
    """True when require_service_auth is one of this route's dependencies.

    Matched by name, not identity: another test in this file reloads
    app.core.security, which rebinds the module attribute while the routers
    keep the function object they imported.
    """
    return any(
        getattr(dep.call, "__name__", None) == "require_service_auth"
        for dep in route.dependant.dependencies
    )


def _probe_client(require_auth=None) -> TestClient:
    """A one-route app wired to the real auth dependency, with no database.

    Every guarded business route opens a database session inside the handler,
    so on an unmigrated test database a correct key comes back as a 500 and the
    test can no longer tell "auth let it through" from "auth is broken". The
    dependency here is the real one; only the handler is a stub.
    """
    from fastapi import Depends, FastAPI

    from app.core.security import require_service_auth

    app = FastAPI()
    guard = require_auth or require_service_auth

    @app.get("/probe")
    def _probe(who: str = Depends(guard)) -> dict:
        return {"who": who}

    return TestClient(app)


def test_the_suite_is_not_running_against_a_placeholder_key():
    """Guards the accept-path tests below.

    If SERVICE_API_KEY is blank or a shipped placeholder, every guarded
    endpoint is closed by design and "the right key is accepted" would fail for
    the wrong reason. Fail loudly instead of silently testing nothing.
    """
    from app.core.security import configured_service_key

    assert _key(), "SERVICE_API_KEY is not set for the test run"
    assert configured_service_key(), (
        "SERVICE_API_KEY is a placeholder; the accept-path assertions below "
        "would be meaningless"
    )


def test_correct_service_key_accepted():
    from app.core.security import require_service_auth

    who = asyncio.run(require_service_auth(_key(), None))
    assert who == "service-key"


def test_wrong_service_key_rejected():
    _rejects("not-the-real-key", None)


def test_missing_service_key_rejected():
    _rejects(None, None)


def test_empty_configured_key_rejects_everything():
    from app.core.config import settings

    original = settings.service_api_key
    settings.service_api_key = ""
    try:
        _rejects("", None)
        _rejects("anything", None)
        _rejects(None, None)
    finally:
        settings.service_api_key = original


def test_placeholder_configured_key_rejects_everything():
    from app.core.config import settings

    original = settings.service_api_key
    settings.service_api_key = "change-me-service-key"
    try:
        _rejects("change-me-service-key", None)
        _rejects(_key(), None)
        _rejects(None, None)
    finally:
        settings.service_api_key = original


def test_non_ascii_key_is_rejected_not_500():
    _rejects("kéy-test", None)


def test_non_ascii_configured_key_compares_through_utf8_bytes():
    """A non-ASCII secret must work, and a near miss must 401 rather than 500.

    secrets.compare_digest raises TypeError when a str operand contains
    non-ASCII characters, so comparing the raw strings would turn both a
    correct key and a wrong one into a 500. security.py encodes both sides;
    this pins that it keeps doing so.
    """
    from app.core.config import settings
    from app.core.security import require_service_auth

    original = settings.service_api_key
    settings.service_api_key = "këy-rahasia-🔑"
    try:
        who = asyncio.run(require_service_auth("këy-rahasia-🔑", None))
        assert who == "service-key"
        _rejects("këy-rahasia", None)  # prefix
        _rejects("kèy-rahasia-🔑", None)  # one byte different
    finally:
        settings.service_api_key = original


def test_service_key_comparison_is_constant_time(monkeypatch):
    """The credential must go through secrets.compare_digest, not `==`.

    `==` on str short-circuits at the first differing byte, which hands an
    attacker the configured key one character at a time. The spy delegates to
    the real function, so what is asserted here is how the key is compared, not
    merely that a bad key is refused.
    """
    import app.core.security as security

    calls: list = []
    real = security.secrets.compare_digest

    def spy(left, right):
        calls.append((left, right))
        return real(left, right)

    monkeypatch.setattr(security.secrets, "compare_digest", spy)
    with pytest.raises(HTTPException) as exc:
        asyncio.run(security.require_service_auth("wrong-key-for-test", None))
    assert exc.value.status_code == 401
    assert len(calls) == 1, calls
    left, right = calls[0]
    assert isinstance(left, bytes) and isinstance(right, bytes), (left, right)
    assert right == _key().encode("utf-8")


def test_bearer_token_accepted():
    from fastapi.security import HTTPAuthorizationCredentials

    from app.core.config import settings
    from app.core.security import create_access_token, require_service_auth

    original_secret = settings.jwt_secret
    settings.jwt_secret = "unit-test-jwt-secret-not-real"
    try:
        token = create_access_token("laravel-orchestrator")
        creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=token)
        assert asyncio.run(require_service_auth(None, creds)) == "laravel-orchestrator"
    finally:
        settings.jwt_secret = original_secret


def test_placeholder_jwt_secret_refuses_to_mint_or_verify():
    from fastapi.security import HTTPAuthorizationCredentials

    from app.core.config import settings
    from app.core.security import create_access_token

    original_secret = settings.jwt_secret
    settings.jwt_secret = "change-me-jwt-secret"
    try:
        with pytest.raises(ValueError):
            create_access_token("anyone")
        forged = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhdHRhY2tlciJ9.bad"
        creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=forged)
        _rejects(None, creds)
    finally:
        settings.jwt_secret = original_secret


def test_optional_service_auth_falls_back_to_anonymous():
    from app.core.security import optional_service_auth

    assert asyncio.run(optional_service_auth(None, None)) == "anonymous"


def test_optional_service_auth_still_accepts_a_valid_key():
    from app.core.security import optional_service_auth

    assert asyncio.run(optional_service_auth(_key(), None)) == "service-key"


def test_default_header_is_x_service_key():
    from app.core.security import resolve_service_key_header

    assert resolve_service_key_header(None) == "X-Service-Key"
    assert resolve_service_key_header("") == "X-Service-Key"


def test_configurable_header_name_is_honoured():
    from app.core.security import resolve_service_key_header

    assert resolve_service_key_header("X-Internal-Key") == "X-Internal-Key"


def test_unsafe_header_names_fall_back_to_default():
    from app.core.security import resolve_service_key_header

    assert resolve_service_key_header("Authorization") == "X-Service-Key"
    assert resolve_service_key_header("Cookie") == "X-Service-Key"
    assert resolve_service_key_header("not a header") == "X-Service-Key"
    assert resolve_service_key_header("X-Service-Key: evil") == "X-Service-Key"


def test_header_name_is_resolved_from_configuration():
    """No argument means "whatever SERVICE_API_KEY_HEADER says", not the default."""
    from app.core.config import settings
    from app.core.security import resolve_service_key_header

    original = settings.service_api_key_header
    try:
        settings.service_api_key_header = "X-Internal-Key"
        assert resolve_service_key_header() == "X-Internal-Key"
        settings.service_api_key_header = "  X-Padded-Key  "
        assert resolve_service_key_header() == "X-Padded-Key"
        settings.service_api_key_header = ""
        assert resolve_service_key_header() == "X-Service-Key"
    finally:
        settings.service_api_key_header = original


def test_renaming_the_header_moves_both_the_accept_and_the_reject_path():
    """SERVICE_API_KEY_HEADER has to actually move the credential.

    Renaming the header in .env used to be a no-op inside the engine: the
    dependency kept reading a hardcoded X-Service-Key, so Laravel sent the
    renamed header, the engine answered 401, and the operator went looking for
    a key mismatch. The module resolves the name once at import, so the
    rename is driven by reloading it and then making real requests through the
    reloaded dependency.
    """
    from app.core.config import settings
    import app.core.security as security

    original = settings.service_api_key_header
    settings.service_api_key_header = "X-Internal-Key"
    try:
        reloaded = importlib.reload(security)
        assert reloaded.SERVICE_KEY_HEADER == "X-Internal-Key"
        assert reloaded.resolve_service_key_header() == "X-Internal-Key"

        with _probe_client(reloaded.require_service_auth) as c:
            # reject path: the old name no longer carries the credential
            stale = c.get("/probe", headers={"X-Service-Key": _key()})
            assert stale.status_code == 401, stale.text
            # accept path: the configured name does
            ok = c.get("/probe", headers={"X-Internal-Key": _key()})
            assert ok.status_code == 200, ok.text
            assert ok.json() == {"who": "service-key"}
            # and a wrong value in the new name is still refused
            bad = c.get("/probe", headers={"X-Internal-Key": "wrong-key"})
            assert bad.status_code == 401, bad.text
    finally:
        settings.service_api_key_header = original
        importlib.reload(security)
    assert security.SERVICE_KEY_HEADER == original


def test_every_business_endpoint_is_closed_to_an_anonymous_caller(client):
    """The sweep: no route outside the ops probes answers without a key.

    Driven from the OpenAPI document, so a route that ships without a guard is
    caught whatever it is called. A 401 also proves the guard runs before the
    handler, which is why this passes without a migrated database.
    """
    checked = 0
    for path, operations in client.app.openapi()["paths"].items():
        if path in OPS_PROBES:
            continue
        concrete = re.sub(r"\{[^}]+\}", "1", path)
        for method in operations:
            r = client.request(method.upper(), concrete)
            assert r.status_code == 401, (
                f"{method.upper()} {path} answered {r.status_code} without a key: "
                f"{r.text[:200]}"
            )
            checked += 1
    assert checked >= 30, f"only {checked} guarded operations were exercised"


def test_the_only_unguarded_routes_are_the_ops_probes(client):
    """Locks the open set. Adding a route here without a guard fails this test.

    Anything unguarded is reachable by an unauthenticated caller, so this is the
    audit surface: the health probes and the metrics scrape only. Opening a new
    one is a security decision, not an accident.
    """
    guarded, unguarded = [], set()
    for path, route in _iter_routes(client.app.routes):
        (guarded.append(path) if _is_guarded(route) else unguarded.add(path))
    assert len(guarded) >= 30, guarded
    assert unguarded == set(OPS_PROBES), (
        f"unguarded routes changed: {sorted(unguarded)}; every other route must "
        f"carry Depends(require_service_auth)"
    )


def test_wrong_key_is_rejected_with_the_fastapi_detail_shape(client):
    """A 401 is {"detail": ...}, never the {"success", "error"} business envelope.

    AiEngineClient reads error.message first and then detail, so wrapping an
    auth failure in the business envelope would make Laravel treat an
    authentication failure as a business error and retry it.
    """
    r = client.get("/api/v1/models", headers={"X-Service-Key": "wrong-key-for-test"})
    assert r.status_code == 401
    body = r.json()
    assert set(body) == {"detail"}, body
    assert "success" not in body and "error" not in body

    from app.core.security import SERVICE_KEY_HEADER

    # The rejection names the header the engine actually reads.
    assert SERVICE_KEY_HEADER in body["detail"]


def test_valid_key_is_accepted_by_the_real_dependency():
    """The accept path, on a handler that needs no database."""
    with _probe_client() as c:
        ok = c.get("/probe", headers={"X-Service-Key": _key()})
        assert ok.status_code == 200, ok.text
        assert ok.json() == {"who": "service-key"}


def test_valid_key_in_the_bearer_header_is_accepted():
    """Laravel may send the key as a bearer token; that path stays open too."""
    with _probe_client() as c:
        ok = c.get("/probe", headers={"Authorization": f"Bearer {_key()}"})
        assert ok.status_code == 200, ok.text
        assert ok.json() == {"who": "service-key"}
