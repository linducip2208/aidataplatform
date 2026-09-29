"""Enterprise AI tests: hybrid RAG, rerank, citations, SQL guardrails, grounding,
fallback chain, cost ledger, prompt templates, conversation context, API surface.

Deterministic by construction: provider calls are monkeypatched, embeddings are
fixed 2-D vectors where ranking matters, and the warehouse is a real SQLite DB
emptied per test. The offline hash-embedding path is covered by one smoke test;
everything ranking-sensitive pins its vectors so CI never depends on MD5
bucket luck.
"""
from __future__ import annotations

import pytest

OFFLINE = {"content": "", "tool_calls": [], "raw": {"offline": True}, "offline": True}


@pytest.fixture()
def ai_warehouse(warehouse):
    """Session warehouse plus tables other modules register (ai_usage).

    Mirrors the ``bi_warehouse`` pattern: ``create_all`` first (registers the
    ledger table this file's import added to ``Base``), then empty tolerantly.
    Local to this file so the shared fixture stays untouched.
    """
    import app.ai.cost_tracking  # noqa: F401  (registers ai_usage on Base)
    try:
        import app.main  # noqa: F401  (registers every router-side model)
    except Exception:
        pass
    from sqlalchemy import delete

    from app.database.connection import Base

    Base.metadata.create_all(warehouse)
    with warehouse.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            try:
                conn.execute(delete(table))
            except Exception:
                pass
    return warehouse


@pytest.fixture()
def ai_session(ai_warehouse):
    from app.database.connection import SessionLocal

    session = SessionLocal()
    try:
        yield session
    finally:
        session.rollback()
        session.close()


def _fake_embed_factory():
    """Fixed 2-D vectors keyed on marker text (query vs three corpus docs)."""
    def fake_embed(texts):
        out = []
        for text in texts:
            low = str(text or "").lower()
            if len(low) < 40 and ("refund policy" in low or "stok gudang" in low):
                out.append([1.0, 0.0])  # the query vector
            elif "kebijakan-refund" in low:
                out.append([0.4, 0.917])  # A: keyword doc, cos 0.4
            elif "distraktor-vektor" in low:
                out.append([0.5, 0.866])  # B: vector doc, cos 0.5, no terms
            elif "netral-kosong" in low:
                out.append([0.0, 1.0])  # C: neither, cos 0.0
            elif "laporan-keuangan-triwulan" in low:
                out.append([1.0, 0.0])  # D: rerank decoy, cos 1.0
            elif "stok-gudang-aman" in low:
                out.append([0.2, 0.98])  # E: rerank winner, cos 0.2
            else:
                out.append([0.0, 1.0])
        return out
    return fake_embed


@pytest.fixture()
def hybrid_corpus(ai_session, monkeypatch):
    """Three docs: A has the query terms but low vector sim, B the reverse."""
    import app.ai.rag as rag_mod

    monkeypatch.setattr(rag_mod, "_embed", _fake_embed_factory())
    docs = [
        ("Refund", "kebijakan-refund: Refund dibayar 14 hari. Policy pengembalian dana.", "handbook"),
        ("Distraktor", "distraktor-vektor: surat jalan pengiriman barang keluar kota.", "log"),
        ("Netral", "netral-kosong: jadwal piket kebersihan area parkir basement.", "memo"),
    ]
    for title, content, source in docs:
        res = rag_mod.ingest_text(title, content, source, "txt", ai_session)
        assert res["status"] == "created", res
    return ai_session


@pytest.fixture()
def rerank_corpus(ai_session, monkeypatch):
    import app.ai.rag as rag_mod

    monkeypatch.setattr(rag_mod, "_embed", _fake_embed_factory())
    docs = [
        ("Keuangan", "laporan-keuangan-triwulan: arus kas dan depresiasi aset tetap.", "finance"),
        ("Stok", "stok-gudang-aman: stok gudang tercatat aman untuk semua sku aktif.", "ops"),
    ]
    for title, content, source in docs:
        res = rag_mod.ingest_text(title, content, source, "txt", ai_session)
        assert res["status"] == "created", res
    return ai_session


# ------------------------------------------------------------------
# hybrid vs vector-only recall
# ------------------------------------------------------------------

