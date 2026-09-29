"""Business glossary: certified definitions, matching, and AI wiring.

The glossary must never invent a metric: every entry is grounded in the
analytics function that computes it, matching is alias-based, and unknown
words match nothing.
"""
from __future__ import annotations


def test_glossary_is_complete_and_certified():
    from app.semantic.glossary import GLOSSARY_VERSION, all_metrics

    metrics = all_metrics()
    assert len(metrics) == 11
    names = [m["name"] for m in metrics]
    assert names == sorted(names)
    for m in metrics:
        assert m["certified"] is True
        assert m["version"] == GLOSSARY_VERSION
        for field in ("definition", "formula", "source", "owner", "aliases"):
            assert m[field], f"{m['name']} missing {field}"
        assert len(m["aliases"]) >= 2


def test_matching_uses_aliases_in_both_languages():
    from app.semantic.glossary import metric_context

    rev = metric_context("berapa revenue bulan ini?")
    assert [m["name"] for m in rev] == ["revenue"]

    mix = metric_context("laba bersih dan HPP kuartal ini")
    assert {m["name"] for m in mix} == {"net_profit", "total_cogs"}

    assert metric_context("halo, apa kabar?") == []
    assert metric_context("") == []
    assert metric_context(None) == []


def test_matching_prefers_longest_alias_and_caps_count():
    from app.semantic.glossary import metric_context

    got = metric_context("nilai pesanan rata-rata cabang Jakarta")
    assert {m["name"] for m in got} == {"aov", "orders"}

    many = metric_context("revenue orders units aov growth margin laba kotor laba bersih hpp beban")
    assert len(many) == 4


def test_sql_prompt_carries_certified_definitions(monkeypatch):
    import json

    import app.ai.agent as agent

    captured = {}

    class _StubLlm:
        def chat(self, messages, **kwargs):
            captured["prompt"] = messages[1]["content"]
            return {"content": json.dumps({"sql": "SELECT revenue FROM fact_sales LIMIT 10"}), "offline": False}

        def validate_structured(self, content, keys):
            return (json.loads(content), None)

    monkeypatch.setattr(agent, "llm_client", _StubLlm())
    res = agent.generate_sql("berapa total pendapatan?")
    assert res["error"] == ""
    assert "SUM(fact_sales.revenue)" in captured["prompt"]
    assert "jangan menebak" in captured["prompt"]

    res = agent.generate_sql("halo")
    assert "Definisi metrik tersertifikasi" not in captured["prompt"]


def test_semantic_endpoint_lists_metrics(client, service_headers):
    res = client.get("/api/v1/semantic/metrics", headers=service_headers)
    assert res.status_code == 200
    body = res.json()
    assert body["success"] is True
    assert body["data"]["version"] == "v1"
    names = [m["name"] for m in body["data"]["metrics"]]
    assert "revenue" in names and "net_profit" in names


def test_semantic_endpoint_rejects_anonymous(client):
    res = client.get("/api/v1/semantic/metrics")
    assert res.status_code == 401
