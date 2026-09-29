"""Enterprise BI tests: registry, thresholds, compare, drilldown, exports, dashboards, API."""
from __future__ import annotations

import io

import pandas as pd
import pytest


def _df():
    return pd.DataFrame([
        {"transaction_date": "2024-01-01", "customer_name": "A", "product_name": "P1",
         "branch_name": "JKT", "revenue": 100.0, "quantity": 1.0},
        {"transaction_date": "2024-01-02", "customer_name": "B", "product_name": "P2",
         "branch_name": "BDG", "revenue": 200.0, "quantity": 2.0},
        {"transaction_date": "2024-02-01", "customer_name": "A", "product_name": "P1",
         "branch_name": "JKT", "revenue": 150.0, "quantity": 1.0},
        {"transaction_date": "2024-02-05", "customer_name": "C", "product_name": "P2",
         "branch_name": "JKT", "revenue": 300.0, "quantity": 3.0},
    ])


def _ensure_bi_tables(warehouse):
    import app.analytics.models  # noqa: F401
    from app.database.connection import Base

    Base.metadata.create_all(warehouse)


@pytest.fixture()
def bi_warehouse(warehouse):
    """Session warehouse plus any tables other agents registered since.

    The shared ``clean_warehouse`` fixture empties every registered table
    strictly, so a model another agent adds (table registered via router
    imports but never created in the test DB) breaks its setup with
    ``no such table``. This fixture creates missing tables first and empties
    tolerantly; it is local to this file so the shared fixture stays
    untouched.
    """
    import app.analytics.models  # noqa: F401
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


# ------------------------------------------------------------------
# registry validation
# ------------------------------------------------------------------

def test_registry_validation_rejects_bad_names():
    from app.analytics import kpi as k

    with pytest.raises(ValueError):
        k.validate_definition({"name": "1bad", "formula": "x"})
    with pytest.raises(ValueError):
        k.validate_definition({"name": "ok_name"})
    good = k.validate_definition({"name": "my_kpi", "formula": "sum(x)", "unit": "IDR",
                                  "target": 10, "warn_threshold": 8, "crit_threshold": 5})
    assert good["name"] == "my_kpi"
    with pytest.raises(ValueError):
        k.validate_definition({"name": "bad_order", "formula": "x",
                               "warn_threshold": 5, "crit_threshold": 8})


def test_thresholds_breach_logic():
    from app.analytics import kpi as k

    assert k.evaluate_kpi("revenue", 12000000.0)["status"] == "ok"
    assert k.evaluate_kpi("revenue", 7000000.0)["status"] == "warn"
    assert k.evaluate_kpi("revenue", 1000.0)["status"] == "crit"
    lower = {"unit": "IDR", "target": 0, "warn_threshold": 100.0,
             "crit_threshold": 200.0, "higher_is_better": False}
    assert k.evaluate_kpi("cost", 50.0, lower)["status"] == "ok"
    assert k.evaluate_kpi("cost", 150.0, lower)["status"] == "warn"
    assert k.evaluate_kpi("cost", 250.0, lower)["status"] == "crit"


def test_compute_values_deterministic():
    from app.analytics import kpi as k

    values = k.compute_kpi_values(_df())
    assert values["revenue"] == 750.0
    assert values["orders"] == 4
    assert values["units"] == 7.0
    empty = k.compute_kpi_values(pd.DataFrame())
    assert empty["revenue"] == 0.0 and empty["orders"] == 0


# ------------------------------------------------------------------
# compare / drilldown / pareto / profitability / employees
# ------------------------------------------------------------------

def test_compare_period_math():
    from app.analytics import kpi as k

    out = k.compare_kpis({"revenue": 120.0, "orders": 10}, {"revenue": 100.0, "orders": 0})
    assert out["kpis"]["revenue"] == {"current": 120.0, "previous": 100.0, "delta": 20.0, "delta_pct": 20.0}
    # previous zero, current non-zero -> 100.0 by contract; both zero -> 0.0
    assert out["kpis"]["orders"]["delta_pct"] == 100.0
    zero = k.compare_kpis({"revenue": 0.0}, {"revenue": 0.0})
    assert zero["kpis"]["revenue"]["delta_pct"] == 0.0


def test_compare_periods_from_frames():
    from app.analytics import kpi as k

    cur = _df().iloc[2:]
    prev = _df().iloc[:2]
    out = k.compare_periods(cur, prev)
    assert out["kpis"]["revenue"]["current"] == 450.0
    assert out["kpis"]["revenue"]["previous"] == 300.0


