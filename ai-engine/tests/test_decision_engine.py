"""Decision engine tests: evidence, rules/scoring, scenarios, audit, API.

Explicit-unsupported policy is pinned here: an unsupported scenario returns
``supported=False`` with non-empty ``reasons`` and NO numeric ``deltas`` at
all — never zeros or textbook constants dressed up as simulation output.
"""
from __future__ import annotations

import pandas as pd
import pytest
from fastapi import APIRouter
from fastapi.testclient import TestClient

import app.main  # noqa: F401 (register every router-reachable mapper before warehouse create_all)
import app.decision.models  # noqa: F401 (register the decision mappers likewise)


@pytest.fixture(scope="module")
def client(warehouse):
    """App with the decision router mounted exactly as master will wire it.

    ``router.py`` is master-owned, so the two include lines below live here
    until integration adds them to ``app/api/v1/router.py``::

        from app.api.v1 import decision  # alongside the other routers
        router.include_router(decision.router)
    """
    from app.api.v1.decision import router as decision_router
    from app.main import create_app

    v1 = APIRouter(prefix="/api/v1")
    v1.include_router(decision_router)
    app = create_app()
    app.include_router(v1)
    with TestClient(app) as test_client:
        yield test_client


def _rich_frame(n_days: int = 20, declining: bool = False) -> pd.DataFrame:
    rows = []
    customers = ["C1", "C2", "C3", "C4", "C5", "C6"]
    for i in range(n_days):
        price = 10.0 + i  # strictly increasing price
        qty = max(1.0, 50.0 - price)  # strictly decreasing qty: negative slope
        revenue = qty * price
        if declining and i >= n_days // 2:
            revenue *= 0.3
            qty *= 0.3
        rows.append({
            "transaction_date": f"2024-01-{i + 1:02d}",
            "customer_name": customers[i % len(customers)],
            "product_name": f"P{(i % 3) + 1}",
            "branch_name": "JKT",
            "revenue": round(revenue, 2),
            "quantity": round(qty, 2),
            "selling_price": price,
        })
    return pd.DataFrame(rows)


def _ensure_decision_tables(warehouse):
    import app.decision.models  # noqa: F401
    from app.database.connection import Base

    Base.metadata.create_all(warehouse)


@pytest.fixture()
def decision_warehouse(warehouse):
    """Warehouse with decision tables created, emptied tolerantly."""
    import app.decision.models  # noqa: F401
    try:
        import app.main  # noqa: F401
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
def decision_session(decision_warehouse):
    from app.database.connection import SessionLocal

    session = SessionLocal()
    try:
        yield session
    finally:
        session.rollback()
        session.close()


# ------------------------------------------------------------------
# evidence
# ------------------------------------------------------------------

def test_empty_frame_marks_every_source_unavailable():
    from app.decision import evidence as ev

    out = ev.collect_evidence({"branch": "JKT"}, sales_df=pd.DataFrame())
    assert out["subject"]["branch"] == "JKT"
    assert len(out["evidence"]) == 4
    for item in out["evidence"]:
        assert item["status"] == "unavailable"
        assert item["confidence"] == 0.0
        assert item["reason"] != ""
        assert item["metrics"] == {}
        assert set(item) >= {"id", "kind", "source", "metrics",
                             "observed_at", "confidence"}


def test_evidence_collects_from_synthetic_frame():
    from app.decision import evidence as ev

    out = ev.collect_evidence({"branch": "JKT"}, sales_df=_rich_frame())
    by_kind = {e["kind"]: e for e in out["evidence"]}
    assert by_kind["kpi"]["status"] == "available"
    assert by_kind["kpi"]["metrics"]["values"]["revenue"] > 0
    assert by_kind["kpi"]["confidence"] > 0
    # anomaly needs >= 6 daily points: 20 days qualifies
    assert by_kind["anomaly"]["status"] == "available"
    assert by_kind["forecast"]["status"] == "available"
    assert by_kind["forecast"]["metrics"]["method"] != "insufficient_data"
    assert by_kind["ml_prediction"]["status"] == "available"
    avail = ev.available_evidence(out)
    assert {e["kind"] for e in avail} >= {"kpi", "forecast"}
    assert len(ev.evidence_by_id(out)) == 4


