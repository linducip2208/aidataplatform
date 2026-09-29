"""Enterprise quality: rule engine, profiles, history/trend, PII, API.

Every rule type in ``app.quality.rules.RULE_TYPES`` is pinned pass + fail,
plus its invalid-params rejection. API tests go through the real app with the
service key; GET endpoints assert the run count is unchanged (read-only).
"""
from __future__ import annotations

import pandas as pd
import pytest
from fastapi import APIRouter
from fastapi.testclient import TestClient

import app.main  # noqa: F401 (register every router-reachable mapper before warehouse create_all)
import app.quality.models  # noqa: F401 (register the quality mappers likewise)
from app.quality import history as _history
from app.quality import pii as _pii
from app.quality.profiles import PROFILES, get_profile, list_profiles, validate_profile
from app.quality.rules import (
    RULE_TYPES, ChunkAccumulator, RuleValidationError, evaluate, evaluate_in_chunks,
    validate_rule,
)


@pytest.fixture(scope="module")
def client(warehouse):
    """App with the quality router mounted exactly as master will wire it.

    ``router.py`` is master-owned, so the two include lines below live here
    until integration adds them to ``app/api/v1/router.py``::

        from app.api.v1 import quality  # alongside the other routers
        router.include_router(quality.router)
    """
    from app.api.v1.quality import router as quality_router
    from app.main import create_app

    v1 = APIRouter(prefix="/api/v1")
    v1.include_router(quality_router)
    app = create_app()
    app.include_router(v1)
    with TestClient(app) as test_client:
        yield test_client


def _frame(**columns) -> pd.DataFrame:
    return pd.DataFrame(dict(columns))


def _rule(rule_id: str, column, rtype: str, params=None, severity: str = "error") -> dict:
    return {"id": rule_id, "column": column, "type": rtype,
            "params": params or {}, "severity": severity}


def _by_id(outcome: dict, rule_id: str) -> dict:
    for result in outcome["results"]:
        if result["id"] == rule_id:
            return result
    raise AssertionError(f"no result for rule {rule_id!r}")


# --------------------------------------------------------------------------
# rule validation
# --------------------------------------------------------------------------

def test_every_documented_rule_type_validates():
    assert RULE_TYPES == frozenset({
        "required", "nullable", "unique", "duplicate", "regex", "range", "enum",
        "datatype", "referential", "freshness", "completeness", "consistency",
        "validity", "schema_drift", "anomaly_ref",
    })


def test_unknown_rule_type_is_rejected():
    with pytest.raises(RuleValidationError):
        validate_rule(_rule("r", "c", "nope"))


def test_bad_severity_is_rejected():
    with pytest.raises(RuleValidationError):
        validate_rule(_rule("r", "c", "required", severity="fatal"))


def test_columnless_row_rule_is_rejected():
    with pytest.raises(RuleValidationError):
        validate_rule(_rule("r", None, "required"))


def test_rule_without_id_is_rejected():
    with pytest.raises(RuleValidationError):
        validate_rule({"column": "c", "type": "required"})


@pytest.mark.parametrize("rtype,params", [
    ("regex", {}),
    ("regex", {"pattern": "(unclosed"}),
    ("range", {}),
    ("range", {"min": 5, "max": 1}),
    ("enum", {}),
    ("enum", {"allowed": []}),
    ("datatype", {}),
    ("datatype", {"dtype": "uuid"}),
    ("referential", {}),
    ("freshness", {}),
    ("freshness", {"max_age_days": -3}),
    ("validity", {}),
    ("validity", {"check": "spellcheck"}),
    ("schema_drift", {}),
    ("schema_drift", {"expected_columns": []}),
    ("duplicate", {"columns": []}),
    ("nullable", {"max_null_ratio": 1.5}),
    ("completeness", {"min_ratio": -0.1}),
    ("anomaly_ref", {"sensitivity": 9.0}),
])
def test_invalid_params_are_rejected(rtype, params):
    column = None if rtype == "schema_drift" else "c"
    with pytest.raises(RuleValidationError):
        validate_rule(_rule("r", column, rtype, params))