def test_hybrid_finds_the_keyword_doc_vector_only_misses_it(hybrid_corpus):
    import app.ai.rag as rag_mod

    hybrid = rag_mod.query("refund policy", top_k=1, db_session=hybrid_corpus,
                           hybrid=True, rerank=False)
    vector_only = rag_mod.query("refund policy", top_k=1, db_session=hybrid_corpus,
                                hybrid=False, rerank=False)
    assert hybrid["citations"][0]["title"] == "Refund"
    assert vector_only["citations"][0]["title"] == "Distraktor"
    assert hybrid["confidence"] > 0.0


def test_hybrid_rank_improves_over_vector_only(hybrid_corpus):
    import app.ai.rag as rag_mod

    def order(**kwargs):
        res = rag_mod.query("refund policy", top_k=3, db_session=hybrid_corpus, **kwargs)
        return [c["title"] for c in res["citations"]]

    hybrid_order = order(hybrid=True, rerank=False)
    vector_order = order(hybrid=False, rerank=False)
    assert hybrid_order.index("Refund") < vector_order.index("Refund")


def test_rerank_reorders_toward_term_coverage(rerank_corpus):
    import app.ai.rag as rag_mod

    fused = rag_mod.query("stok gudang", top_k=2, db_session=rerank_corpus,
                          hybrid=True, rerank=False)
    reranked = rag_mod.query("stok gudang", top_k=2, db_session=rerank_corpus,
                             hybrid=True, rerank=True)
    assert [c["title"] for c in fused["citations"]] == ["Keuangan", "Stok"]
    assert [c["title"] for c in reranked["citations"]] == ["Stok", "Keuangan"]
    top = reranked["evidence"][0]
    assert set(top["features"]) == {"fused", "coverage", "phrase", "length"}
    assert top["features"]["coverage"] == 1.0


def test_rerank_never_adds_or_drops_candidates(rerank_corpus):
    import app.ai.rag as rag_mod

    fused = rag_mod.query("stok gudang", top_k=2, db_session=rerank_corpus,
                          hybrid=True, rerank=False)
    reranked = rag_mod.query("stok gudang", top_k=2, db_session=rerank_corpus,
                             hybrid=True, rerank=True)
    assert {c["chunk_id"] for c in fused["citations"]} == \
        {c["chunk_id"] for c in reranked["citations"]}


def test_citation_offsets_slice_the_matched_term(hybrid_corpus):
    import app.ai.rag as rag_mod
    from app.schemas.ai import Citation

    res = rag_mod.query("refund", top_k=3, db_session=hybrid_corpus,
                        hybrid=True, rerank=False)
    cited = [c for c in res["citations"] if c["title"] == "Refund"][0]
    Citation.model_validate(cited)  # contract shape
    assert cited["chunk_id"] is not None
    assert cited["source"] == "handbook" and cited["title"] == "Refund"
    row = [e for e in res["evidence"] if e["chunk_id"] == cited["chunk_id"]][0]
    start, end = cited["char_start"], cited["char_end"]
    assert isinstance(start, int) and isinstance(end, int) and end > start
    assert row["content"][start:end].lower() == "refund"


def test_offline_hash_corpus_still_answers(ai_session):
    """No monkeypatch: the real hash-embedding path retrieves verbatim terms."""
    import app.ai.rag as rag_mod

    res = rag_mod.ingest_text("Nota", "garansi produk berlaku dua tahun sejak pembelian",
                              "nota", "txt", ai_session)
    assert res["status"] == "created"
    out = rag_mod.query("garansi produk", top_k=3, db_session=ai_session)
    assert out["n_results"] >= 1
    assert "garansi" in out["answer"].lower() or out["citations"]
    assert out["confidence"] >= 0.0


def test_empty_corpus_refuses_with_zero_confidence(ai_session):
    import app.ai.rag as rag_mod

    out = rag_mod.query("apakah ada naga di gudang?", top_k=5, db_session=ai_session)
    assert out["n_results"] == 0
    assert out["confidence"] == 0.0
    assert out["citations"] == [] and out["evidence"] == []
    assert "Tidak ada dokumen" in out["answer"]
    assert any("Tidak ada chunk" in note for note in out["limitations"])


# ------------------------------------------------------------------
# SQL guardrails
# ------------------------------------------------------------------