def test_drilldown_dimensions():
    from app.analytics import kpi as k

    by_branch = k.drilldown(_df(), "branch")
    assert by_branch[0]["label"] == "JKT"
    assert by_branch[0]["revenue"] == 550.0
    by_product = k.drilldown(_df(), "product")
    assert {r["label"] for r in by_product} == {"P1", "P2"}
    by_customer = k.drilldown(_df(), "customer")
    assert len(by_customer) == 3
    assert k.drilldown(pd.DataFrame(), "branch") == []
    with pytest.raises(ValueError):
        k.drilldown(_df(), "nope")


def test_pareto_flags_top_80():
    from app.analytics import kpi as k

    rows = [{"label": "A", "revenue": 70.0}, {"label": "B", "revenue": 20.0},
            {"label": "C", "revenue": 10.0}]
    out = k.pareto_analysis(rows)
    assert out[0]["cumulative_pct"] == 70.0 and out[0]["pareto"] is True
    assert out[-1]["cumulative_pct"] == 100.0 and out[-1]["pareto"] is False


def test_profitability_supported_and_unsupported():
    from app.analytics import kpi as k

    unsupported = k.profitability(_df(), by="product")
    assert unsupported["supported"] is False and unsupported["rows"] == []
    df = _df()
    df["cost_price"] = 50.0
    supported = k.profitability(df, by="product")
    assert supported["supported"] is True
    assert supported["rows"][0]["gross_profit"] > 0


def test_employee_analytics_unsupported_without_column():
    from app.analytics import kpi as k

    out = k.employee_analytics(_df())
    assert out == {"supported": False, "rows": [],
                   "reason": "unsupported: no employee/salesperson column in the sales frame"}
    df = _df()
    df["salesperson"] = ["S1", "S2", "S1", "S2"]
    ok = k.employee_analytics(df)
    assert ok["supported"] is True and len(ok["rows"]) == 2


# ------------------------------------------------------------------
# exports round-trip
# ------------------------------------------------------------------

def test_exports_csv_round_trip():
    from app.analytics import exports as x

    rows = [{"a": 1, "b": "x"}, {"a": 2, "b": "y"}]
    raw = x.to_csv_bytes(rows)
    back = pd.read_csv(io.BytesIO(raw))
    assert list(back.columns) == ["a", "b"]
    assert back["a"].tolist() == [1, 2]
    streamed = b"".join(x.iter_csv(rows))
    assert streamed == raw


def test_exports_xlsx_round_trip(tmp_path):
    from app.analytics import exports as x

    rows = [{"product": "P1", "revenue": 100.5}, {"product": "P2", "revenue": 200.25}]
    raw = x.to_xlsx_bytes(rows)
    assert raw[:2] == b"PK"
    back = pd.read_excel(io.BytesIO(raw))
    assert back["product"].tolist() == ["P1", "P2"]
    assert [round(v, 2) for v in back["revenue"].tolist()] == [100.5, 200.25]
    content, media, name = x.export_rows(rows, "xlsx", filename="abc")
    assert media.startswith("application/") and name == "abc.xlsx"
    with pytest.raises(ValueError):
        x.export_rows(rows, "pdf")


def test_pdf_boundary_documented():
    from app.analytics import exports as x

    assert x.pdf_status() == {"supported": False, "reason": x.PDF_REASON}


# ------------------------------------------------------------------
# dashboards
# ------------------------------------------------------------------

def test_dashboard_catalog_validates():
    from app.analytics import dashboards as d

    for entry in d.DASHBOARDS.values():
        assert d.validate_dashboard(entry) is entry
    with pytest.raises(ValueError):
        d.validate_widget({"id": "x"})
    with pytest.raises(ValueError):
        d.get_dashboard("nope")


def test_dashboard_resolve_uses_real_data():
    from app.analytics import dashboards as d

    resolved = d.resolve_dashboard("executive", _df())
    assert resolved["dashboard"] == "executive"
    by_id = {w["id"]: w for w in resolved["widgets"]}
    assert by_id["executive.kpi"]["data"]["revenue"] == 750.0
    assert isinstance(by_id["executive.trend"]["data"], list)


# ------------------------------------------------------------------
# history persistence
# ------------------------------------------------------------------