# --------------------------------------------------------------------------
# each rule type: pass + fail
# --------------------------------------------------------------------------

def test_required():
    df = _frame(name=["a", None, "  ", "b"])
    res = _by_id(evaluate(df, [_rule("r", "name", "required")]), "r")
    assert not res["passed"] and res["failure_count"] == 2
    assert len(res["sample_failures"]) <= 10
    ok = _by_id(evaluate(_frame(name=["a", "b"]), [_rule("r", "name", "required")]), "r")
    assert ok["passed"] and ok["failure_count"] == 0


def test_nullable_threshold():
    df = _frame(c=[1, None, None, 4])
    failed = _by_id(evaluate(df, [_rule("r", "c", "nullable", {"max_null_ratio": 0.25})]), "r")
    assert not failed["passed"] and failed["failure_count"] == 2
    passed = _by_id(evaluate(df, [_rule("r", "c", "nullable", {"max_null_ratio": 0.5})]), "r")
    assert passed["passed"]


def test_unique():
    df = _frame(email=["a@x.com", "a@x.com", "b@x.com"])
    res = _by_id(evaluate(df, [_rule("r", "email", "unique")]), "r")
    assert not res["passed"] and res["failure_count"] == 2  # both copies fail (keep=False)
    ok = _by_id(evaluate(_frame(email=["a@x.com", "b@x.com"]),
                         [_rule("r", "email", "unique")]), "r")
    assert ok["passed"]


def test_duplicate_rows_and_subset():
    df = _frame(a=[1, 1, 2], b=["x", "x", "y"])
    res = _by_id(evaluate(df, [_rule("r", None, "duplicate")]), "r")
    assert not res["passed"] and res["failure_count"] == 1
    sub = _by_id(evaluate(df, [_rule("r", None, "duplicate", {"columns": ["a"]})]), "r")
    assert not sub["passed"] and sub["failure_count"] == 1
    assert evaluate(_frame(a=[1, 2], b=["x", "y"]),
                    [_rule("r", None, "duplicate")])["verdict"] == "pass"


def test_regex():
    df = _frame(code=["AB-123", "nope", None])
    res = _by_id(evaluate(df, [_rule("r", "code", "regex",
                                     {"pattern": r"^[A-Z]{2}-\d{3}$"})]), "r")
    assert not res["passed"] and res["failure_count"] == 1  # nulls are skipped
    assert res["sample_failures"] == ["nope"]


def test_range():
    df = _frame(qty=[1, 5, 99, None, "N/A"])
    res = _by_id(evaluate(df, [_rule("r", "qty", "range", {"min": 1, "max": 10})]), "r")
    assert not res["passed"] and res["failure_count"] == 2  # 99 + unparseable
    ok = _by_id(evaluate(_frame(qty=[1, 10]), [_rule("r", "qty", "range",
                                                    {"min": 1, "max": 10})]), "r")
    assert ok["passed"]
    excl = _by_id(evaluate(_frame(qty=[1]), [_rule("r", "qty", "range",
                                                  {"min": 1, "include_min": False})]), "r")
    assert not excl["passed"]


def test_enum():
    df = _frame(seg=["new", "vip", "ghost"])
    res = _by_id(evaluate(df, [_rule("r", "seg", "enum", {"allowed": ["new", "vip"]})]), "r")
    assert not res["passed"] and res["sample_failures"] == ["ghost"]