ACCEPT = [
    "SELECT revenue FROM fact_sales LIMIT 10",
    "select quantity, revenue from fact_sales where quantity > 1 limit 5",
    "SELECT p.product_name, SUM(f.revenue) FROM fact_sales f JOIN dim_product p "
    "ON f.product_id = p.id GROUP BY p.product_name LIMIT 20",
    "SELECT * FROM fact_sales WHERE customer_name = 'drop table users; --' LIMIT 3",
]

REFUSE = [
    ("", "refused:empty"),
    ("DROP TABLE fact_sales", "refused:not_select"),
    ("DELETE FROM fact_sales", "refused:not_select"),
    ("INSERT INTO fact_sales (revenue) VALUES (1)", "refused:not_select"),
    ("UPDATE fact_sales SET revenue = 0", "refused:not_select"),
    ("SELECT * FROM fact_sales; DROP TABLE dim_product", "refused:multi_statement"),
    ("SELECT * FROM fact_sales LIMIT 1; SELECT * FROM dim_branch", "refused:multi_statement"),
    ("SELECT * FROM ai_messages LIMIT 5", "refused:blocked_table"),
    ("SELECT * FROM users LIMIT 5", "refused:blocked_table"),
    ("SELECT * FROM rag_chunks LIMIT 5", "refused:blocked_table"),
    ("SELECT * FROM mysterious_table LIMIT 5", "refused:blocked_table"),
    ("SELECT 1", "refused:no_table"),
    ("EXPLAIN SELECT * FROM fact_sales", "refused:not_select"),
    ("SELECT * FROM fact_sales INTO OUTFILE '/tmp/x.csv'", "refused:file_access"),
    ("SELECT * FROM fact_sales LIMIT 0", "refused:bad_limit"),
    ("x" * 9000, "refused:too_long"),
]


@pytest.mark.parametrize("sql", ACCEPT)
def test_sql_guard_accepts_plain_selects(sql):
    from app.ai import sql_guard

    verdict = sql_guard.validate_sql(sql)
    assert verdict["allowed"] is True, verdict
    assert verdict["reason"] == "ok"
    assert verdict["tables"] and set(verdict["tables"]) <= set(sql_guard.ALLOWED_TABLES)


@pytest.mark.parametrize("sql,prefix", REFUSE)
def test_sql_guard_refuses_destructive_and_out_of_scope(sql, prefix):
    from app.ai import sql_guard

    verdict = sql_guard.validate_sql(sql)
    assert verdict["allowed"] is False
    assert str(verdict["reason"]).startswith(prefix), verdict
    assert verdict["normalized_sql"] == ""


def test_sql_guard_enforces_limit_when_missing_or_excessive():
    from app.ai import sql_guard

    missing = sql_guard.validate_sql("SELECT revenue FROM fact_sales")
    assert missing["allowed"] and missing["limit"] == 200
    assert missing["limit_enforced"] is True
    assert "LIMIT 200" in missing["normalized_sql"]

    huge = sql_guard.validate_sql("SELECT revenue FROM fact_sales LIMIT 999999")
    assert huge["allowed"] and huge["limit"] == 5000
    assert huge["limit_enforced"] is True

    exact = sql_guard.validate_sql("SELECT revenue FROM fact_sales LIMIT 7")
    assert exact["allowed"] and exact["limit"] == 7
    assert exact["limit_enforced"] is False


def test_execute_path_never_runs_destructive_sql(ai_session):
    from app.ai import tools as tool_mod
    from app.database.models import FactSales

    before = ai_session.query(FactSales).count()
    res = tool_mod.execute_tool("query_sql", {"sql": "DROP TABLE fact_sales"}, ai_session)
    assert res["source"] == "sql"
    assert res["data"]["empty"] is True
    assert "ditolak" in res["data"]["error"] and "DROP" not in res["data"]["error"].upper() or \
        "guardrail" in res["data"]["error"]
    assert ai_session.query(FactSales).count() == before


def test_execute_sql_returns_real_rows(ai_session):
    from datetime import date

    from app.ai import tools as tool_mod
    from app.database.models import FactSales

    ai_session.add(FactSales(transaction_date=date(2024, 5, 1), revenue=125.5, quantity=2.0))
    ai_session.commit()
    res = tool_mod.execute_tool(
        "query_sql", {"sql": "SELECT revenue, quantity FROM fact_sales LIMIT 5"}, ai_session)
    assert res["source"] == "sql"
    assert res["data"]["rows"][0]["revenue"] == 125.5
    assert res["data"]["count"] >= 1


