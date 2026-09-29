"""Rate limiter tests: app.core.security.check_rate_limit and the middleware.

check_rate_limit is in-process -- an OrderedDict of deques in the security
module, no Redis -- so every property here is testable without one. The
properties that matter are the ones an attacker probes: varying the credential
header must not mint a fresh bucket, the limiter must fail closed when it breaks,
its state must stay bounded, and the ops probes must never be rate limited,
because a 429 on a probe reads as a dead engine.
"""
from __future__ import annotations

from collections import OrderedDict

import pytest


@pytest.fixture()
def fresh_stores():
    """One test's own limiter state, never leaked into the rest of the suite."""
    from app.core import security

    saved = (security._calls, security._path_calls)
    security._calls = OrderedDict()
    security._path_calls = OrderedDict()
    try:
        yield
    finally:
        security._calls, security._path_calls = saved


class _Clock:
    """A controllable stand-in for the time module, so no test has to sleep."""

    def __init__(self, now: float = 1_700_000_000.0) -> None:
        self.now = now

    def time(self) -> float:
        return self.now

    def advance(self, seconds: float) -> None:
        self.now += seconds


def test_rate_limit_blocks_after_limit(fresh_stores):
    from app.core.errors import AppError
    from app.core.security import check_rate_limit

    key = "unit-test-credential:/api/v1/models"
    for _ in range(3):
        check_rate_limit(key, limit=3, window_seconds=60)
    with pytest.raises(AppError) as exc:
        check_rate_limit(key, limit=3, window_seconds=60)
    assert exc.value.status_code == 429
    assert exc.value.code == "RATE_LIMITED"


def test_a_repeating_credential_is_stopped_by_its_own_bucket(fresh_stores):
    """One credential cannot spend the whole shared path budget.

    The per-credential bucket has to bite at `limit`, not at limit * 3, or a
    single caller walks straight through the shared path allowance.
    """
    from app.core.errors import AppError
    from app.core.security import _SHARED_PATH_FACTOR, _path_calls, check_rate_limit

    key = "unit-test-repeater:/api/v1/analytics/kpi"
    check_rate_limit(key, limit=2, window_seconds=60)
    check_rate_limit(key, limit=2, window_seconds=60)
    with pytest.raises(AppError):
        check_rate_limit(key, limit=2, window_seconds=60)
    # The shared path bucket was nowhere near its own allowance, so it cannot
    # have been what refused the third call.
    path = key.split(":", 1)[1]
    assert len(_path_calls[path]) == 2 < 2 * _SHARED_PATH_FACTOR


def test_varying_the_key_header_cannot_mint_a_fresh_bucket(fresh_stores):
    """The eviction attack: change the header value on every request.

    Each new value would get its own credential bucket if the limiter only
    counted per credential. It is also counted per path, so a caller that does
    not know the secret still runs out of budget by changing nothing at all.
    """
    from app.core.errors import AppError
    from app.core.security import _SHARED_PATH_FACTOR, _path_calls, check_rate_limit

    path = "/api/v1/analytics/kpi"
    limit = 4
    shared_allowance = limit * _SHARED_PATH_FACTOR
    allowed = 0
    for i in range(shared_allowance + 5):
        try:
            check_rate_limit(f"guess-{i}:{path}", limit=limit, window_seconds=60)
            allowed += 1
        except AppError as exc:
            assert exc.status_code == 429
            break
    else:
        pytest.fail(f"{allowed} distinct credentials were all accepted on {path}")
    assert allowed == shared_allowance
    # Every one of those requests was still counted against the path.
    assert len(_path_calls[path]) == shared_allowance


def test_a_different_path_gets_its_own_budget(fresh_stores):
    """Per-path counting must not collapse into one global bucket."""
    from app.core.security import check_rate_limit

    for i in range(3):
        check_rate_limit(f"unit-test:{'/p%d' % i}", limit=1, window_seconds=60)


