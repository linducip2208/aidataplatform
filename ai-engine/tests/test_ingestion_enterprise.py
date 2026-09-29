"""Enterprise ingestion tests (Agent 2 / A2).

Covers: CSV/XLS/XLSX/JSON/JSONL/Parquet happy paths, malformed rows going to
the dead-letter store, resume from checkpoints, content-hash duplicate skip,
the cancel flag, encoding/delimiter edge cases, connector units with faked
transports (httpx MockTransport / sqlite source — fakes live ONLY in these
tests), and the new ingestion API endpoints.

Run: & "<root>\\.venv\\Scripts\\python.exe" -m pytest
         tests/test_ingestion_enterprise.py -q -p no:cacheprovider
(workdir ai-engine/).
"""
from __future__ import annotations

import base64
import json
from pathlib import Path

import pytest

import app.ingestion.models  # noqa: F401  (register enterprise tables on Base)
from app.database.connection import Base, SessionLocal, engine

# Enterprise tables are registered on the shared Base by the import above;
# create them eagerly (checkfirst) so every _reset_schema in this session sees
# tables that exist. The module import runs at collection time, before any
# fixture (and therefore any reset) executes.
if engine.dialect.name == "sqlite":
    Base.metadata.create_all(engine)


# --------------------------------------------------------------------------
# fixtures
# --------------------------------------------------------------------------
@pytest.fixture()
def edb(warehouse):
    """Function-scoped session on a fully emptied warehouse (core + enterprise)."""
    from sqlalchemy import delete

    Base.metadata.create_all(warehouse)
    with warehouse.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            conn.execute(delete(table))
    session = SessionLocal()
    try:
        yield session
    finally:
        session.rollback()
        session.close()


SALES_COLS = ["transaction_date", "customer_name", "product_name",
              "quantity", "selling_price", "branch_name"]
SALES_ROWS = [
    ["2024-01-01", "Budi", "Laptop", 2, 5000000, "Jakarta"],
    ["2024-01-02", "Ani", "Mouse", 5, 150000, "Bandung"],
    ["2024-01-03", "Budi", "Keyboard", 1, 400000, "Jakarta"],
    ["2024-01-04", "Cici", "Monitor", 3, 1200000, "Surabaya"],
    ["2024-01-05", "Dedi", "Mouse", 2, 150000, "Jakarta"],
]


def _sales_csv(path: Path, rows=None) -> Path:
    lines = [",".join(SALES_COLS)]
    for r in (rows if rows is not None else SALES_ROWS):
        lines.append(",".join(str(v) for v in r))
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def _make_job(edb, dataset_type="sales", status="uploaded", stored_path=""):
    from app.database.models import ImportJob, RawUpload

    raw = RawUpload(filename="t.csv", stored_path=str(stored_path), size_bytes=10,
                    mime="text/csv", checksum_sha256="abc", status="received")
    edb.add(raw)
    edb.commit()
    edb.refresh(raw)
    job = ImportJob(upload_id=raw.id, dataset_type=dataset_type, status=status,
                    mapping={}, report={}, error_log=[])
    edb.add(job)
    edb.commit()
    edb.refresh(job)
    return job


# A real BIFF (.xls) workbook generated once with xlwt (3 data rows, same
# shape as SALES_COLS/SALES_ROWS[:3]); embedded so the suite needs no writer
# dependency — the reader under test only needs xlrd, which ships in
# requirements.txt.
_XLS_B64 = (
    "0M8R4KGxGuEAAAAAAAAAAAAAAAAAAAAAPgADAP7/CQAGAAAAAAAAAAAAAAABAAAACQAAAAAAAAAAEAAA/v///wAAAAD+////AAAAAAgAAAD/////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////////////////////////////////////////////////////////8JCBAAAAYFALsNzAcAAAAABgAAAOEAAgCwBMEA"
    "AgAAAOIAAABcAHAATm9uZSAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg"
    "ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIEIAAgCwBGEBAgAAAD0BAgABAJwAAgAOABkAAgAAABIAAgAAAGMAAgAAABMAAgAAAK8BAgAAALwB"
    "AgAAAEAAAgAAAI0AAgAAAD0AEgDgAVoAzz9OKjgAAAAAAAEAWAIiAAIAAAAOAAIAAQC3AQIAAADaAAIAAAAxABUAyAAAAP9/kAEAAAAAAQAFAEFyaWFsMQAV"
    "AMgAAAD/f5ABAAAAAAEABQBBcmlhbDEAFQDIAAAA/3+QAQAAAAABAAUAQXJpYWwxABUAyAAAAP9/kAEAAAAAAQAFAEFyaWFsMQAVAMgAAAD/f5ABAAAAAAEA"
    "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"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAQAAAAIAAAADAAAABAAAAAUAAAAGAAAABwAAAP7////9/////v//////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////////////////////////////////////////////////////////////////////////////////9SAG8AbwB0ACAA"
    "RQBuAHQAcgB5AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAFgAFAf//////////AQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAP7///8AAAAAAAAAAFcAbwByAGsAYgBvAG8AawAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAASAAIB////////////////AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAH///////////////8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    "AAD+////AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAf//////////"
    "/////wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP7///8AAAAAAAAAAA=="
)