def test_evidence_ids_are_deterministic_order():
    from app.decision import evidence as ev

    out = ev.collect_evidence({}, sales_df=_rich_frame())
    assert [e["id"] for e in out["evidence"]] == [
        "ev-0001", "ev-0002", "ev-0003", "ev-0004"]
    assert [e["kind"] for e in out["evidence"]] == [
        "kpi", "anomaly", "forecast", "ml_prediction"]


# ------------------------------------------------------------------
# rules + scoring
# ------------------------------------------------------------------

def test_scoring_math_matches_documented_formula():
    from app.decision import rules as ru

    # score = 100 * (0.6*severity + 0.4*mean_conf)
    assert ru.score_recommendation(1.0, [1.0]) == 100.0
    assert ru.score_recommendation(0.0, [0.0]) == 0.0
    assert ru.score_recommendation(0.5, [0.5]) == 50.0
    assert ru.score_recommendation(1.0, [0.0]) == 60.0
    assert ru.score_recommendation(0.0, [1.0]) == 40.0
    # no evidence -> mean_conf 0
    assert ru.score_recommendation(0.8, []) == round(100 * 0.6 * 0.8, 2)
    # clamping
    assert ru.score_recommendation(2.0, [5.0]) == 100.0
    assert 0.0 <= ru.score_recommendation(0.33, [0.77]) <= 100.0


def test_revenue_drop_rule_fires_on_declining_frame():
    from app.decision import evidence as ev
    from app.decision import rules as ru

    out = ev.collect_evidence({}, sales_df=_rich_frame(20, declining=True))
    results = {r["rule"]: r for r in ru.evaluate_rules(out["evidence"])}
    assert results["revenue_drop_rule"]["fired"] is True
    assert results["revenue_drop_rule"]["version"] == ru.RULES_VERSION
    assert results["revenue_drop_rule"]["score"] > 0
    # every fired rule references real evidence
    ids = {e["id"] for e in out["evidence"]}
    for ref in results["revenue_drop_rule"]["evidence_ids"]:
        assert ref in ids


def test_rules_do_not_fire_on_empty_evidence():
    from app.decision import evidence as ev
    from app.decision import rules as ru

    out = ev.collect_evidence({}, sales_df=pd.DataFrame())
    for r in ru.evaluate_rules(out["evidence"]):
        assert r["fired"] is False
        assert r["severity"] == 0.0


# ------------------------------------------------------------------
# scenarios
# ------------------------------------------------------------------

def test_price_scenario_supported_path_has_ordered_ci():
    from app.decision import scenarios as sc

    res = sc.run_scenario("price_change_pct", {"price_change_pct": 10.0},
                          {}, sales_df=_rich_frame())
    assert res["supported"] is True
    assert res["elasticity"] < 0
    # qty falls when price rises (negative elasticity); revenue direction
    # follows elasticity magnitude and is NOT asserted here — only that the
    # numbers are ordered and finite, never fabricated.
    assert res["deltas"]["units_pct"] < 0
    ci = res["confidence_interval"]
    assert ci["revenue_lower"] <= res["deltas"]["revenue"] <= ci["revenue_upper"]
    assert res["assumptions"] != []


def test_price_scenario_unsupported_without_price_basis_has_no_deltas():
    from app.decision import scenarios as sc

    df = _rich_frame().drop(columns=["selling_price"])
    res = sc.run_scenario("price_change_pct", {"price_change_pct": 10.0},
                          {}, sales_df=df)
    assert res["supported"] is False
    assert res["reasons"] != []
    assert "deltas" not in res  # NO fabricated numbers
    assert "confidence_interval" not in res


def test_price_scenario_unsupported_on_flat_prices():
    from app.decision import scenarios as sc

    df = _rich_frame()
    df["selling_price"] = 10.0
    res = sc.run_scenario("price_change_pct", {"price_change_pct": 10.0},
                          {}, sales_df=df)
    assert res["supported"] is False
    assert res["reasons"] != []
    assert "deltas" not in res