def test_datatype():
    df = _frame(n=["10", "x", None], d=["2024-01-01", "not-a-date", None])
    ints = _by_id(evaluate(df, [_rule("r", "n", "datatype", {"dtype": "int"})]), "r")
    assert not ints["passed"] and ints["failure_count"] == 1
    dates = _by_id(evaluate(df, [_rule("r", "d", "datatype", {"dtype": "date"})]), "r")
    assert not dates["passed"] and dates["failure_count"] == 1
    floats = _by_id(evaluate(_frame(n=["1.5"]), [_rule("r", "n", "datatype",
                                                       {"dtype": "float"})]), "r")
    assert floats["passed"]
    strict = _by_id(evaluate(_frame(n=["1.5"]), [_rule("r", "n", "datatype",
                                                       {"dtype": "int"})]), "r")
    assert not strict["passed"]
    bools = _by_id(evaluate(_frame(b=["yes"]), [_rule("r", "b", "datatype",
                                                      {"dtype": "bool"})]), "r")
    assert bools["passed"]


def test_referential():
    df = _frame(branch=["Jakarta", "Atlantis", None])
    res = _by_id(evaluate(df, [_rule("r", "branch", "referential",
                                     {"allowed_values": ["Jakarta", "Bandung"]})]), "r")
    assert not res["passed"] and res["sample_failures"] == ["Atlantis"]


def test_freshness():
    df = _frame(day=["2026-08-20", "2026-01-01"])
    params = {"max_age_days": 30, "reference": "2026-09-01"}
    res = _by_id(evaluate(df, [_rule("r", "day", "freshness", params)]), "r")
    assert res["passed"] and res["failure_count"] == 1  # one stale row, max is fresh
    stale = _by_id(evaluate(_frame(day=["2026-01-01"]),
                            [_rule("r", "day", "freshness", params)]), "r")
    assert not stale["passed"]
    garbage = _by_id(evaluate(_frame(day=["yesterday-ish"]),
                              [_rule("r", "day", "freshness", params)]), "r")
    assert not garbage["passed"]


def test_completeness_column_and_dataset():
    df = _frame(a=[1, None, 3, None], b=[1, 2, 3, 4])
    col = _by_id(evaluate(df, [_rule("r", "a", "completeness", {"min_ratio": 0.9})]), "r")
    assert not col["passed"] and col["failure_count"] == 2
    ds = _by_id(evaluate(df, [_rule("r", None, "completeness", {"min_ratio": 0.9})]), "r")
    assert not ds["passed"] and ds["failure_count"] == 2
    lax = _by_id(evaluate(df, [_rule("r", None, "completeness", {"min_ratio": 0.5})]), "r")
    assert lax["passed"]


def test_consistency_outliers():
    df = _frame(price=[10, 11, 12, 13, 14, 15, 16, 17, 1000])
    res = _by_id(evaluate(df, [_rule("r", "price", "consistency",
                                     {"max_outlier_ratio": 0.05})]), "r")
    assert not res["passed"] and res["failure_count"] == 1
    lax = _by_id(evaluate(df, [_rule("r", "price", "consistency",
                                     {"max_outlier_ratio": 0.5})]), "r")
    assert lax["passed"]


def test_validity_checks():
    df = _frame(qty=[2, -1, "N/A", None], day=["2024-01-01", "bad", None, None],
                amt=["10.5", "NaN-ish", None, None])
    neg = _by_id(evaluate(df, [_rule("r", "qty", "validity", {"check": "no_negative"})]), "r")
    assert not neg["passed"] and neg["failure_count"] == 2
    dates = _by_id(evaluate(df, [_rule("r", "day", "validity", {"check": "parse_date"})]), "r")
    assert not dates["passed"] and dates["failure_count"] == 1
    nums = _by_id(evaluate(df, [_rule("r", "amt", "validity", {"check": "parse_number"})]), "r")
    assert not nums["passed"] and nums["failure_count"] == 1


