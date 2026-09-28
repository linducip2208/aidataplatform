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
