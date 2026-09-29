"""Ops probes: /health, /readiness, /liveness, /metrics.

These are the endpoints the platform depends on being up: the compose healthcheck
reads /api/v1/health, nginx routes /health, Prometheus scrapes /metrics. None of
them carries a service key, and none of them may be closed, wrapped in the
business envelope, or answered with an error because a dependency is down.
"""
import pytest


def test_health(client):
    r = client.get("/health")
    assert r.status_code == 200
    assert r.json()["status"] == "ok"


def test_api_health(client):
    r = client.get("/api/v1/health")
    assert r.status_code == 200


def test_metrics(client):
    r = client.get("/metrics")
    assert r.status_code == 200


def test_api_health_is_an_ops_probe_and_needs_no_service_key(client):
    """Probes must stay reachable without a credential, or the platform's own
    monitoring cannot see a broken engine."""
    r = client.get("/api/v1/health")
    assert r.status_code == 200, r.text
    assert r.json()["status"] == "ok"


def test_readiness_and_liveness_need_no_service_key(client):
    ready = client.get("/api/v1/readiness")
    assert ready.status_code == 200, ready.text
    assert "ready" in ready.json()
    live = client.get("/api/v1/liveness")
    assert live.status_code == 200, live.text
    assert live.json()["alive"] is True


def test_probes_are_bare_objects_not_enveloped(client):
    """health/readiness/liveness answer a bare object (response_model=HealthResponse).
    Only business routes use {"success": true, "data": ...}, so no probe may be
    unwrapped as an envelope."""
    body = client.get("/api/v1/health").json()
    assert "data" not in body and "success" not in body
    assert set(body) == {"status", "app", "env", "version"}
    assert "data" not in client.get("/api/v1/readiness").json()
    assert "data" not in client.get("/api/v1/liveness").json()


@pytest.mark.parametrize("configured", ["", "change-me-service-key"])
def test_probes_stay_reachable_with_no_usable_service_key(client, configured):
    """The healthcheck runs before anyone has configured the service key.

    Closing the probes when SERVICE_API_KEY is blank would take the container
    out of rotation for a misconfiguration that the probes exist to report.
    """
    from app.core.config import settings

    original = settings.service_api_key
    settings.service_api_key = configured
    try:
        for path in ("/api/v1/health", "/api/v1/liveness", "/health", "/liveness"):
            r = client.get(path)
            assert r.status_code == 200, f"{path} -> {r.status_code}: {r.text}"
    finally:
        settings.service_api_key = original


def test_root_and_versioned_probes_agree(client):
    """nginx routes /health, the container healthcheck reads /api/v1/health.

    A divergence between the two would let one of them report a broken engine
    as healthy.
    """
    assert client.get("/health").json() == client.get("/api/v1/health").json()
    assert client.get("/liveness").json()["alive"] is True
    assert client.get("/api/v1/liveness").json()["alive"] is True


@pytest.mark.xfail(
    strict=True,
    reason="app/main.py:171-173 hardcodes {'ready': True} for the root /readiness "
    "alias, so it cannot report an unready engine and disagrees with "
    "/api/v1/readiness (app/api/v1/health.py:17-38), which probes the database "
    "and redis. Remove this marker when the root alias delegates to the real check.",
)
def test_root_readiness_agrees_with_the_versioned_readiness(client):
    r = client.get("/readiness")
    assert r.status_code == 200
    assert r.json() == client.get("/api/v1/readiness").json()


def test_readiness_reports_failing_dependencies_instead_of_failing(client):
    """A probe that 500s on a dead database takes the container down with it.

    readiness has to answer 200 and describe the outage in the body, so the
    orchestrator can tell "the engine is unhealthy" from "the engine is gone".
    """
    r = client.get("/api/v1/readiness")
    assert r.status_code == 200, r.text
    body = r.json()
    assert set(body) == {"ready", "checks"}
    for name, state in body["checks"].items():
        assert state == "up" or state.startswith("down: "), (name, state)
    assert body["ready"] is (body["checks"]["db"] == "up")