def test_schema_drift():
    df = _frame(a=[1], b=[2])
    missing = _by_id(evaluate(df, [_rule("r", None, "schema_drift",
                                         {"expected_columns": ["a", "c"]})]), "r")
    assert not missing["passed"] and missing["failure_count"] == 2  # missing c + extra b
    allowed = _by_id(evaluate(df, [_rule("r", None, "schema_drift",
                                         {"expected_columns": ["a"],
                                          "allow_extra": True})]), "r")
    assert allowed["passed"]
    exact = _by_id(evaluate(df, [_rule("r", None, "schema_drift",
                                       {"expected_columns": ["a", "b"]})]), "r")
    assert exact["passed"]


def test_anomaly_ref():
    df = _frame(v=[10] * 9 + [100])
    res = _by_id(evaluate(df, [_rule("r", "v", "anomaly_ref",
                                     {"sensitivity": 2.5})]), "r")
    assert not res["passed"] and res["failure_count"] == 1
    lax = _by_id(evaluate(df, [_rule("r", "v", "anomaly_ref",
                                     {"sensitivity": 2.5,
                                      "max_anomaly_ratio": 0.2})]), "r")
    assert lax["passed"]
    flat = _by_id(evaluate(_frame(v=[5, 5, 5, 5]),
                           [_rule("r", "v", "anomaly_ref")]), "r")
    assert flat["passed"]  # zero variance: nothing to flag


def test_missing_column_fails_row_rules():
    df = _frame(a=[1, 2])
    res = _by_id(evaluate(df, [_rule("r", "ghost", "required")]), "r")
    assert not res["passed"] and res["failure_count"] == 2


def test_empty_dataset_fails_with_score_zero():
    out = evaluate(_frame(a=[]), [_rule("r", "a", "required")])
    assert out["score"] == 0.0 and out["verdict"] == "fail"


def test_verdict_and_scores():
    df = _frame(a=["x", None], b=[1, 1])
    out = evaluate(df, [
        _rule("e1", "a", "required", severity="error"),
        _rule("w1", "b", "unique", severity="warn"),
    ])
    assert out["verdict"] == "fail"
    assert out["results"][0]["passed"] is False
    assert set(out["column_scores"]) == {"a", "b"}
    assert all(0.0 <= v <= 1.0 for v in out["column_scores"].values())
    warn_only = evaluate(df, [_rule("w1", "b", "unique", severity="warn")])
    assert warn_only["verdict"] == "warn"
    clean = evaluate(_frame(a=["x", "y"], b=[1, 2]), [
        _rule("e1", "a", "required"), _rule("w1", "b", "unique", severity="warn")])
    assert clean["verdict"] == "pass" and clean["score"] == 1.0


def test_samples_capped_at_ten():
    df = _frame(a=[None] * 25)
    res = _by_id(evaluate(df, [_rule("r", "a", "required")]), "r")
    assert res["failure_count"] == 25 and len(res["sample_failures"]) == 10


def test_evaluate_is_deterministic():
    df = _frame(a=[1, None, 3], b=["x", "y", "y"])
    rules = [_rule("r1", "a", "required"), _rule("r2", "b", "unique", severity="warn")]
    assert evaluate(df, rules) == evaluate(df, rules)


# --------------------------------------------------------------------------
# chunks
# --------------------------------------------------------------------------

def test_chunked_evaluation_matches_single_pass():
    df = pd.DataFrame({
        "email": [f"u{i}@x.com" for i in range(30)] + ["u1@x.com"],
        "qty": list(range(30)) + [-5],
        "price": [10, 11, 12, 13, 14, 15, 16, 17] * 3 + [10, 11, 12, 1000, 13, 14, 15],
    })
    rules = [
        _rule("u", "email", "unique"),
        _rule("q", "qty", "range", {"min": 0}),
        _rule("c", "price", "consistency", {"max_outlier_ratio": 0.5}),
        _rule("d", None, "duplicate", severity="warn"),
    ]
    single = evaluate(df, rules)
    acc = ChunkAccumulator(rules)
    for start in range(0, len(df), 7):
        acc.add_chunk(df.iloc[start:start + 7])
    chunked = acc.finalize()
    assert chunked["score"] == single["score"]
    assert chunked["verdict"] == single["verdict"]
    for left, right in zip(chunked["results"], single["results"]):
        assert left["failure_count"] == right["failure_count"]
        assert left["passed"] == right["passed"]
    assert evaluate_in_chunks(df, rules, chunksize=7)["score"] == single["score"]


