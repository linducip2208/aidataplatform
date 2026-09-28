"""Auth tests for app.core.security (no network; uses the session client fixture)."""
import asyncio
import importlib
import os

from fastapi import HTTPException


def _rejected(result):
    return isinstance(result, HTTPException) and result.status_code == 401


def _key():
    from app.core.config import settings

    return settings.service_api_key


def test_correct_service_key_accepted():
    from app.core.security import require_service_auth

    who = asyncio.run(require_service_auth("test-key", None))
    assert who == "service-key"


def test_wrong_service_key_rejected():
    from app.core.security import require_service_auth

    assert _rejected(asyncio.run(require_service_auth("not-the-real-key", None)))


def test_missing_service_key_rejected():
    from app.core.security import require_service_auth

    assert _rejected(asyncio.run(require_service_auth(None, None)))


def test_empty_configured_key_rejects_everything():
    from app.core.config import settings
    from app.core.security import require_service_auth

    original = settings.service_api_key
    settings.service_api_key = ""
    try:
        assert _rejected(asyncio.run(require_service_auth("", None)))
        assert _rejected(asyncio.run(require_service_auth("anything", None)))
        assert _rejected(asyncio.run(require_service_auth(None, None)))
    finally:
        settings.service_api_key = original


def test_placeholder_configured_key_rejects_everything():
    from app.core.config import settings
    from app.core.security import require_service_auth

    original = settings.service_api_key
    settings.service_api_key = "change-me-service-key"
    try:
        assert _rejected(asyncio.run(require_service_auth("change-me-service-key", None)))
        assert _rejected(asyncio.run(require_service_auth("test-key", None)))
    finally:
        settings.service_api_key = original


def test_non_ascii_key_is_rejected_not_500():
    from app.core.security import require_service_auth

    assert _rejected(asyncio.run(require_service_auth("k\u00e9y-test", None)))


def test_bearer_token_accepted():
    from app.core.config import settings
    from app.core.security import create_access_token, require_service_auth
    from fastapi.security import HTTPAuthorizationCredentials

    original_secret = settings.jwt_secret
    settings.jwt_secret = "unit-test-jwt-secret-not-real"
    try:
        token = create_access_token("laravel-orchestrator")
        creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=token)
        assert asyncio.run(require_service_auth(None, creds)) == "laravel-orchestrator"
    finally:
        settings.jwt_secret = original_secret


def test_placeholder_jwt_secret_refuses_to_mint_or_verify():
    from app.core.config import settings
    from app.core.security import create_access_token, require_service_auth
    from fastapi.security import HTTPAuthorizationCredentials

    original_secret = settings.jwt_secret
    settings.jwt_secret = "change-me-jwt-secret"
    try:
        raised = False
        try:
            create_access_token("anyone")
        except ValueError:
            raised = True
        assert raised
        forged = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhdHRhY2tlciJ9.bad"
        creds = HTTPAuthorizationCredentials(scheme="Bearer", credentials=forged)
        assert _rejected(asyncio.run(require_service_auth(None, creds)))
    finally:
        settings.jwt_secret = original_secret


def test_optional_service_auth_falls_back_to_anonymous():
    from app.core.security import optional_service_auth

    assert asyncio.run(optional_service_auth(None, None)) == "anonymous"


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


def test_env_var_drives_header_name():
    import app.core.security as security

    original = os.environ.get("SERVICE_API_KEY_HEADER")
    os.environ["SERVICE_API_KEY_HEADER"] = "X-Internal-Key"
    try:
        reloaded = importlib.reload(security)
        assert reloaded.SERVICE_KEY_HEADER == "X-Internal-Key"
    finally:
        if original is None:
            os.environ.pop("SERVICE_API_KEY_HEADER", None)
        else:
            os.environ["SERVICE_API_KEY_HEADER"] = original
        importlib.reload(security)


def test_rate_limit_blocks_after_limit():
    from app.core.errors import AppError
    from app.core.security import check_rate_limit

    check_rate_limit("unit-test-credential:/api/v1/models", limit=3, window_seconds=60)
    check_rate_limit("unit-test-credential:/api/v1/models", limit=3, window_seconds=60)
    check_rate_limit("unit-test-credential:/api/v1/models", limit=3, window_seconds=60)
    blocked = False
    try:
        check_rate_limit("unit-test-credential:/api/v1/models", limit=3, window_seconds=60)
    except AppError as exc:
        blocked = exc.status_code == 429
    assert blocked


def test_rate_limit_key_is_not_stored_in_cleartext():
    from app.core.security import _calls, check_rate_limit

    check_rate_limit("unit-test-raw-credential-value:/api/v1/models")
    assert not any("unit-test-raw-credential-value" in k for k in _calls)


def test_request_with_correct_key_passes(client):
    r = client.get("/api/v1/models", headers={"X-Service-Key": _key()})
    assert r.status_code != 401


def test_request_with_wrong_key_rejected(client):
    r = client.get("/api/v1/models", headers={"X-Service-Key": "wrong-key-for-test"})
    assert r.status_code == 401


def test_request_without_key_rejected(client):
    r = client.get("/api/v1/models")
    assert r.status_code == 401