def test_the_window_slides_so_a_bucket_recovers(fresh_stores, monkeypatch):
    from app.core.errors import AppError
    from app.core import security

    clock = _Clock()
    monkeypatch.setattr(security, "time", clock)
    key = "unit-test-windowed:/api/v1/forecast"

    security.check_rate_limit(key, limit=1, window_seconds=60)
    with pytest.raises(AppError):
        security.check_rate_limit(key, limit=1, window_seconds=60)

    clock.advance(61)
    security.check_rate_limit(key, limit=1, window_seconds=60)


def test_rate_limit_key_is_not_stored_in_cleartext(fresh_stores):
    """The presented credential must never become a key or a log line."""
    from app.core.security import _calls, check_rate_limit

    check_rate_limit("unit-test-raw-credential-value:/api/v1/models")
    assert _calls
    assert not any("unit-test-raw-credential-value" in k for k in _calls)
    assert not any("unit-test-raw-credential-value" in k for k in _calls.keys())


def test_a_blank_credential_shares_the_anonymous_bucket(fresh_stores):
    from app.core.errors import AppError
    from app.core.security import check_rate_limit

    check_rate_limit("", limit=1, window_seconds=60)
    with pytest.raises(AppError):
        check_rate_limit("", limit=1, window_seconds=60)


def test_the_store_stays_bounded_when_keys_are_minted_freely(fresh_stores):
    """Unbounded growth is a denial of service by a caller that sends no secret.

    Each distinct credential+path would otherwise leave a deque behind forever.
    """
    from app.core import security

    limit = 10_000
    for i in range(limit + 50):
        security.check_rate_limit(f"mint-{i}:/mint/{i}", limit=1, window_seconds=60)
    assert len(security._calls) <= security._MAX_RATE_KEYS
    assert len(security._path_calls) <= security._MAX_RATE_KEYS


def test_an_internal_error_in_the_limiter_fails_closed(fresh_stores, monkeypatch):
    """Any internal failure becomes a 429, never an open door.

    An exception escaping here would be a 500 from the middleware, and 500 does
    not stop an attacker; 429 does.
    """
    from app.core.errors import AppError
    from app.core import security

    def boom(value: str) -> str:
        raise RuntimeError("limiter backend unavailable")

    monkeypatch.setattr(security, "_fingerprint", boom)
    with pytest.raises(AppError) as exc:
        security.check_rate_limit("unit-test-credential:/api/v1/models")
    assert exc.value.status_code == 429
    assert exc.value.code == "RATE_LIMITED"
    assert isinstance(exc.value.__cause__, RuntimeError)


def test_the_middleware_answers_429_once_the_limit_is_spent(client):
    from app.core.config import settings
    from app.core import security

    saved = (security._calls, security._path_calls, settings.rate_limit_per_minute)
    security._calls = OrderedDict()
    security._path_calls = OrderedDict()
    settings.rate_limit_per_minute = 2
    try:
        statuses = [client.get("/api/v1/models").status_code for _ in range(3)]
    finally:
        security._calls, security._path_calls, limit = saved
        settings.rate_limit_per_minute = limit

    # Anonymous traffic is counted before auth, so a 429 is reachable without a
    # credential. On an unmigrated test database a keyed request would come back
    # 500, which would hide the point of the test.
    assert statuses[:2] == [401, 401], statuses
    assert statuses[2] == 429, statuses


def test_ops_probes_are_exempt_from_the_limiter(client, fresh_stores):
    """A 429 on a probe reads as a dead engine and takes the container down.

    The exemption is asserted against a full bucket, not an empty one: a probe
    that is merely untrafficked proves nothing.
    """
    from app.core.errors import AppError
    from app.core.security import check_rate_limit

    flood_key = "flooder:/api/v1/health"
    for _ in range(500):
        try:
            check_rate_limit(flood_key, limit=120, window_seconds=60)
        except AppError:
            break
    else:
        pytest.fail("the probe path bucket never filled; the test proves nothing")
    with pytest.raises(AppError):
        check_rate_limit(flood_key, limit=120, window_seconds=60)

    for path in ("/api/v1/health", "/api/v1/liveness", "/health", "/liveness",
                 "/metrics", "/api/v1/readiness", "/readiness"):
        r = client.get(path)
        assert r.status_code == 200, f"{path} -> {r.status_code}: {r.text}"