# --------------------------------------------------------------------------
# profiles
# --------------------------------------------------------------------------

def test_builtin_profiles_validate():
    assert set(PROFILES) >= {"sales_strict", "inventory_standard", "customers_pii_aware"}
    for profile in list_profiles():
        assert validate_profile(profile)["rules"]


def test_get_profile_unknown_raises():
    with pytest.raises(KeyError):
        get_profile("nope")


def test_profile_duplicate_ids_rejected():
    profile = {"name": "dup", "rules": [
        _rule("same", "a", "required"), _rule("same", "b", "required")]}
    with pytest.raises(ValueError, match="duplicate rule id"):
        validate_profile(profile)


def test_profile_empty_rules_rejected():
    with pytest.raises(ValueError, match="non-empty"):
        validate_profile({"name": "empty", "rules": []})


def test_sales_strict_catches_bad_sales_frame():
    profile = get_profile("sales_strict")
    df = _frame(customer=["Budi", None], product=["Laptop", "Mouse"],
                quantity=[2, -1], price=["5000000", "NaN-ish"],
                date=["2026-08-20", "2026-08-21"], branch=["Jakarta", "Atlantis"])
    out = evaluate(df, validate_profile(profile)["rules"])
    failed = {r["id"] for r in out["results"] if not r["passed"]}
    assert {"customer_required", "quantity_valid", "price_valid",
            "branch_known"} <= failed
    assert out["verdict"] == "fail"


# --------------------------------------------------------------------------
# history
# --------------------------------------------------------------------------

def test_history_save_and_trend(db_session):
    run1 = _history.save_run(db_session, dataset_ref="ds-1", job_id=3,
                             profile="sales_strict",
                             scores={"score": 0.5, "column_scores": {}}, verdict="fail")
    _history.save_findings(db_session, run1.id, [
        {"id": "r1", "column": "a", "passed": False,
         "failure_count": 2, "sample_failures": [1, 2]},
        {"id": "r2", "column": "b", "passed": True,
         "failure_count": 0, "sample_failures": []},
    ])
    run2 = _history.save_run(db_session, dataset_ref="ds-1", job_id=3,
                             profile="sales_strict",
                             scores={"score": 0.9, "column_scores": {}}, verdict="pass")
    _history.save_findings(db_session, run2.id, [])
    db_session.commit()

    runs = _history.get_history(db_session, dataset_ref="ds-1")
    assert [r["id"] for r in runs] == [run2.id, run1.id]  # newest first

    detail = _history.get_run_detail(db_session, run1.id)
    assert detail is not None and len(detail["findings"]) == 1  # only failures stored
    assert detail["findings"][0]["rule_id"] == "r1"
    assert _history.get_run_detail(db_session, 999999) is None

    trend = _history.get_trend(db_session, "ds-1")
    assert trend["direction"] == "improving" and trend["delta"] == 0.4
    assert [p["score"] for p in trend["scores"]] == [0.5, 0.9]

    flat = _history.get_trend(db_session, "missing")
    assert flat == {"dataset_ref": "missing", "run_count": 0,
                    "scores": [], "direction": "stable", "delta": 0.0}


def test_trend_degrading(db_session):
    for score in (0.9, 0.4):
        _history.save_run(db_session, dataset_ref="ds-2", job_id=None, profile=None,
                          scores={"score": score}, verdict="fail")
    db_session.commit()
    trend = _history.get_trend(db_session, "ds-2")
    assert trend["direction"] == "degrading" and trend["delta"] == -0.5


# --------------------------------------------------------------------------
# PII
# --------------------------------------------------------------------------