@pytest.fixture()
def xls_file(tmp_path: Path) -> Path:
    p = tmp_path / "legacy.xls"
    p.write_bytes(base64.b64decode(_XLS_B64))
    return p


# --------------------------------------------------------------------------
# 1-6. happy paths
# --------------------------------------------------------------------------
def test_csv_happy_path(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion.etl import run_etl

    p = _sales_csv(tmp_path / "sales.csv")
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    assert res["processed_rows"] == 5
    assert res["error_rows"] == 0
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5
    assert res["metrics"]["rows_per_sec"] > 0
    assert res["metrics"]["chunks"] == 3
    assert res["metrics"]["error_rate"] == 0.0


def test_xlsx_happy_path(tmp_path, edb):
    import pandas as pd

    from app.database.models import FactSales
    from app.ingestion.etl import run_etl
    from app.ingestion.reader import iter_chunks

    p = tmp_path / "sales.xlsx"
    pd.DataFrame(SALES_ROWS, columns=SALES_COLS).to_excel(p, index=False)
    assert sum(len(c) for c in iter_chunks(p, chunksize=2)) == 5
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    assert res["processed_rows"] == 5
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5


def test_xls_legacy_read_via_xlrd_not_openpyxl(tmp_path, edb, monkeypatch, xls_file):
    """BIFF .xls must parse even with openpyxl disabled: the reader routes
    .xls to xlrd and never attempts openpyxl for it."""
    import openpyxl

    from app.database.models import FactSales
    from app.ingestion.etl import run_etl
    from app.ingestion.reader import iter_chunks

    # Sanity: this fixture is a genuine OLE2/BIFF workbook, which openpyxl
    # (OOXML-only) cannot parse.
    with pytest.raises(Exception):
        openpyxl.load_workbook(xls_file, read_only=True, data_only=True)

    def _boom(*a, **k):
        raise AssertionError("openpyxl must never be used for BIFF .xls")

    monkeypatch.setattr(openpyxl, "load_workbook", _boom)
    chunks = list(iter_chunks(xls_file, chunksize=2))
    assert sum(len(c) for c in chunks) == 3
    assert list(chunks[0].columns) == SALES_COLS

    job = _make_job(edb, stored_path=xls_file)
    res = run_etl(xls_file, "sales", {}, job.id, edb, chunksize=2)
    assert res["processed_rows"] == 3
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 3


def test_json_happy_path(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion.etl import run_etl

    p = tmp_path / "sales.json"
    p.write_text(json.dumps([dict(zip(SALES_COLS, r)) for r in SALES_ROWS]), encoding="utf-8")
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb)
    assert res["processed_rows"] == 5
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5


def test_jsonl_happy_path(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion.etl import run_etl
    from app.ingestion.reader import iter_chunks

    p = tmp_path / "sales.jsonl"
    with open(p, "w", encoding="utf-8") as fh:
        for r in SALES_ROWS:
            fh.write(json.dumps(dict(zip(SALES_COLS, r))) + "\n")
    assert sum(len(c) for c in iter_chunks(p, chunksize=2)) == 5
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    assert res["processed_rows"] == 5
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5


def test_parquet_happy_path_and_column_projection(tmp_path, edb):
    import pandas as pd

    from app.database.models import FactSales
    from app.ingestion.etl import run_etl
    from app.ingestion.reader import iter_chunks

    p = tmp_path / "sales.parquet"
    pd.DataFrame(SALES_ROWS, columns=SALES_COLS).to_parquet(p, index=False)
    proj = list(iter_chunks(p, chunksize=2, columns=["quantity", "selling_price"]))
    assert sum(len(c) for c in proj) == 5
    assert sorted(proj[0].columns.tolist()) == ["quantity", "selling_price"]
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb)
    assert res["processed_rows"] == 5
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5


# --------------------------------------------------------------------------
# 7. malformed rows -> dead-letter, never a crash
# --------------------------------------------------------------------------
def test_malformed_rows_go_to_dead_letter(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion import checkpoints as cp
    from app.ingestion.etl import run_etl
    from app.ingestion.validator import validate_file

    p = tmp_path / "bad.csv"
    p.write_text(
        "transaction_date,customer_name,product_name,quantity,selling_price,branch_name\n"
        "2024-01-01,Budi,Laptop,2,5000000,Jakarta\n"
        "TOO,MANY,COLUMNS,HERE,EXTRA,ONE,MORE,BEYOND\n"
        "2024-01-02,Ani,Mouse,5,150000,Bandung\n"
        "ALSO,BAD,EXTRA,COLS,X,Y,Z,W\n"
        "2024-01-03,Cici,Keyboard,1,400000,Jakarta\n",
        encoding="utf-8",
    )
    v = validate_file(p)
    assert v["ok"]  # tolerance: malformed lines do not fail validation
    assert len(v["row_errors"]) == 2

    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=10)
    assert res["processed_rows"] == 3
    assert res["dead_letter_count"] == 2
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 3
    letters = cp.list_dead_letters(edb, job.id)
    assert len(letters) == 2
    assert all("malformed_row" in r["reason"] for r in letters)


# --------------------------------------------------------------------------
# 8. resume from checkpoint after a crash
# --------------------------------------------------------------------------
def test_resume_from_checkpoint_after_crash(tmp_path, edb, monkeypatch):
    import app.ingestion.reader as reader_mod
    from app.database.models import FactSales
    from app.ingestion import checkpoints as cp
    from app.ingestion.etl import run_etl

    p = _sales_csv(tmp_path / "sales.csv")
    job = _make_job(edb, stored_path=p)
    real_iter = reader_mod.iter_chunks

    def flaky(path, chunksize=None, **kw):
        for i, chunk in enumerate(real_iter(path, chunksize=chunksize, **kw)):
            yield chunk
            if i == 0:
                raise ConnectionError("simulated crash mid-import")

    monkeypatch.setattr(reader_mod, "iter_chunks", flaky)
    with pytest.raises(RuntimeError):
        run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    # Chunk 0 committed before the crash: 2 rows + 1 checkpoint.
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 2
    assert cp.completed_chunks(edb, job.id) != set()

    monkeypatch.undo()
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    assert res["resumed_from"] == [0]
    assert res["processed_rows"] == 5  # cumulative incl. the resumed chunk
    # No duplication of the already-loaded chunk.
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 5


# --------------------------------------------------------------------------
# 9. content-hash duplicate skip
# --------------------------------------------------------------------------
def test_duplicate_content_skipped(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion.etl import run_etl

    p = _sales_csv(tmp_path / "sales.csv")
    job_a = _make_job(edb, stored_path=p)
    first = run_etl(p, "sales", {}, job_a.id, edb)
    assert first["processed_rows"] == 5 and not first.get("skipped_duplicate")

    job_b = _make_job(edb, stored_path=p)
    second = run_etl(p, "sales", {}, job_b.id, edb)
    assert second.get("skipped_duplicate") is True
    assert second["duplicate_of"] == job_a.id
    assert second["processed_rows"] == 0
    assert edb.query(FactSales).filter_by(import_job_id=job_b.id).count() == 0
    # The original job's rows are untouched.
    assert edb.query(FactSales).filter_by(import_job_id=job_a.id).count() == 5


def test_row_level_duplicate_rows_dropped(tmp_path, edb):
    from app.database.models import FactSales
    from app.ingestion.etl import run_etl

    rows = SALES_ROWS[:2] + SALES_ROWS[:2] + SALES_ROWS[2:3]  # 2 exact dupes
    p = _sales_csv(tmp_path / "dupes.csv", rows=rows)
    job = _make_job(edb, stored_path=p)
    res = run_etl(p, "sales", {}, job.id, edb, skip_duplicates=False)
    assert res["skipped_duplicate_rows"] == 2
    assert res["processed_rows"] == 3
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 3


# --------------------------------------------------------------------------
# 10. cancel flag
# --------------------------------------------------------------------------
def test_cancel_flag_stops_import(tmp_path, edb):
    from app.database.models import FactSales, ImportJob
    from app.ingestion import checkpoints as cp
    from app.ingestion.etl import run_etl

    p = _sales_csv(tmp_path / "sales.csv")
    job = _make_job(edb, stored_path=p)
    assert cp.request_cancel(edb, job.id) is True
    assert cp.is_cancelled(edb, job.id) is True
    res = run_etl(p, "sales", {}, job.id, edb, chunksize=2)
    assert res["cancelled"] is True
    assert res["processed_rows"] == 0
    assert edb.query(FactSales).filter_by(import_job_id=job.id).count() == 0
    assert edb.query(ImportJob).filter_by(id=job.id).first().status == "cancelled"
    # Unknown job: helpers degrade gracefully.
    assert cp.request_cancel(edb, 999999) is False
    assert cp.is_cancelled(None, job.id) is False


# --------------------------------------------------------------------------
# 11. encoding / delimiter edge cases + empty files
# --------------------------------------------------------------------------
def test_latin1_semicolon_csv(tmp_path):
    from app.ingestion.reader import detect_encoding, iter_chunks, sniff_delimiter

    p = tmp_path / "euro.csv"
    p.write_bytes("nama;harga\nMüller;150000\nAndré;200000\n".encode("latin-1"))
    assert detect_encoding(p) == "latin-1"
    assert sniff_delimiter(p) == ";"
    chunks = list(iter_chunks(p, chunksize=10))
    assert len(chunks) == 1
    recs = chunks[0].to_dict("records")
    assert recs[0]["nama"] == "Müller"
    assert recs[1]["nama"] == "André"


def test_tab_delimited_utf8_bom(tmp_path):
    from app.ingestion.reader import iter_chunks, sniff_delimiter

    p = tmp_path / "tabs.csv"
    p.write_bytes("a\tb\n1\t2\n3\t4\n".encode("utf-8-sig"))
    assert sniff_delimiter(p) == "\t"
    chunks = list(iter_chunks(p, chunksize=10))
    assert list(chunks[0].columns) == ["a", "b"]
    assert len(chunks[0]) == 2


def test_empty_file_and_empty_sheet(tmp_path):
    from app.ingestion.reader import iter_chunks
    from app.ingestion.validator import validate_file

    empty = tmp_path / "empty.csv"
    empty.write_text("", encoding="utf-8")
    assert list(iter_chunks(empty)) == []
    v = validate_file(empty)
    assert v["ok"] is False  # 0 bytes is still an error, but it must not crash

    header_only = tmp_path / "header.csv"
    header_only.write_text("a,b,c\n", encoding="utf-8")
    assert list(iter_chunks(header_only)) == []
    v2 = validate_file(header_only)
    assert any("No data rows" in w for w in v2["warnings"])
    assert len(v2.get("row_errors", [])) == 0


# --------------------------------------------------------------------------
# 12. connectors (fakes only in tests)
# --------------------------------------------------------------------------
def test_database_connector_sqlite_chunks(tmp_path):
    import sqlite3

    from app.ingestion.connectors import DatabaseConnector

    db = tmp_path / "src.db"
    conn = sqlite3.connect(db)
    conn.execute("CREATE TABLE orders (id INTEGER PRIMARY KEY, amount REAL)")
    conn.executemany("INSERT INTO orders (amount) VALUES (?)", [(float(i),) for i in range(7)])
    conn.commit()
    conn.close()

    c = DatabaseConnector(f"sqlite:///{db}", table="orders", order_by="id")
    chunks = list(c.iter_chunks(chunksize=3))
    assert [len(x) for x in chunks] == [3, 3, 1]
    assert chunks[0]["amount"].tolist() == [0.0, 1.0, 2.0]

    c2 = DatabaseConnector(f"sqlite:///{db}",
                           query="SELECT * FROM orders ORDER BY id LIMIT {limit} OFFSET {offset}")
    assert sum(len(x) for x in c2.iter_chunks(chunksize=4)) == 7


def test_rest_api_connector_with_mock_transport():
    import httpx

    from app.ingestion.connectors import RestApiConnector

    pages = {1: [{"id": 1}, {"id": 2}], 2: [{"id": 3}], 3: []}

    def handler(request: httpx.Request) -> httpx.Response:
        page = int(request.url.params.get("page", "1"))
        return httpx.Response(200, json={"success": True, "data": pages.get(page, [])})

    client = httpx.Client(transport=httpx.MockTransport(handler), base_url="https://x.test")
    c = RestApiConnector("https://x.test", "/orders", client=client)
    chunks = list(c.iter_chunks(chunksize=2))
    assert [len(x) for x in chunks] == [2, 1]
    assert chunks[1]["id"].tolist() == [3]


def test_rest_api_connector_rejects_unusable_endpoint():
    import httpx

    from app.ingestion.connectors import IntegrationBoundary, RestApiConnector

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"success": True})  # no data list

    client = httpx.Client(transport=httpx.MockTransport(handler), base_url="https://x.test")
    with pytest.raises(IntegrationBoundary):
        list(RestApiConnector("https://x.test", "/odd", client=client).iter_chunks())