# ------------------------------------------------------------------
# grounding / no-evidence refusal
# ------------------------------------------------------------------

def test_no_evidence_refuses_instead_of_inventing(ai_session, monkeypatch):
    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client

    monkeypatch.setattr(
        llm_client, "chat",
        lambda *a, **k: {"content": "Revenue bulan ini 9.999.999 dari 42 cabang.",
                         "tool_calls": [],
                         "raw": {"model": "m", "usage": {}}, "offline": False})
    out = agent_mod.run_agent("berapa revenue fiktif?", ai_session)
    assert "tidak ada evidence" in out["answer"]
    assert "9.999.999" not in out["answer"]
    assert out["confidence"] == 0.0
    assert out["limitations"]


def test_grounded_numbers_pass_through(ai_session, monkeypatch):
    from datetime import date

    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client
    from app.database.models import FactSales

    ai_session.add(FactSales(transaction_date=date.today(), revenue=500.0, quantity=1.0))
    ai_session.commit()
    monkeypatch.setattr(
        llm_client, "chat",
        lambda *a, **k: {"content": "Tercatat 500.0 pada evidence fact_sales.",
                         "tool_calls": [],
                         "raw": {"model": "m", "usage": {}}, "offline": False})
    out = agent_mod.run_agent("ringkasan kpi pendapatan", ai_session)
    assert "500.0" in out["answer"]
    assert out["confidence"] > 0.0


def test_forced_provider_failure_degrades_offline(ai_session, monkeypatch):
    from datetime import date

    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client
    from app.database.models import FactSales

    ai_session.add(FactSales(transaction_date=date.today(), revenue=10.0, quantity=1.0))
    ai_session.commit()

    def boom(*a, **k):
        raise RuntimeError("provider down")

    monkeypatch.setattr(llm_client, "chat", boom)
    out = agent_mod.run_agent("ringkasan kpi", ai_session)
    assert "(LLM offline" in out["answer"]
    assert out["metrics"]["degraded"] is True
    assert any("offline" in entry for entry in out["limitations"])


# ------------------------------------------------------------------
# fallback chain + provider registry + structured validation
# ------------------------------------------------------------------

def test_fallback_chain_uses_second_model_after_first_degrades(monkeypatch):
    from app.ai import llm as llm_client

    calls = []

    def fake_chat(messages, model=None, **kwargs):
        calls.append(model)
        if len(calls) == 1:
            return dict(OFFLINE, raw={"offline": True, "error": "boom"})
        return {"content": "ok", "tool_calls": [],
                "raw": {"model": model}, "offline": False}

    monkeypatch.setattr(llm_client, "chat", fake_chat)
    out = llm_client.chat_with_fallback([{"role": "user", "content": "hi"}],
                                        primary="model-a", fallback="model-b")
    assert out["offline"] is False and out["content"] == "ok"
    assert calls == ["model-a", "model-b"]
    assert out["raw"]["fallback_steps"] == ["model-a", "model-b"]


def test_fallback_chain_exhausted_returns_offline_marker(monkeypatch):
    from app.ai import llm as llm_client

    monkeypatch.setattr(llm_client, "chat",
                        lambda *a, **k: dict(OFFLINE, raw={"offline": True}))
    out = llm_client.chat_with_fallback([{"role": "user", "content": "hi"}],
                                        primary="model-a", fallback="model-b")
    assert out["offline"] is True
    assert out["content"] == llm_client.OFFLINE_NOTE
    assert out["raw"]["fallback_steps"] == ["model-a", "model-b"]


def test_provider_registry_resolves_and_degrades():
    from app.ai import llm as llm_client

    assert llm_client.resolve_provider("openrouter") == "openrouter"
    assert llm_client.resolve_provider("openai") == "openai"
    assert llm_client.resolve_provider("nope-not-real") == "offline"
    assert llm_client.resolve_provider("") == "offline"
    assert llm_client.resolve_provider(None) in llm_client.PROVIDERS