def test_pii_detect_each_kind():
    df = _frame(
        email=["budi@example.com", "not-an-email"],
        phone=["081234567890", "hello"],
        card=["4532015112830366", "123"],
        nik=["3174052501900001", "short"],
        plain=["lorem", "ipsum"],
    )
    findings = _pii.detect(df)
    by_col = {(f["column"], f["kind"]): f for f in findings}
    assert by_col[("email", "email")]["count"] == 1
    assert by_col[("phone", "phone")]["count"] == 1
    assert by_col[("card", "credit_card")]["count"] == 1
    assert by_col[("nik", "national_id")]["count"] == 1
    assert not [f for f in findings if f["column"] == "plain"]
    assert all(len(f["samples"]) <= 5 for f in findings)


def test_pii_repeated_digits_are_not_cards():
    df = _frame(card=["0000000000000000"])
    assert _pii.detect(df) == []


def test_pii_extra_patterns_and_unknown_kind():
    df = _frame(code=["EMP-001", "other"])
    findings = _pii.detect(df, extra_patterns={"employee_id": r"EMP-\d{3}"})
    assert findings == [{"column": "code", "kind": "employee_id",
                         "count": 1, "samples": ["EMP-001"]}]
    with pytest.raises(ValueError, match="unknown PII kind"):
        _pii.detect(df, kinds=["nope"])
    with pytest.raises(ValueError, match="bad regex"):
        _pii.detect(df, extra_patterns={"bad": "(unclosed"})


def test_pii_masking_strategies():
    assert _pii.mask_email("budi.santoso@example.com") == "b***@example.com"
    assert _pii.mask_partial("081234567890") == "********7890"
    assert _pii.mask_partial("ab") == "**"
    assert _pii.mask_full("secret") == "******"
    assert _pii.mask_email(None) is None and _pii.mask_full(None) is None
    with pytest.raises(ValueError, match="unknown masking strategy"):
        _pii.mask_dataframe(_frame(a=["x"]), strategy="rot13")


def test_pii_mask_dataframe_masks_only_findings_and_keeps_input():
    df = _frame(email=["budi@example.com"], city=["Jakarta"])
    masked, findings = _pii.mask_dataframe(df)
    assert masked["email"].iloc[0] == "b***@example.com"
    assert masked["city"].iloc[0] == "Jakarta"  # untouched
    assert df["email"].iloc[0] == "budi@example.com"  # input never mutated
    assert {(f["column"], f["kind"]) for f in findings} == {("email", "email")}
    full, _ = _pii.mask_dataframe(df, strategy="full")
    assert full["email"].iloc[0] == "*" * len("budi@example.com")


# --------------------------------------------------------------------------
# API
# --------------------------------------------------------------------------

def _run_count(client, headers) -> int:
    resp = client.get("/api/v1/quality/history", headers=headers)
    assert resp.status_code == 200
    return len(resp.json()["data"]["runs"])


def test_api_requires_service_key(client):
    assert client.get("/api/v1/quality/rules").status_code == 401
    assert client.post("/api/v1/quality/evaluate", json={"rows": []}).status_code == 401


def test_api_rule_crud(client, service_headers, clean_warehouse):
    created = client.post("/api/v1/quality/rules", headers=service_headers, json={
        "name": "email_required", "dataset_type": "customers", "column": "email",
        "rule_type": "required", "params": {}, "severity": "error", "active": True,
    })
    assert created.status_code == 201
    body = created.json()["data"]
    assert body["name"] == "email_required" and body["rule_type"] == "required"

    dup = client.post("/api/v1/quality/rules", headers=service_headers, json={
        "name": "email_required", "column": "email", "rule_type": "required"})
    assert dup.status_code == 409

    bad = client.post("/api/v1/quality/rules", headers=service_headers, json={
        "name": "bad", "column": "c", "rule_type": "nope"})
    assert bad.status_code == 422

    before = _run_count(client, service_headers)
    listed = client.get("/api/v1/quality/rules", headers=service_headers)
    assert listed.status_code == 200 and listed.json()["success"] is True
    names = [r["name"] for r in listed.json()["data"]]
    assert "email_required" in names
    filtered = client.get("/api/v1/quality/rules",
                          params={"dataset_type": "customers"}, headers=service_headers)
    assert all(r["dataset_type"] == "customers" for r in filtered.json()["data"])
    assert _run_count(client, service_headers) == before  # GET wrote nothing