def test_connector_boundaries():
    from app.ingestion.connectors import DatabaseConnector, IntegrationBoundary, RestApiConnector

    with pytest.raises(IntegrationBoundary):
        DatabaseConnector("")
    with pytest.raises(IntegrationBoundary):
        DatabaseConnector("postgresql+asyncpg://u:p@h/db", table="t")
    with pytest.raises(IntegrationBoundary):
        DatabaseConnector("sqlite:///x.db")  # neither query nor table
    with pytest.raises(IntegrationBoundary):
        RestApiConnector("")


def test_scheduled_import_due_logic():
    from datetime import datetime, timezone

    from app.ingestion.connectors import ScheduledImport

    now = datetime(2026, 9, 29, 12, 0, tzinfo=timezone.utc)
    # Every minute: a run an hour ago means due.
    s = ScheduledImport(name="hourly", cron="* * * * *",
                        last_run_at=datetime(2026, 9, 29, 11, 0, tzinfo=timezone.utc))
    assert s.is_due(now) is True
    # Just ran this minute: not due.
    s.last_run_at = datetime(2026, 9, 29, 11, 59, tzinfo=timezone.utc)
    # A per-minute tick at 11:59 already covered (11:59, 12:00] contains 12:00 tick
    assert s.is_due(now) is True
    s.last_run_at = now
    assert s.is_due(now) is False
    # Disabled / invalid specs are never due (and never raise).
    s.enabled = False
    assert s.is_due(now) is False
    bad = ScheduledImport(name="bad", cron="not a cron")
    assert bad.is_due(now) is False
    # Round-trip through dict storage.
    s2 = ScheduledImport.from_dict(s.to_dict())
    assert (s2.name, s2.cron, s2.enabled) == (s.name, s.cron, s.enabled)