def test_price_scenario_unsupported_on_tiny_frame():
    from app.decision import scenarios as sc

    res = sc.run_scenario("price_change_pct", {"price_change_pct": 5.0},
                          {}, sales_df=_rich_frame(3))
    assert res["supported"] is False
    assert "deltas" not in res


def test_inventory_scenario_supported_and_unsupported():
    from app.decision import scenarios as sc

    ok = sc.run_scenario("inventory_change_pct", {"inventory_change_pct": 10.0},
                         {}, sales_df=_rich_frame())
    assert ok["supported"] is True
    assert ok["deltas"]["revenue"] > 0
    assert ok["confidence_interval"]["revenue_lower"] <= ok["deltas"]["revenue"]
    assert any("Demand" in a for a in ok["assumptions"])

    bad = sc.run_scenario("inventory_change_pct", {"inventory_change_pct": 10.0},
                          {}, sales_df=pd.DataFrame())
    assert bad["supported"] is False
    assert bad["reasons"] != []
    assert "deltas" not in bad


def test_churn_scenario_supported_and_unsupported():
    from app.decision import scenarios as sc

    ok = sc.run_scenario("churn_rise_pp", {"churn_rise_pp": 5.0},
                         {}, sales_df=_rich_frame())
    assert ok["supported"] is True
    assert ok["deltas"]["revenue"] < 0
    assert ok["deltas"]["baseline_customers"] == 6

    few = _rich_frame()
    few["customer_name"] = "ONLY"
    bad = sc.run_scenario("churn_rise_pp", {"churn_rise_pp": 5.0},
                          {}, sales_df=few)
    assert bad["supported"] is False
    assert "deltas" not in bad

    no_cust = _rich_frame().drop(columns=["customer_name"])
    bad2 = sc.run_scenario("churn_rise_pp", {"churn_rise_pp": 5.0},
                           {}, sales_df=no_cust)
    assert bad2["supported"] is False
    assert "deltas" not in bad2


def test_scenario_validation_rejects_bad_type_and_range():
    from app.decision import scenarios as sc

    with pytest.raises(ValueError):
        sc.run_scenario("moon_landing", {}, {}, sales_df=_rich_frame())
    with pytest.raises(ValueError):
        sc.run_scenario("price_change_pct", {"price_change_pct": 500.0},
                        {}, sales_df=_rich_frame())
    with pytest.raises(ValueError):
        sc.run_scenario("price_change_pct", {}, {}, sales_df=_rich_frame())


# ------------------------------------------------------------------
# explanations
# ------------------------------------------------------------------

def test_explanation_builder_shape():
    from app.decision import evidence as ev
    from app.decision import explain as ex

    out = ev.collect_evidence({}, sales_df=_rich_frame())
    xp = ex.build_explanation("Do X", ["ev-0001", "ev-9999"], out["evidence"], score=42.0)
    assert set(xp) == {"summary", "drivers", "confidence", "limitations"}
    assert len(xp["drivers"]) == 2
    assert xp["drivers"][0]["kind"] == "kpi"
    assert "not present" in xp["drivers"][1]["note"]
    assert any("never imputed" in lim for lim in xp["limitations"])


# ------------------------------------------------------------------
# orchestration + persistence
# ------------------------------------------------------------------

def test_recommend_without_session_computes_but_persists_nothing(decision_session):
    from app.decision import recommendations as rec

    out = rec.recommend({"branch": "JKT"}, sales_df=_rich_frame(20, declining=True))
    assert out["case_id"] is None
    assert out["recommendations"] != []
    for r in out["recommendations"]:
        assert set(r) >= {"action", "expected_impact", "confidence",
                          "evidence_ids", "scenario_ref", "explanation"}
        assert set(r["explanation"]) == {"summary", "drivers",
                                         "confidence", "limitations"}
    from app.decision.models import DecisionCase

    assert decision_session.query(DecisionCase).count() == 0


