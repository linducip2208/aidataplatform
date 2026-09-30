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


def test_list_documents_reports_audience(warehouse):
    # Own session like ai_session: importing the app registers router-side
    # tables the shared db_session fixture never created, so build and clean
    # locally instead of borrowing it.
    import app.ai.rag  # noqa: F401
    try:
        import app.main  # noqa: F401
    except Exception:
        pass
    from sqlalchemy import delete

    from app.database.connection import Base, SessionLocal

    Base.metadata.create_all(warehouse)
    with warehouse.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            try:
                conn.execute(delete(table))
            except Exception:
                pass
    db_session = SessionLocal()
    try:
        from fastapi.testclient import TestClient

        from app.core.config import settings
        from app.core.security import SERVICE_KEY_HEADER
        from app.main import create_app

        _ingest(db_session, "Terbuka", "dokumen terbuka", "public")
        from app.ai.rag import ingest_text
        ingest_text("Pribadi", "dokumen pemilik", source="acl-test",
                    db_session=db_session, visibility="private", owner="7")

        with TestClient(create_app()) as client:
            res = client.get("/api/v1/rag/documents?limit=10",
                             headers={SERVICE_KEY_HEADER: settings.service_api_key})
    finally:
        try:
            db_session.rollback()
        except Exception:
            pass
        try:
            db_session.close()
        except Exception:
            pass
    assert res.status_code == 200
    docs = {d["title"]: d for d in res.json()["data"]}
    assert docs["Terbuka"]["visibility"] == "public"
    assert docs["Pribadi"]["visibility"] == "private"
    assert docs["Pribadi"]["owner"] == "7"
    assert docs["Pribadi"]["n_chunks"] >= 1


def test_private_needs_an_owner(db_session):
    from app.ai.rag import ingest_text

    res = ingest_text("Pribadi", "catatan pribadi pemilik", source="acl-test",
                      db_session=db_session, visibility="private")
    assert res["status"] == "failed"
    assert res["error"] == "private_needs_owner"


def test_private_visible_only_to_owner(db_session):
    from app.ai.rag import ingest_text, query

    _ingest(db_session, "Pengumuman", "pengumuman terbuka untuk semua", "public")
    res = ingest_text("Pribadi", "catatan rahasia pemilik tujuh", source="acl-test",
                      db_session=db_session, visibility="private", owner="7")
    assert res["status"] in ("created", "updated")

    owner_hits = query("catatan rahasia pemilik", 5, db_session,
                       allowed_visibility="public,internal,confidential",
                       user_id="7")
    assert "Pribadi" in {h["title"] for h in owner_hits["citations"]}

    stranger = query("catatan rahasia pemilik", 5, db_session,
                     allowed_visibility="public,internal,confidential",
                     user_id="8")
    assert "Pribadi" not in {h["title"] for h in stranger["citations"]}

    anonymous = query("catatan rahasia pemilik", 5, db_session,
                      allowed_visibility=None)
    assert "Pribadi" not in {h["title"] for h in anonymous["citations"]}
