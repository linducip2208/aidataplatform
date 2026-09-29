"""RAG document ACL: visibility at ingest, enforcement before retrieval."""
from __future__ import annotations


def _ingest(db_session, title, content, visibility="public"):
    from app.ai.rag import ingest_text

    res = ingest_text(title, content, source="acl-test", db_session=db_session,
                      visibility=visibility)
    assert res["status"] in ("created", "updated", "unchanged"), res
    return res


def test_ingest_rejects_unknown_visibility(db_session):
    from app.ai.rag import ingest_text

    res = ingest_text("t", "isi dokumen", source="acl-test",
                      db_session=db_session, visibility="rahasia")
    assert res["status"] == "failed"
    assert res["error"] == "bad_visibility"
    assert res["document_id"] is None


def test_visibility_defaults_to_public(db_session):
    from app.ai.rag import doc_visibility, ingest_text

    res = ingest_text("Publik", "laporan penjualan kuartal", source="acl-test",
                      db_session=db_session)
    assert res["status"] in ("created", "updated", "unchanged")
    assert doc_visibility({}) == "public"

    hits = _query(db_session, "penjualan", allow=None)
    assert any(h["title"] == "Publik" for h in hits)


def test_query_filters_before_retrieval(db_session):
    _ingest(db_session, "Publik", "laporan penjualan kuartal terbuka", "public")
    _ingest(db_session, "Rahasia", "laporan penjualan kuartal rahasia direksi", "confidential")

    pub = _query(db_session, "penjualan kuartal", allow="public")
    assert {h["title"] for h in pub} == {"Publik"}

    all_hits = _query(db_session, "penjualan kuartal",
                      allow="public,internal,confidential")
    assert {h["title"] for h in all_hits} == {"Publik", "Rahasia"}

    legacy = _query(db_session, "penjualan kuartal", allow=None)
    assert {h["title"] for h in legacy} == {"Publik", "Rahasia"}


def test_empty_allowlist_searches_nothing(db_session):
    from app.ai.rag import query

    _ingest(db_session, "Publik", "laporan penjualan kuartal", "public")
    res = query("penjualan", 5, db_session, allowed_visibility=[])
    assert res["n_results"] == 0
    assert res["citations"] == []


def test_excluded_chunks_are_reported(db_session):
    from app.ai.rag import query

    _ingest(db_session, "Rahasia", "kata-kunci-unik-direksi", "confidential")
    res = query("kata-kunci-unik-direksi", 5, db_session, allowed_visibility=["public"])
    assert res["n_results"] == 0
    assert any("ACL" in lim for lim in res["limitations"])


def _query(db_session, text, allow):
    from app.ai.rag import query

    res = query(text, 5, db_session, allowed_visibility=allow)
    return res["citations"]