def test_history_persistence(bi_warehouse):
    _ensure_bi_tables(bi_warehouse)
    from app.analytics import kpi as k
    from app.database.connection import SessionLocal

    db = SessionLocal()
    try:
        k.register_definition(db, {"name": "custom_rev", "formula": "sum(x)", "unit": "IDR"})
        db.commit()
        names = [d["name"] for d in k.list_definitions(db)]
        assert "custom_rev" in names and "revenue" in names
        rows = k.compute_and_store(_df(), None, None, db_session=db,
                                   period="weekly", filters={"branch": "JKT"})
        db.commit()
        assert len(rows) == len(k.compute_kpi_values(_df()))
        hist = k.get_history(db, kpi_name="revenue", limit=10)
        assert hist and hist[0]["kpi_name"] == "revenue"
        assert hist[0]["period"] == "weekly"
    finally:
        db.close()


def test_snapshot_kpis_beat_entrypoint(bi_warehouse):
    _ensure_bi_tables(bi_warehouse)
    from app.analytics import kpi as k
    from app.database.connection import SessionLocal

    db = SessionLocal()
    try:
        rows = k.snapshot_kpis(db, period="daily")
        db.commit()
        assert rows
    finally:
        db.close()


# ------------------------------------------------------------------
# API
# ------------------------------------------------------------------

def test_bi_api_endpoints(client, service_headers, bi_warehouse):
    _ensure_bi_tables(bi_warehouse)
    # definitions round-trip
    r = client.post("/api/v1/analytics/kpi/definitions",
                    json={"name": "api_kpi", "formula": "sum(x)", "unit": "IDR"},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    assert r.json()["data"]["name"] == "api_kpi"
    r = client.get("/api/v1/analytics/kpi/definitions", headers=service_headers)
    assert r.status_code == 200 and any(d["name"] == "api_kpi" for d in r.json()["data"])
    # bad definition -> 422
    r = client.post("/api/v1/analytics/kpi/definitions", json={"name": "1bad", "formula": "x"},
                    headers=service_headers)
    assert r.status_code == 422
    # compute + history
    r = client.post("/api/v1/analytics/kpi/compute", json={"period": "weekly", "filter": {}},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    assert isinstance(r.json()["data"], list)
    r = client.get("/api/v1/analytics/kpi/history?limit=5", headers=service_headers)
    assert r.status_code == 200 and isinstance(r.json()["data"], list)
    # compare
    r = client.post("/api/v1/analytics/compare",
                    json={"current": {}, "previous": {}}, headers=service_headers)
    assert r.status_code == 200 and "kpis" in r.json()["data"]
    # drilldown
    r = client.post("/api/v1/analytics/drilldown",
                    json={"dimension": "branch", "filter": {}}, headers=service_headers)
    assert r.status_code == 200 and isinstance(r.json()["data"], list)
    r = client.post("/api/v1/analytics/drilldown",
                    json={"dimension": "nope"}, headers=service_headers)
    assert r.status_code == 422
    # dashboards
    r = client.post("/api/v1/analytics/dashboards/resolve",
                    json={"dashboard": "executive", "filter": {}}, headers=service_headers)
    assert r.status_code == 200, r.text
    assert r.json()["data"]["dashboard"] == "executive"
    r = client.post("/api/v1/analytics/dashboards/resolve",
                    json={"dashboard": "nope"}, headers=service_headers)
    assert r.status_code == 422
    # export csv + xlsx from explicit rows
    for fmt, ctype in (("csv", "text/csv"), ("xlsx", "application/")):
        r = client.post("/api/v1/analytics/export",
                        json={"format": fmt, "rows": [{"a": 1}], "filename": "t"},
                        headers=service_headers)
        assert r.status_code == 200, r.text
        assert ctype in r.headers["content-type"]
        assert "attachment" in r.headers["content-disposition"]
    r = client.post("/api/v1/analytics/export", json={"format": "pdf"},
                    headers=service_headers)
    assert r.status_code == 422


def test_existing_analytics_routes_still_work(client, service_headers):
    for path, body in (("/api/v1/analytics/kpi", {}), ("/api/v1/analytics/trend", {}),
                       ("/api/v1/analytics/rfm", {}), ("/api/v1/analytics/abc", {}),
                       ("/api/v1/analytics/cohort", {})):
        r = client.post(path, json=body, headers=service_headers)
        assert r.status_code == 200, (path, r.text)
        assert r.json()["success"] is True
    for path in ("/api/v1/analytics/branches", "/api/v1/analytics/finance"):
        r = client.get(path, headers=service_headers)
        assert r.status_code == 200, (path, r.text)