def test_validate_structured_accepts_and_rejects():
    from app.ai import llm as llm_client

    parsed, problem = llm_client.validate_structured('{"sql": "SELECT 1", "x": 1}', ["sql"])
    assert parsed == {"sql": "SELECT 1", "x": 1} and problem == ""
    parsed, problem = llm_client.validate_structured('```json\n{"a": 1}\n```', ["sql"])
    assert parsed is None and problem == "missing_keys:sql"
    parsed, problem = llm_client.validate_structured("bukan json", ["sql"])
    assert parsed is None and problem == "not_json"


# ------------------------------------------------------------------
# cost ledger
# ------------------------------------------------------------------

def test_usage_ledger_math_for_known_model(ai_session):
    from app.ai import cost_tracking

    summary = cost_tracking.record_usage(
        ai_session, 7, "gpt-4o-mini", "openai-compatible", "p", "c",
        provider_usage={"prompt_tokens": 1000, "completion_tokens": 500,
                        "total_tokens": 1500})
    assert summary["recorded"] is True
    assert summary["estimated_cost"] == pytest.approx(0.00045)
    assert summary["total_tokens"] == 1500
    assert summary["cost_note"] == ""

    stored = cost_tracking.get_usage(ai_session, 7)
    assert stored["totals"]["turns"] == 1
    assert stored["totals"]["estimated_cost_total"] == pytest.approx(0.00045)
    assert stored["totals"]["unpriced_rows"] == 0


def test_usage_unknown_model_leaves_cost_null_with_note(ai_session):
    from app.ai import cost_tracking

    summary = cost_tracking.record_usage(
        ai_session, 8, "misteri-9000", "openai-compatible", "prompt", "jawab")
    assert summary["recorded"] is True
    assert summary["estimated_cost"] is None
    assert "unknown model" in summary["cost_note"]
    assert summary["estimated"] is True  # heuristic counts, labelled

    stored = cost_tracking.get_usage(ai_session, 8)
    assert stored["totals"]["unpriced_rows"] == 1
    assert stored["totals"]["estimated_cost_total"] == 0.0


def test_estimate_tokens_is_deterministic():
    from app.ai import cost_tracking

    assert cost_tracking.estimate_tokens("") == 0
    assert cost_tracking.estimate_tokens("abcd" * 10) == 10
    assert cost_tracking.estimate_tokens("x") == 1


# ------------------------------------------------------------------
# prompt templates + conversation context
# ------------------------------------------------------------------

def test_template_registry_defaults_and_falls_back():
    import app.ai.agent as agent_mod

    system, resolved, fell_back = agent_mod.get_template("assistant.v1")
    assert resolved == "assistant.v1" and fell_back is False
    assert "HANYA dari blok" in system
    system2, resolved2, fell_back2 = agent_mod.get_template("tidak-ada")
    assert resolved2 == agent_mod.DEFAULT_TEMPLATE and fell_back2 is True
    assert system2 == system
    assert set(agent_mod.PROMPT_TEMPLATES) >= {"assistant.v1", "assistant.v2", "sql.v1"}


def test_run_agent_reports_template_and_fallback(ai_session, monkeypatch):
    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client

    monkeypatch.setattr(llm_client, "chat",
                        lambda *a, **k: dict(OFFLINE, raw={"offline": True}))
    out = agent_mod.run_agent("ringkasan kpi", ai_session, template="assistant.v2")
    assert out["template"] == "assistant.v2"
    assert out["metrics"]["template_fallback"] is False

    out2 = agent_mod.run_agent("ringkasan kpi", ai_session, template="bogus")
    assert out2["template"] == "assistant.v1"
    assert out2["metrics"]["template_fallback"] is True
    assert any("bogus" in entry for entry in out2["limitations"])


def test_second_turn_sees_first_turn_history(ai_session, monkeypatch):
    from datetime import date

    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client
    from app.database.models import FactSales

    ai_session.add(FactSales(transaction_date=date.today(), revenue=10.0, quantity=1.0))
    ai_session.commit()
    prompts = []

    def fake_chat(messages, **kwargs):
        prompts.append(messages[-1]["content"])
        return {"content": "Tercatat 10.0 pada evidence.", "tool_calls": [],
                "raw": {"model": "m", "usage": {}}, "offline": False}

    monkeypatch.setattr(llm_client, "chat", fake_chat)
    first = agent_mod.run_agent("pertanyaan pertama", ai_session)
    cid = first["conversation_id"]
    assert cid is not None
    agent_mod.run_agent("lalu bagaimana?", ai_session, conversation_id=cid)
    assert len(prompts) == 2
    assert "<history/>" in prompts[0]
    assert "<history role=" in prompts[1]
    assert "pertanyaan pertama" in prompts[1]