# --------------------------------------------------------------------------
# 13. checkpoint store helpers
# --------------------------------------------------------------------------
def test_checkpoint_helpers(edb):
    from app.ingestion import checkpoints as cp

    assert cp.list_checkpoints(None, 1) == []
    assert cp.completed_chunks(None, 1) == set()
    cp.save_checkpoint(None, 1, 0, 10)  # no-op, must not raise
    job = _make_job(edb)
    cp.save_checkpoint(edb, job.id, 0, 100, "done", "hash1")
    cp.save_checkpoint(edb, job.id, 1, 250, "started", "hash1")
    edb.commit()
    assert cp.completed_chunks(edb, job.id, "hash1") == {0}
    assert cp.completed_chunks(edb, job.id, "other-hash") == set()
    assert cp.last_rows_done(edb, job.id, "hash1") == 100
    rows = cp.list_checkpoints(edb, job.id)
    assert [r["chunk_index"] for r in rows] == [0, 1]
    assert cp.clear_checkpoints(edb, job.id) == 2
    edb.commit()
    assert cp.list_checkpoints(edb, job.id) == []


# --------------------------------------------------------------------------
# 14. enterprise API endpoints
#
# NOTE: app/api/v1/router.py is wired by master at integration (A2 must not
# touch existing routers), so these tests mount the new router on a throwaway
# FastAPI app against the same warehouse. The route shapes asserted here are
# exactly what master will expose under /api/v1.
# --------------------------------------------------------------------------
@pytest.fixture(scope="module")
def ingestion_client(warehouse):
    from fastapi import FastAPI
    from fastapi.testclient import TestClient

    from app.api.v1 import ingestion as ingestion_router

    app = FastAPI()
    app.include_router(ingestion_router.router, prefix="/api/v1")
    with TestClient(app) as c:
        yield c