def test_recommend_persists_case_and_system_audit(decision_session):
    from app.decision import recommendations as rec
    from app.decision.models import DecisionAudit, DecisionCase, DecisionRecommendation

    out = rec.recommend({"branch": "JKT"}, sales_df=_rich_frame(20, declining=True),
                        db_session=decision_session)
    decision_session.commit()
    assert out["case_id"] is not None
    assert decision_session.query(DecisionCase).count() == 1
    assert decision_session.query(DecisionRecommendation).count() == len(out["recommendations"])
    audits = decision_session.query(DecisionAudit).filter_by(case_id=out["case_id"]).all()
    assert len(audits) == 1 and audits[0].actor == "system"

    detail = rec.get_case(decision_session, out["case_id"])
    assert detail is not None
    assert detail["id"] == out["case_id"]
    assert len(detail["recommendations"]) == len(out["recommendations"])
    assert len(detail["audits"]) == 1

    row = rec.add_audit(decision_session, out["case_id"], "analyst-1",
                        "approved", "looks solid")
    decision_session.commit()
    assert row is not None and row["actor"] == "analyst-1"
    detail2 = rec.get_case(decision_session, out["case_id"])
    assert len(detail2["audits"]) == 2

    assert rec.get_case(decision_session, 999999) is None
    assert rec.add_audit(decision_session, 999999, "x", "y") is None
    with pytest.raises(ValueError):
        rec.add_audit(decision_session, out["case_id"], "", "approved")


def test_list_cases_newest_first(decision_session):
    from app.decision import recommendations as rec

    rec.recommend({}, sales_df=_rich_frame(), db_session=decision_session)
    rec.recommend({}, sales_df=pd.DataFrame(), db_session=decision_session)
    decision_session.commit()
    headers = rec.list_cases(decision_session)
    assert len(headers) == 2
    assert headers[0]["id"] > headers[1]["id"]


# ------------------------------------------------------------------
# API
# ------------------------------------------------------------------

def test_api_recommend_cases_audit_flow(client, service_headers, decision_warehouse):
    from app.database.connection import SessionLocal

    # recommend persists through the API (compute + persist = POST)
    r = client.post("/api/v1/decision/recommend",
                    json={"subject": {"branch": "JKT", "horizon": 7}},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert body["data"]["case_id"] is not None

    r2 = client.get("/api/v1/decision/cases", headers=service_headers)
    assert r2.status_code == 200
    assert len(r2.json()["data"]) >= 1

    case_id = body["data"]["case_id"]
    r3 = client.get(f"/api/v1/decision/cases/{case_id}", headers=service_headers)
    assert r3.status_code == 200
    assert r3.json()["data"]["id"] == case_id

    r4 = client.post(f"/api/v1/decision/cases/{case_id}/audit",
                     json={"actor": "analyst-1", "decision": "approved",
                           "rationale": "ok"},
                     headers=service_headers)
    assert r4.status_code == 201, r4.text

    r5 = client.get("/api/v1/decision/cases/999999", headers=service_headers)
    assert r5.status_code == 404

    SessionLocal.close_all() if hasattr(SessionLocal, "close_all") else None


def test_api_scenario_unsupported_is_explicit(client, service_headers, decision_warehouse):
    # empty warehouse -> inventory scenario cannot be supported; the API must
    # say so explicitly instead of returning zeros.
    r = client.post("/api/v1/decision/scenarios/run",
                    json={"type": "inventory_change_pct",
                          "params": {"inventory_change_pct": 10.0},
                          "subject": {}},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    data = r.json()["data"]
    assert data["supported"] is False
    assert data["reasons"] != []
    assert "deltas" not in data


def test_api_scenario_validation_is_422(client, service_headers, decision_warehouse):
    r = client.post("/api/v1/decision/scenarios/run",
                    json={"type": "nope", "params": {}, "subject": {}},
                    headers=service_headers)
    assert r.status_code == 422


def test_api_requires_service_key(client, decision_warehouse):
    assert client.post("/api/v1/decision/recommend",
                       json={"subject": {}}).status_code in (401, 403)
    assert client.get("/api/v1/decision/cases").status_code in (401, 403)