def test_run_agent_returns_enriched_contract(ai_session, monkeypatch):
    import app.ai.agent as agent_mod
    from app.ai import llm as llm_client
    from app.schemas.ai import ChatEnrichedResponse

    monkeypatch.setattr(llm_client, "chat",
                        lambda *a, **k: dict(OFFLINE, raw={"offline": True}))
    out = agent_mod.run_agent("tren penjualan", ai_session)
    ChatEnrichedResponse.model_validate(out)  # additive contract shape
    assert out["data_sources"] and out["steps"] >= 1
    assert 0.0 <= out["confidence"] <= 1.0
    assert out["usage"]["recorded"] is True


# ------------------------------------------------------------------
# API surface
# ------------------------------------------------------------------

def test_api_chat_returns_enriched_payload(ai_warehouse, client, service_headers):
    body = {"message": "ringkasan kpi", "context": {}}
    res = client.post("/api/v1/ai/chat", json=body, headers=service_headers)
    assert res.status_code == 200, res.text
    data = res.json()["data"]
    for key in ("answer", "evidence", "data_sources", "metrics", "confidence",
                "limitations", "usage", "template", "conversation_id", "steps"):
        assert key in data, key
    assert data["usage"]["recorded"] is True


def test_api_chat_template_param_and_fallback(ai_warehouse, client, service_headers):
    body = {"message": "ringkasan kpi"}
    res = client.post("/api/v1/ai/chat?template=assistant.v2", json=body,
                      headers=service_headers)
    assert res.status_code == 200
    assert res.json()["data"]["template"] == "assistant.v2"

    res = client.post("/api/v1/ai/chat?template=nope", json=body, headers=service_headers)
    assert res.status_code == 200
    assert res.json()["data"]["metrics"]["template_fallback"] is True

    res = client.post("/api/v1/ai/chat", json={"message": "x" * 4001, "context": {}},
                      headers=service_headers)
    assert res.status_code == 422


def test_api_rag_query_flags_and_citations(ai_warehouse, client, service_headers):
    ingest = {"title": "Kebijakan", "content": "Refund maksimal 14 hari setelah transaksi.",
              "source": "handbook", "doc_type": "txt"}
    res = client.post("/api/v1/rag/ingest", json=ingest, headers=service_headers)
    assert res.status_code == 200, res.text

    for qs in ("", "?hybrid=true&rerank=true", "?hybrid=false&rerank=false"):
        res = client.post(f"/api/v1/rag/query{qs}",
                          json={"query": "refund", "top_k": 3}, headers=service_headers)
        assert res.status_code == 200, res.text
        data = res.json()["data"]
        for key in ("answer", "evidence", "citations", "chunks", "n_results",
                    "confidence", "limitations"):
            assert key in data, (qs, key)
        assert data["n_results"] >= 1
        cited = data["citations"][0]
        assert {"chunk_id", "document_id", "chunk_index", "source", "title",
                "score", "char_start", "char_end"} <= set(cited)


def test_api_usage_and_sql_validate(ai_warehouse, client, service_headers):
    client.post("/api/v1/ai/chat", json={"message": "ringkasan kpi"},
                headers=service_headers)
    res = client.get("/api/v1/ai/usage", headers=service_headers)
    assert res.status_code == 200
    assert res.json()["data"]["totals"]["turns"] >= 1

    res = client.post("/api/v1/ai/sql/validate",
                      json={"sql": "DROP TABLE fact_sales"}, headers=service_headers)
    assert res.status_code == 200
    assert res.json()["data"]["allowed"] is False

    res = client.post("/api/v1/ai/sql/validate",
                      json={"sql": "SELECT revenue FROM fact_sales LIMIT 5"},
                      headers=service_headers)
    assert res.status_code == 200, res.text
    assert res.json()["data"]["allowed"] is True
    assert res.json()["data"]["limit"] == 5