def test_ingestion_api_cancel_resume_checkpoints_dead_letter(client, ingestion_client,
                                                             service_headers, tmp_path):
    from app.ingestion import checkpoints as cp

    p = tmp_path / "api.csv"
    _sales_csv(p)
    with open(p, "rb") as fh:
        r = client.post("/api/v1/imports/upload", files={"file": ("api.csv", fh, "text/csv")},
                        data={"dataset_type": "sales"}, headers=service_headers)
    assert r.status_code == 200, r.text
    job_id = r.json()["data"]["import_job_id"]
    api = ingestion_client

    r = api.get(f"/api/v1/imports/{job_id}/checkpoints", headers=service_headers)
    assert r.status_code == 200 and r.json()["data"]["total_chunks"] == 0

    r = api.post(f"/api/v1/imports/{job_id}/cancel", headers=service_headers)
    assert r.status_code == 200 and r.json()["data"]["cancelled"] is True

    # A cancelled job refuses to resume without an explicit override.
    r = api.post(f"/api/v1/imports/{job_id}/resume", json={}, headers=service_headers)
    assert r.status_code == 409

    r = api.post(f"/api/v1/imports/{job_id}/resume", json={"clear_cancel": True},
                 headers=service_headers)
    assert r.status_code == 200, r.text
    assert r.json()["data"]["processed_rows"] == 5

    r = api.get(f"/api/v1/imports/{job_id}/checkpoints", headers=service_headers)
    assert r.json()["data"]["done_chunks"] >= 1

    r = api.get(f"/api/v1/imports/{job_id}/dead-letter", headers=service_headers)
    assert r.status_code == 200 and r.json()["data"]["total"] == 0

    # Cancelling a completed job is a no-op success.
    r = api.post(f"/api/v1/imports/{job_id}/cancel", headers=service_headers)
    assert r.json()["data"]["cancelled"] is False

    # Unknown jobs 404 inside the envelope.
    assert api.get("/api/v1/imports/999999/checkpoints",
                   headers=service_headers).status_code == 404
    assert api.post("/api/v1/imports/999999/cancel",
                    headers=service_headers).status_code == 404

    # No credential -> 401 like every other guarded route.
    assert api.get(f"/api/v1/imports/{job_id}/checkpoints").status_code == 401


def test_ingestion_api_dead_letter_lists_malformed(client, ingestion_client,
                                                   service_headers, tmp_path):
    p = tmp_path / "api_bad.csv"
    p.write_text(
        "transaction_date,customer_name,product_name,quantity,selling_price,branch_name\n"
        "2024-01-01,Budi,Laptop,2,5000000,Jakarta\n"
        "TOO,MANY,COLS,EXTRA,ONE,MORE,BEYOND\n",
        encoding="utf-8",
    )
    with open(p, "rb") as fh:
        r = client.post("/api/v1/imports/upload", files={"file": ("api_bad.csv", fh, "text/csv")},
                        data={"dataset_type": "sales"}, headers=service_headers)
    job_id = r.json()["data"]["import_job_id"]
    api = ingestion_client
    r = api.post(f"/api/v1/imports/{job_id}/resume", json={}, headers=service_headers)
    assert r.status_code == 200, r.text
    assert r.json()["data"]["dead_letter_count"] == 1
    r = api.get(f"/api/v1/imports/{job_id}/dead-letter?limit=10&offset=0",
                headers=service_headers)
    recs = r.json()["data"]["records"]
    assert len(recs) == 1 and "malformed_row" in recs[0]["reason"]
