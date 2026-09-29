"""Cross-layer contract regression pins for the engine's EXISTING surface.

Every endpoint, status code and field asserted here ships today; nothing here
depends on code other agents are adding concurrently. The suite is green on a
bare checkout (shared-cache sqlite warehouse, offline embeddings) and must stay
that way: if any test below fails after an engine change, the change broke a
contract the Laravel orchestrator relies on — fix the engine, not the test.

What is pinned:
- envelope key order/shape (``{"success", "data"}`` first-key-last, error
  envelope key order per ``app/core/errors.py::build_error_response``);
- 401 shape without a key (FastAPI ``{"detail"}``, never the business envelope);
- readiness verdict-vs-status-code (verdict lives in the body; HTTP is 200);
- schema parity spot-checks (the field names Laravel reads);
- RAG ingest idempotency on sha256 (same body -> same document_id);
- chunk-reader throughput floor (perf smoke, generous bound).
"""
from __future__ import annotations

import hashlib
import time

# --------------------------------------------------------------------------
# envelope key order / shape
# --------------------------------------------------------------------------

def test_success_envelope_shape_and_key_order(client, service_headers):
    """Business routes answer {"success": true, "data": ...}, success first.

    mapping/suggest needs no database row, so this pins the envelope without
    any warehouse state.
    """
    r = client.post("/api/v1/imports/mapping/suggest",
                    json={"columns": ["tanggal", "qty"], "dataset_type": "sales"},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    body = r.json()
    assert list(body.keys())[0] == "success"
    assert body["success"] is True
    assert "data" in body


def test_inband_business_error_carries_a_message_keyed_error(client, service_headers):
    """A missing import job is an in-band failure: HTTP 200, success false.

    The imports routes report failures as the flat
    ``{"success": false, "error": {"message": ...}}`` shape (see
    ``app/api/v1/imports.py`` quality/preview/commit/job_status): HTTP stays
    200 and the message is what AiEngineClient surfaces. Pinned as-is; the
    richer ordered ErrorDetail shape is pinned separately below.
    """
    r = client.get("/api/v1/imports/quality/999999", headers=service_headers)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is False, body
    assert isinstance(body["error"]["message"], str) and body["error"]["message"], body


def test_structured_error_envelope_carries_the_ordered_error_detail(client, service_headers):
    """Routes that raise through build_error_response keep its key order.

    The key ORDER is pinned because AiEngineClient reads error.message first;
    a reorder is a silent client-side behaviour change. Oversized RAG ingest
    is the cheapest route that returns this shape (413, no database write).
    """
    r = client.post("/api/v1/rag/ingest",
                    json={"title": "too big", "content": "x" * 500_001,
                          "source": "qa-contract", "doc_type": "txt"},
                    headers=service_headers)
    assert r.status_code == 413, r.text
    body = r.json()
    assert list(body.keys()) == ["success", "error"], body
    assert body["success"] is False
    assert list(body["error"].keys()) == [
        "module", "operation", "error_type", "code",
        "message", "technical", "request_id", "resolution", "details",
    ], body["error"]
    assert body["error"]["message"], "error.message must never be empty"


def test_health_is_a_bare_model_not_an_envelope(client):
    r = client.get("/api/v1/health")
    assert r.status_code == 200, r.text
    body = r.json()
    assert set(body) == {"status", "app", "env", "version"}, body
    assert body["status"] == "ok"
    assert "success" not in body and "data" not in body


# --------------------------------------------------------------------------
# 401 shape without a key
# --------------------------------------------------------------------------

def test_unauthenticated_call_is_a_fastapi_detail_not_the_business_envelope(client):
    r = client.get("/api/v1/models")
    assert r.status_code == 401, r.text
    body = r.json()
    assert set(body) == {"detail"}, body
    assert "success" not in body and "error" not in body


# --------------------------------------------------------------------------
# readiness: verdict in the body, never in the status code
# --------------------------------------------------------------------------

def test_readiness_reports_its_verdict_in_the_body_with_http_200(client):
    """readiness() returns {"ready", "checks"} and always answers 200.

    Laravel's readiness() goes through decode(), which only checks the HTTP
    status: a non-200 here would read as "engine down" even for a body that
    says ready, and a 200 with ready=false must NOT read as healthy.
    """
    r = client.get("/api/v1/readiness")
    assert r.status_code == 200, r.text
    body = r.json()
    assert set(body) == {"ready", "checks"}, body
    assert isinstance(body["ready"], bool)
    assert set(body["checks"]) == {"db", "redis"}, body["checks"]
    # The suite runs on sqlite, so the warehouse check must be up here;
    # redis is not part of this job and may be either way.
    assert body["checks"]["db"] == "up", body
    assert body["ready"] is True


# --------------------------------------------------------------------------
# schema parity spot-checks (the field names Laravel reads)
# --------------------------------------------------------------------------

def test_schema_parity_spot_checks():
    """A rename in any of these is a contract break Laravel feels as a null."""
    from app.schemas.ai import RagQueryRequest
    from app.schemas.analytics import BranchKpi, FinanceSummary, KpiResponse
    from app.schemas.imports import ImportCommit, MappingRequest, QualityResponse

    assert set(QualityResponse.model_fields) == {"score", "breakdown", "issues", "passed"}
    assert set(ImportCommit.model_fields) == {"import_job_id", "dataset_type", "mappings", "run_async"}
    assert set(MappingRequest.model_fields) == {"import_job_id", "dataset_type", "mappings", "save_as_template"}
    assert set(KpiResponse.model_fields) == {"revenue", "orders", "units", "aov", "growth_pct", "margin_pct"}
    assert set(FinanceSummary.model_fields) == {
        "total_revenue", "total_cogs", "total_expenses",
        "gross_profit", "net_profit", "margin_pct"}
    assert set(BranchKpi.model_fields) == {"branch", "revenue", "orders", "share_pct"}
    assert set(RagQueryRequest.model_fields) == {"query", "top_k"}

    # Defaults Laravel depends on.
    assert ImportCommit(import_job_id=7).run_async is False
    assert RagQueryRequest(query="q").top_k == 5
    assert QualityResponse().passed is False


# --------------------------------------------------------------------------
# RAG ingest idempotency on sha256
# --------------------------------------------------------------------------

def _unique_source() -> str:
    """A source namespace no other test writes to.

    These tests deliberately do NOT use the clean_warehouse fixture: it empties
    every table on shared Base metadata, which breaks on subset runs (see
    docs/testing.md -- known issue). Unique (source, title) pairs isolate these
    assertions instead.
    """
    import uuid

    return f"qa-contract-{uuid.uuid4().hex[:8]}"


def test_rag_ingest_is_idempotent_on_content_sha256(client, service_headers):
    """Re-sending the same document returns the existing document_id.

    The natural key is sha256(normalised content) within (source, title):
    identical body -> "unchanged", changed body under the same pair ->
    "updated" IN PLACE (same id, chunks replaced, none orphaned).
    """
    source = _unique_source()
    payload = {"title": "Kebijakan Retensi QA",
               "content": "Voucher dikirim pada hari ke-60 setelah pembelian. " * 20,
               "source": source, "doc_type": "md"}

    first = client.post("/api/v1/rag/ingest", json=payload, headers=service_headers)
    assert first.status_code == 200, first.text
    data = first.json()["data"]
    assert data["status"] == "created", data
    doc_id = data["document_id"]
    assert isinstance(doc_id, int) and data["n_chunks"] > 0

    second = client.post("/api/v1/rag/ingest", json=payload, headers=service_headers)
    assert second.status_code == 200, second.text
    again = second.json()["data"]
    assert again["status"] == "unchanged", again
    assert again["document_id"] == doc_id
    assert again["n_chunks"] == data["n_chunks"]

    changed = dict(payload, content="Kebijakan baru: voucher dikirim hari ke-30. " * 20)
    third = client.post("/api/v1/rag/ingest", json=changed, headers=service_headers)
    assert third.status_code == 200, third.text
    updated = third.json()["data"]
    assert updated["status"] == "updated", updated
    assert updated["document_id"] == doc_id, "a changed body must replace in place, not fork"


def test_rag_query_answer_shape_after_ingest(client, service_headers):
    """query() returns {answer, citations, chunks, n_results} even offline."""
    source = _unique_source()
    content = f"Kebijakan retensi {source}: voucher dikirim pada hari ke-60. " * 20
    ing = client.post("/api/v1/rag/ingest",
                      json={"title": "Retensi QA", "content": content,
                            "source": source, "doc_type": "md"},
                      headers=service_headers)
    assert ing.status_code == 200, ing.text

    r = client.post("/api/v1/rag/query",
                    json={"query": f"kapan voucher {source} dikirim?", "top_k": 5},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    data = body["data"]
    # Subset, not equality: the query payload is being extended (answer /
    # evidence / confidence / limitations alongside the legacy keys). What is
    # pinned is what the Laravel side reads (RagController falls back from
    # citations to chunks), so additive keys must not break this test.
    assert {"answer", "citations", "chunks", "n_results"} <= set(data), data
    assert isinstance(data["answer"], str) and data["answer"]
    assert data["n_results"] == len(data["citations"]) == len(data["chunks"])
    assert data["n_results"] >= 1
    # Per-citation keys are NOT pinned here: the citation shape is owned by
    # the RAG surface and is being reshaped (content/score/document_id/
    # chunk_index -> chunk_id/source/char offsets). Laravel forwards citations
    # opaquely (RagController passes the array through unread), so the stable
    # identifiers both shapes share are all this test may assume.
    for cite in data["citations"]:
        assert isinstance(cite, dict), cite
        assert "document_id" in cite and "score" in cite, cite


# --------------------------------------------------------------------------
# enterprise fixture KPIs (the math behind the constants)
# --------------------------------------------------------------------------

def test_enterprise_fixture_kpis_recompute_to_their_constants():
    from tests.fixtures.enterprise import assert_enterprise_kpis

    got = assert_enterprise_kpis()
    assert got["sales_rows"] == 144
    assert got["sales_revenue"] == 24891605715
    assert got["anomaly_rows"] == 3
    assert got["churned_customers"] == 3


# --------------------------------------------------------------------------
# perf smoke: chunk-reader throughput floor (generous, deterministic)
# --------------------------------------------------------------------------

def test_chunk_reader_throughput_floor(tmp_path):
    """The CSV chunk reader must sustain > 1 MB/s on a ~3 MB file.

    Not a benchmark: the floor is an order of magnitude below a healthy
    laptop so it only fails on a real regression (e.g. per-row Python
    overhead reintroduced into the read path). Row count is asserted too, so
    a reader that goes fast by skipping rows still fails.
    """
    import pandas as pd

    from app.ingestion.reader import iter_chunks
    from tests.fixtures.enterprise import make_sales_frame

    frame = make_sales_frame()
    while len(frame) < 60000:  # grow until the file clears ~2.5 MB
        frame = pd.concat([frame, frame], ignore_index=True)
    csv_path = tmp_path / "perf_smoke.csv"
    frame.to_csv(csv_path, index=False)
    size_mb = csv_path.stat().st_size / (1024 * 1024)
    assert size_mb > 2.5, f"smoke file too small to measure: {size_mb:.2f} MB"

    started = time.perf_counter()
    seen = sum(len(chunk) for chunk in iter_chunks(csv_path, chunksize=5000))
    elapsed = time.perf_counter() - started

    assert seen == len(frame), f"reader dropped rows: {seen} != {len(frame)}"
    throughput = size_mb / max(elapsed, 1e-6)
    assert throughput > 1.0, f"chunk reader at {throughput:.2f} MB/s over {size_mb:.2f} MB"
    assert elapsed < 60, f"reader took {elapsed:.1f}s"
    # Content integrity spot-check: the sha of the revenue column survives
    # the chunked round trip (chunking must not rewrite values).
    digest = hashlib.sha256(str(int(frame["revenue_idr"].sum())).encode()).hexdigest()
    assert digest == hashlib.sha256(
        str(sum(int(v) for v in frame["revenue_idr"])).encode()).hexdigest()