def test_api_evaluate_with_rows_and_profile(client, service_headers, clean_warehouse):
    rows = [
        {"customer": "Budi", "product": "Laptop", "quantity": 2,
         "price": "5000000", "date": "2026-08-20", "branch": "Jakarta"},
        {"customer": None, "product": "Mouse", "quantity": -1,
         "price": "NaN-ish", "date": "2026-08-21", "branch": "Atlantis"},
    ]
    resp = client.post("/api/v1/quality/evaluate", headers=service_headers, json={
        "dataset_ref": "sales-2026-09", "profile": "sales_strict", "rows": rows})
    assert resp.status_code == 200
    data = resp.json()["data"]
    assert data["verdict"] == "fail" and data["run_id"] > 0
    assert 0.0 <= data["score"] <= 1.0
    assert {r["id"] for r in data["results"] if not r["passed"]} >= {
        "customer_required", "quantity_valid", "price_valid"}


def test_api_evaluate_with_ad_hoc_rules(client, service_headers, clean_warehouse):
    resp = client.post("/api/v1/quality/evaluate", headers=service_headers, json={
        "dataset_ref": "adhoc", "rows": [{"a": "x"}, {"a": None}],
        "rules": [{"id": "a_req", "column": "a", "type": "required",
                   "params": {}, "severity": "error"}]})
    assert resp.status_code == 200
    assert resp.json()["data"]["verdict"] == "fail"


def test_api_evaluate_rejects_bad_input(client, service_headers, clean_warehouse):
    assert client.post("/api/v1/quality/evaluate", headers=service_headers,
                       json={"dataset_ref": "x"}).status_code == 422
    assert client.post("/api/v1/quality/evaluate", headers=service_headers,
                       json={"rows": []}).status_code == 422
    assert client.post("/api/v1/quality/evaluate", headers=service_headers, json={
        "rows": [{"a": 1}], "profile": "ghost"}).status_code == 422
    assert client.post("/api/v1/quality/evaluate", headers=service_headers, json={
        "rows": [{"a": 1}],
        "rules": [{"id": "bad", "column": "a", "type": "nope"}]}).status_code == 422
    assert client.post("/api/v1/quality/evaluate", headers=service_headers,
                       json={"job_id": 424242}).status_code == 404


def test_api_history_and_run_detail_are_read_only(client, service_headers, clean_warehouse):
    posted = client.post("/api/v1/quality/evaluate", headers=service_headers, json={
        "dataset_ref": "hist-ds", "rows": [{"a": "x"}],
        "rules": [{"id": "a_req", "column": "a", "type": "required",
                   "params": {}, "severity": "error"}]})
    run_id = posted.json()["data"]["run_id"]
    before = _run_count(client, service_headers)

    hist = client.get("/api/v1/quality/history",
                      params={"dataset_ref": "hist-ds"}, headers=service_headers)
    assert hist.status_code == 200
    assert hist.json()["data"]["trend"]["direction"] == "stable"
    assert hist.json()["data"]["runs"][0]["id"] == run_id

    detail = client.get(f"/api/v1/quality/runs/{run_id}", headers=service_headers)
    assert detail.status_code == 200
    assert detail.json()["data"]["id"] == run_id
    assert detail.json()["data"]["findings"] == []  # passed rules store nothing

    assert client.get("/api/v1/quality/runs/424242", headers=service_headers).status_code == 404
    assert _run_count(client, service_headers) == before  # GETs wrote nothing
