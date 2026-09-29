"""Enterprise ML: experiments, splits, registry audit trail, batch prediction.

Covers the A5 enterprise surface end to end:

* split helpers — completeness, determinism, chronological order for series,
  class proportions for classification;
* experiments — create/run with per-split metrics, manual metric records,
  comparison ranking (measured scores outrank missing ones), promotion;
* registry — promote/rollback cycle, event trail, deployment transitions,
  complete model metadata;
* batch prediction — chunking, persisted ``prediction_runs`` rows, artifacts;
* HTTP — every new route plus byte-identical behaviour of the touched ones.

All trainers are the real modules (seasonal-naive+GBM, LogReg+RF churn,
KMeans, zscore/IQR+IsolationForest+LOF) with deterministic seeds; no metric is
invented — tiny or degenerate inputs surface explicit reasons instead.
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest

# Importing the modules registers ml_experiments / ml_model_events on the
# shared Base before the session-scoped warehouse fixture creates the schema.
import app.ml.batch as batch_mod  # noqa: F401
import app.ml.experiments as exp  # noqa: F401
from app.ml import registry as reg


# --------------------------------------------------------------------------
# fixtures + builders
# --------------------------------------------------------------------------

@pytest.fixture()
def clean_artifacts():
    """Remove joblib/JSON artifacts this suite writes under MODEL_PATH."""
    from app.core.config import settings

    before = set(p.name for p in settings.model_path.glob("*")) \
        if settings.model_path.exists() else set()
    yield
    for p in settings.model_path.glob("*"):
        if p.name not in before and (
                p.name.startswith("model_") or p.name.startswith("batch_")):
            try:
                p.unlink()
            except OSError:
                pass


def churn_rows(n: int = 20, flip_every: int = 0) -> list:
    """Two-class cohort with separable features; ``flip_every`` injects label
    noise so a second experiment scores strictly worse."""
    rows = []
    for i in range(n):
        churned = i % 2
        if flip_every and i % flip_every == 0:
            churned = 1 - churned
        rows.append({
            "customer_name": f"c{i}",
            "total_orders": 1 + (i % 5) + churned * 8,
            "total_spending": 100.0 + i * 10.0 + churned * 900.0,
            "recency": 120 - i * 3 if churned else 5 + i,
            "frequency": 1 if churned else 4 + (i % 4),
            "monetary": 100.0 + i * 10.0,
            "aov": 50.0 + i,
            "tenure": 30 + i * 7,
            "churn": churned,
        })
    return rows


def forecast_rows(n: int = 30) -> list:
    from datetime import date, timedelta

    base = date(2024, 1, 1)
    return [{"date": (base + timedelta(days=i)).isoformat(),
             "y": 100.0 + (i % 7) * 10.0 + i}
            for i in range(n)]


def segment_rows(n: int = 12) -> list:
    return [{"customer_name": f"c{i}",
             "total_orders": 1 + i, "total_spending": 100.0 * (1 + i),
             "recency": 90 - i * 5, "frequency": 1 + (i % 4),
             "monetary": 100.0 * (1 + i), "aov": 50.0 + i,
             "tenure": 20 + i * 9}
            for i in range(n)]


def anomaly_series(n: int = 20) -> list:
    rows = [{"value": 10.0 + (i % 3)} for i in range(n)]
    if n > 10:
        rows[10] = {"value": 500.0}
    elif n:
        rows[-1] = {"value": 500.0}
    return rows


# --------------------------------------------------------------------------
# splits
# --------------------------------------------------------------------------

def test_time_aware_split_is_chronological_and_complete():
    rows = forecast_rows(30)
    shuffled = list(reversed(rows))
    split = exp.split_dataset(shuffled, "forecast", 0.7, 0.15, 0.15, seed=42)
    assert split["strategy"] == "time_aware"
    total = sum(split["sizes"].values())
    assert total == 30
    assert len(split["train"]) + len(split["validate"]) + len(split["test"]) == 30
    assert max(r["date"] for r in split["train"]) <= \
        min(r["date"] for r in split["validate"])
    assert max(r["date"] for r in split["validate"]) <= \
        min(r["date"] for r in split["test"])


def test_split_is_deterministic_for_the_same_seed():
    rows = churn_rows(20)
    first = exp.split_dataset(rows, "churn", seed=7)
    second = exp.split_dataset(rows, "churn", seed=7)
    assert first["train"] == second["train"]
    assert first["validate"] == second["validate"]
    assert first["test"] == second["test"]
    third = exp.split_dataset(rows, "churn", seed=8)
    assert third["train"] != first["train"]


def test_stratified_split_keeps_both_classes_in_train():
    rows = churn_rows(20)
    split = exp.split_dataset(rows, "churn", seed=42)
    assert split["strategy"] == "stratified"
    labels = {r["churn"] for r in split["train"]}
    assert labels == {0, 1}
    assert sum(split["sizes"].values()) == 20


def test_random_split_sizes_sum_to_the_input():
    rows = segment_rows(12)
    split = exp.split_dataset(rows, "segmentation", seed=42)
    assert split["strategy"] == "random"
    assert sum(split["sizes"].values()) == 12


def test_tiny_dataset_stays_in_train_with_an_explicit_strategy():
    split = exp.split_dataset([{"y": 1}], "forecast")
    assert split["strategy"] == "too_small_to_split"
    assert len(split["train"]) == 1
    assert split["validate"] == [] and split["test"] == []


def test_unknown_model_type_and_bad_ratios_are_explicit():
    with pytest.raises(ValueError):
        exp.split_dataset(churn_rows(6), "prophet")
    with pytest.raises(ValueError):
        exp.split_dataset(churn_rows(6), "churn", train_ratio=0)


# --------------------------------------------------------------------------
# experiments
# --------------------------------------------------------------------------

def test_create_experiment_runs_and_records_per_split_metrics(db_session, clean_artifacts):
    res = exp.create_experiment(
        "churn", churn_rows(20), name="exp-churn-a",
        dataset_ref="warehouse:fact_sales", dataset_version="v2024-01",
        feature_list=["total_orders", "recency"], params={},
        db_session=db_session)
    assert res["status"] == "DONE"
    assert res["model_id"] and res["version_id"]
    assert res["dataset_ref"] == "warehouse:fact_sales"
    assert res["dataset_version"] == "v2024-01"
    assert set(res["metrics"]) == {"train", "validate", "test"}
    # Per-split metrics describe the train split, not the full dataset.
    assert res["metrics"]["train"]["n_rows"] == res["split_config"]["sizes"]["train"]
    assert res["split_config"]["strategy"] == "stratified"
    assert sum(res["split_config"]["sizes"].values()) == 20


def test_create_experiment_without_rows_is_planned_not_zero_filled(db_session):
    res = exp.create_experiment("forecast", [], name="exp-empty",
                                run_training=True, db_session=db_session)
    assert res["status"] == "PLANNED"
    assert res["metrics"]["reason"] == "no dataset rows supplied"
    assert res["model_id"] is None


def test_forecast_experiment_records_holdout_mae(db_session, clean_artifacts):
    res = exp.create_experiment("forecast", forecast_rows(30),
                                name="exp-forecast-a", db_session=db_session)
    assert res["status"] == "DONE"
    assert res["split_config"]["strategy"] == "time_aware"
    assert isinstance(res["metrics"]["validate"].get("mae"), float)
    assert isinstance(res["metrics"]["test"].get("mae"), float)


def test_compare_ranks_measured_scores_above_missing_ones(db_session):
    first = exp.create_experiment("churn", [], name="exp-cmp-1",
                                  run_training=False, db_session=db_session)
    second = exp.create_experiment("churn", [], name="exp-cmp-2",
                                   run_training=False, db_session=db_session)
    exp.record_metrics(first["id"],
                       {"validate": {"f1": 0.9}, "train": {}, "test": {}},
                       db_session)
    # second keeps no f1: it must rank last, explicitly, not as a zero.
    res = exp.compare_experiments([first["id"], second["id"]], db_session=db_session)
    assert res["metric"] == "f1" and res["split"] == "validate"
    assert res["higher_is_better"] is True
    assert [e["experiment_id"] for e in res["ranking"]] == [first["id"], second["id"]]
    assert res["ranking"][0]["rank"] == 1
    assert res["ranking"][1]["value"] is None
    assert "no numeric" in res["ranking"][1]["reason"]
    assert res["best_experiment_id"] == first["id"]


def test_compare_rejects_mixed_model_types(db_session):
    first = exp.create_experiment("churn", [], name="exp-mix-1",
                                  run_training=False, db_session=db_session)
    second = exp.create_experiment("forecast", [], name="exp-mix-2",
                                   run_training=False, db_session=db_session)
    with pytest.raises(ValueError):
        exp.compare_experiments([first["id"], second["id"]], db_session=db_session)


def test_compare_supports_lower_is_better_override(db_session):
    first = exp.create_experiment("forecast", [], name="exp-mae-1",
                                  run_training=False, db_session=db_session)
    second = exp.create_experiment("forecast", [], name="exp-mae-2",
                                   run_training=False, db_session=db_session)
    exp.record_metrics(first["id"], {"validate": {"mae": 12.5}}, db_session)
    exp.record_metrics(second["id"], {"validate": {"mae": 3.25}}, db_session)
    res = exp.compare_experiments([first["id"], second["id"]], metric="mae",
                                  split="validate", higher_is_better=False,
                                  db_session=db_session)
    assert [e["experiment_id"] for e in res["ranking"]] == [second["id"], first["id"]]
    assert res["best_experiment_id"] == second["id"]


def test_promote_experiment_moves_the_linked_version_to_production(
        db_session, clean_artifacts):
    res = exp.create_experiment("anomaly", anomaly_series(20),
                                name="exp-promote-a", db_session=db_session)
    assert res["status"] == "DONE"
    out = exp.promote_experiment(res["id"], db_session=db_session)
    assert out["status"] == "PRODUCTION"
    assert out["version_id"] == res["version_id"]


def test_promote_experiment_refuses_an_untrained_experiment(db_session):
    res = exp.create_experiment("churn", [], name="exp-promote-b",
                                run_training=False, db_session=db_session)
    with pytest.raises(ValueError):
        exp.promote_experiment(res["id"], db_session=db_session)


# --------------------------------------------------------------------------
# registry: promote / rollback / events / metadata
# --------------------------------------------------------------------------

def _two_version_model(db_session, name="rb-model"):
    from app.ml.training import train_model

    first = train_model("anomaly", name, {}, anomaly_series(20), db_session)
    second = train_model("anomaly", name, {}, anomaly_series(20), db_session)
    return first, second


def test_promote_rollback_cycle_restores_the_predecessor(db_session, clean_artifacts):
    first, second = _two_version_model(db_session)
    model_id = first["model_id"]
    reg.promote(model_id, first["version_id"], "PRODUCTION", db_session)
    reg.promote(model_id, second["version_id"], "PRODUCTION", db_session)

    from app.database.models import MLModel, ModelVersion

    assert db_session.query(ModelVersion).filter_by(
        id=first["version_id"]).first().status == "ARCHIVED"
    out = reg.rollback(model_id, db_session, actor="tester", note="bad deploy")
    assert out == {"model_id": model_id,
                   "rolled_back_from": second["version_id"],
                   "rolled_back_to": first["version_id"],
                   "status": "PRODUCTION"}
    model = db_session.query(MLModel).filter_by(id=model_id).first()
    assert model.production_version_id == first["version_id"]
    assert db_session.query(ModelVersion).filter_by(
        id=second["version_id"]).first().status == "ARCHIVED"


def test_rollback_without_a_predecessor_is_explicit(db_session, clean_artifacts):
    from app.ml.training import train_model

    res = train_model("anomaly", "rb-lonely", {}, anomaly_series(20), db_session)
    reg.promote(res["model_id"], res["version_id"], "PRODUCTION", db_session)
    with pytest.raises(ValueError, match="no archived predecessor"):
        reg.rollback(res["model_id"], db_session)


def test_event_trail_records_promote_and_rollback_in_order(
        db_session, clean_artifacts):
    first, second = _two_version_model(db_session, name="ev-model")
    model_id = first["model_id"]
    reg.promote(model_id, first["version_id"], "PRODUCTION", db_session)
    reg.promote(model_id, second["version_id"], "PRODUCTION", db_session)
    reg.rollback(model_id, db_session)

    trail = reg.list_events(model_id, db_session)
    assert trail["model_id"] == model_id
    kinds = [e["event_type"] for e in trail["events"]]
    assert "lifecycle" in kinds and "deployment" in kinds and "rollback" in kinds
    lifecycle_targets = [e["to_status"] for e in trail["events"]
                         if e["event_type"] == "lifecycle"]
    assert lifecycle_targets.count("PRODUCTION") >= 2
    assert lifecycle_targets.count("ARCHIVED") >= 2
    # deployment axis follows the serving pointer, not the lifecycle label.
    serving = [e for e in trail["events"]
               if e["event_type"] == "deployment" and e["to_status"] == "SERVING"]
    assert len(serving) >= 2
    assert set(trail["deployment_status"]) == {
        str(first["version_id"]), str(second["version_id"])}


def test_deployment_transitions_reject_skips(db_session, clean_artifacts):
    first, _ = _two_version_model(db_session, name="dep-model")
    # Fresh versions start PENDING; PENDING -> SERVING skips STAGING.
    with pytest.raises(ValueError, match="cannot move from PENDING"):
        reg.set_deployment_status(first["model_id"], first["version_id"],
                                  "SERVING", db_session=db_session)
    out = reg.set_deployment_status(first["model_id"], first["version_id"],
                                    "STAGING", db_session=db_session)
    assert out["deployment_status"] == "STAGING"
    assert reg.deployment_status(first["model_id"], first["version_id"],
                                 db_session) == "STAGING"


def test_model_detail_exposes_complete_metadata(db_session, clean_artifacts):
    from app.ml.training import train_model

    res = train_model("churn", "detail-model", {"sensitivity": 2.5},
                      churn_rows(20), db_session)
    detail = reg.model_detail(res["model_id"], db_session)
    assert detail["production_version_id"] is None
    assert len(detail["versions"]) == 1
    version = detail["versions"][0]
    for key in ("version", "training_timestamp", "dataset_version", "features",
                "metrics", "params", "artifact_path", "status",
                "deployment_status"):
        assert key in version, f"missing metadata key: {key}"
    assert version["training_timestamp"] is not None
    assert version["metrics"].get("accuracy") is not None
    # training timestamp is a real ISO timestamp, not a placeholder.
    assert "T" in version["training_timestamp"]
    assert Path(version["artifact_path"]).is_file()


def test_model_detail_surfaces_recorded_provenance(db_session, clean_artifacts):
    model = reg.create_model("prov-model", "churn", db_session)
    ver = reg.create_version(
        model["id"], {"accuracy": 0.8}, {"model_type": "churn"},
        dataset_version="v2024-02", features=["a", "b"],
        params={"seed": 42}, db_session=db_session)
    detail = reg.model_detail(model["id"], db_session)
    version = next(v for v in detail["versions"] if v["id"] == ver["id"])
    assert version["dataset_version"] == "v2024-02"
    assert version["features"] == ["a", "b"]
    assert version["params"] == {"seed": 42}
    # reserved provenance keys never leak into the compared metrics.
    assert "_dataset_version" not in version["metrics"]


# --------------------------------------------------------------------------
# batch prediction
# --------------------------------------------------------------------------

def test_batch_churn_chunks_persists_and_writes_an_artifact(
        db_session, clean_artifacts):
    from app.ml.training import train_model

    trained = train_model("churn", "churn-model", {}, churn_rows(20), db_session)
    reg.promote(trained["model_id"], trained["version_id"], "PRODUCTION",
                db_session)
    rows = churn_rows(7)
    res = batch_mod.run_batch_predict("churn", rows, chunk_size=3,
                                      db_session=db_session)
    assert res["n_rows"] == 7
    assert res["n_chunks"] == 3
    assert res["summary"]["n_scored"] == 7
    assert res["summary"]["n_errors"] == 0
    assert Path(res["artifact_path"]).is_file()
    payload = json.loads(Path(res["artifact_path"]).read_text(encoding="utf-8"))
    assert len(payload["predictions"]) == 7
    assert payload["summary"]["n_rows"] == 7

    from app.database.models import PredictionRun

    run = db_session.query(PredictionRun).filter_by(id=res["run_id"]).first()
    assert run is not None
    assert run.input_summary["n_rows"] == 7
    assert run.output_summary["n_scored"] == 7


def test_batch_anomaly_scores_per_chunk_with_an_explicit_note(
        db_session, clean_artifacts):
    res = batch_mod.run_batch_predict("anomaly", anomaly_series(10),
                                      chunk_size=4, db_session=db_session)
    assert res["n_chunks"] == 3
    assert res["summary"]["chunked"] is True
    assert "per chunk" in res["summary"]["note"]
    assert res["summary"]["n_rows"] == 10


def test_batch_csv_text_parses_and_scores(db_session, clean_artifacts):
    from app.ml.training import train_model

    trained = train_model("churn", "churn-model", {}, churn_rows(20), db_session)
    reg.promote(trained["model_id"], trained["version_id"], "PRODUCTION",
                db_session)
    csv_text = ("total_orders,total_spending,recency,frequency,monetary,aov,"
                "tenure,churn\n" + "\n".join(
                    "3,500,10,4,500,120,60,0" for _ in range(5)))
    res = batch_mod.run_batch_predict("churn", csv_text=csv_text,
                                      db_session=db_session)
    assert res["n_rows"] == 5
    assert res["summary"]["n_scored"] == 5


def test_batch_without_a_production_model_is_explicit(db_session):
    with pytest.raises(ValueError, match="no production model"):
        batch_mod.run_batch_predict(
            "churn", churn_rows(4), model_name="never-trained-xyz",
            db_session=db_session)


def test_batch_rejects_empty_input(db_session):
    with pytest.raises(ValueError, match="needs dataset rows or csv_text"):
        batch_mod.run_batch_predict("churn", [], db_session=db_session)


# --------------------------------------------------------------------------
# HTTP surface
# --------------------------------------------------------------------------

def _train_churn_production(client, headers, name="churn-model"):
    body = {"model_type": "churn", "name": name, "params": {},
            "dataset": churn_rows(20)}
    r = client.post("/api/v1/training/train", json=body, headers=headers)
    assert r.status_code == 200, r.text
    data = r.json()["data"]
    promo = client.post(f"/api/v1/models/{data['model_id']}/promote",
                        json={"version_id": data["version_id"],
                              "to_status": "PRODUCTION"},
                        headers=headers)
    assert promo.status_code == 200, promo.text
    return data


def test_existing_train_and_models_routes_are_unchanged(
        client, service_headers, clean_warehouse, clean_artifacts):
    body = {"model_type": "churn", "name": "compat-model", "params": {},
            "dataset": churn_rows(20)}
    r = client.post("/api/v1/training/train", json=body, headers=service_headers)
    assert r.status_code == 200
    data = r.json()["data"]
    assert set(data) == {"model_id", "version_id", "version", "metrics", "status"}

    listed = client.get("/api/v1/models", headers=service_headers)
    assert listed.status_code == 200
    assert any(m["name"] == "compat-model" for m in listed.json()["data"])

    promo = client.post(f"/api/v1/models/{data['model_id']}/promote",
                        json={"version_id": data["version_id"],
                              "to_status": "PRODUCTION"},
                        headers=service_headers)
    assert promo.status_code == 200
    assert promo.json()["data"]["status"] == "PRODUCTION"


def test_experiment_endpoints(client, service_headers, clean_warehouse,
                              clean_artifacts):
    created = client.post("/api/v1/training/experiments",
                          json={"model_type": "churn", "name": "http-exp",
                                "dataset": churn_rows(20),
                                "dataset_ref": "warehouse:fact_sales",
                                "dataset_version": "v1"},
                          headers=service_headers)
    assert created.status_code == 200, created.text
    experiment = created.json()["data"]
    assert experiment["status"] == "DONE"
    assert set(experiment["metrics"]) == {"train", "validate", "test"}

    listed = client.get("/api/v1/training/experiments",
                        headers=service_headers)
    assert listed.status_code == 200
    assert any(e["id"] == experiment["id"] for e in listed.json()["data"])

    single = client.get(f"/api/v1/training/experiments/{experiment['id']}",
                        headers=service_headers)
    assert single.status_code == 200
    assert single.json()["data"]["id"] == experiment["id"]

    missing = client.get("/api/v1/training/experiments/999999",
                         headers=service_headers)
    assert missing.status_code == 404

    compared = client.post(
        f"/api/v1/training/experiments/{experiment['id']}/compare",
        json={"experiment_ids": [experiment["id"]]},
        headers=service_headers)
    assert compared.status_code == 200, compared.text
    assert compared.json()["data"]["best_experiment_id"] == experiment["id"]

    promoted = client.post(
        f"/api/v1/training/experiments/{experiment['id']}/promote",
        json={}, headers=service_headers)
    assert promoted.status_code == 200, promoted.text
    assert promoted.json()["data"]["status"] == "PRODUCTION"


def test_experiment_create_rejects_unknown_type(client, service_headers,
                                                clean_warehouse):
    r = client.post("/api/v1/training/experiments",
                    json={"model_type": "prophet", "dataset": []},
                    headers=service_headers)
    assert r.status_code == 422


def test_batch_predict_endpoint(client, service_headers, clean_warehouse,
                                clean_artifacts):
    _train_churn_production(client, service_headers)
    r = client.post("/api/v1/training/batch-predict",
                    json={"model_type": "churn",
                          "dataset": churn_rows(7), "chunk_size": 3},
                    headers=service_headers)
    assert r.status_code == 200, r.text
    data = r.json()["data"]
    assert data["n_rows"] == 7 and data["n_chunks"] == 3
    assert Path(data["artifact_path"]).is_file()


def test_batch_predict_without_production_is_404(client, service_headers,
                                                 clean_warehouse):
    r = client.post("/api/v1/training/batch-predict",
                    json={"model_type": "churn",
                          "model_name": "missing-http-model",
                          "dataset": churn_rows(4)},
                    headers=service_headers)
    assert r.status_code == 404


def test_rollback_and_events_endpoints(client, service_headers, clean_warehouse,
                                       clean_artifacts):
    body = {"model_type": "anomaly", "name": "http-rb", "params": {},
            "dataset": anomaly_series(20)}
    first = client.post("/api/v1/training/train", json=body,
                        headers=service_headers).json()["data"]
    second = client.post("/api/v1/training/train", json=body,
                         headers=service_headers).json()["data"]
    model_id = first["model_id"]
    client.post(f"/api/v1/models/{model_id}/promote",
                json={"version_id": first["version_id"],
                      "to_status": "PRODUCTION"},
                headers=service_headers)
    client.post(f"/api/v1/models/{model_id}/promote",
                json={"version_id": second["version_id"],
                      "to_status": "PRODUCTION"},
                headers=service_headers)

    rolled = client.post(f"/api/v1/models/{model_id}/rollback",
                         json={"actor": "tester", "note": "bad deploy"},
                         headers=service_headers)
    assert rolled.status_code == 200, rolled.text
    assert rolled.json()["data"]["rolled_back_to"] == first["version_id"]

    events = client.get(f"/api/v1/models/{model_id}/events",
                        headers=service_headers)
    assert events.status_code == 200
    kinds = [e["event_type"] for e in events.json()["data"]["events"]]
    assert "rollback" in kinds

    detail = client.get(f"/api/v1/models/{model_id}/detail",
                        headers=service_headers)
    assert detail.status_code == 200
    versions = detail.json()["data"]["versions"]
    assert len(versions) == 2
    for v in versions:
        for key in ("version", "training_timestamp", "dataset_version",
                    "features", "metrics", "params", "artifact_path",
                    "status", "deployment_status"):
            assert key in v, f"missing metadata key: {key}"

    lonely = client.post("/api/v1/training/train",
                         json={"model_type": "anomaly", "name": "http-lonely",
                               "params": {}, "dataset": anomaly_series(20)},
                         headers=service_headers).json()["data"]
    client.post(f"/api/v1/models/{lonely['model_id']}/promote",
                json={"version_id": lonely["version_id"],
                      "to_status": "PRODUCTION"},
                headers=service_headers)
    stuck = client.post(f"/api/v1/models/{lonely['model_id']}/rollback",
                        json={}, headers=service_headers)
    assert stuck.status_code == 422


def test_forecast_domain_endpoints(client, service_headers, clean_warehouse):
    domains = client.get("/api/v1/forecast/domains", headers=service_headers)
    assert domains.status_code == 200
    assert domains.json()["data"]["domains"] == [
        "customers", "demand", "inventory", "operational", "revenue", "sales"]

    hist = [{"date": r["date"], "demand": r["y"]} for r in forecast_rows(30)]
    ok = client.post("/api/v1/forecast/demand",
                     json={"history": hist, "horizon": 7},
                     headers=service_headers)
    assert ok.status_code == 200, ok.text
    assert len(ok.json()["data"]["forecast"]) == 7
    assert ok.json()["data"]["metrics"]["domain"] == "demand"

    # The base endpoint keeps its byte-identical shape and behaviour.
    base = client.post("/api/v1/forecast",
                       json={"history": forecast_rows(30)[:30], "horizon": 5},
                       headers=service_headers)
    assert base.status_code == 200
    assert len(base.json()["data"]["forecast"]) == 5

    # A demand history with no demand-like column is an explicit non-result.
    bad = client.post("/api/v1/forecast/demand",
                      json={"history": [{"date": "2024-01-01", "note": "x"}],
                            "horizon": 3},
                      headers=service_headers)
    assert bad.status_code == 200
    assert bad.json()["data"]["method"] == "unsupported_shape"
    assert bad.json()["data"]["forecast"] == []

    bogus = client.post("/api/v1/forecast/bogus",
                        json={"history": [], "horizon": 3},
                        headers=service_headers)
    assert bogus.status_code == 422
