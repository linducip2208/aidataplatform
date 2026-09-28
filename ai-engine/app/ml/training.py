"""train_model() dispatcher by model_type; persists artifacts to models/ dir."""
from __future__ import annotations

from typing import Any, Dict, List

from app.ml import registry


def train_model(model_type: str, name: str = "model", params: Dict[str, Any] | None = None,
                dataset: List[Dict[str, Any]] | None = None, db_session=None) -> Dict[str, Any]:
    params = params or {}
    dataset = dataset or []
    mt = model_type.lower()
    meta = registry.create_model(name, mt, db_session=db_session)
    model_id = meta["id"]
    metrics: Dict[str, Any] = {}
    artifact: Any = {"model_type": mt, "params": params}

    if mt == "forecast":
        from app.ml.forecasting import SalesForecaster

        horizon = int(params.get("horizon", 30))
        fc = SalesForecaster(horizon=horizon)
        info = fc.fit(dataset)
        artifact = {"forecaster_state": "fitted", "horizon": horizon,
                    "n_obs": info.get("n_obs", 0)}
        # persist via forecaster.save then registry re-saves; keep metrics
        metrics = {"n_obs": info.get("n_obs", 0), "resid_std": info.get("resid_std", 0.0),
                   "method": "baseline+gbm"}
    elif mt == "churn":
        from app.ml.churn import train_churn

        res = train_churn(dataset)
        artifact = {"model": res["model"], "scaler": res["scaler"], "features": res["features"]}
        metrics = res["metrics"]
    elif mt in ("segmentation", "segment"):
        from app.ml.segmentation import segment

        res = segment(dataset, n_clusters=int(params.get("n_clusters", 4)))
        artifact = {"model": res.get("model"), "scaler": res.get("scaler")}
        metrics = res.get("metrics", {})
    elif mt == "anomaly":
        from app.ml.anomaly import detect_anomalies

        res = detect_anomalies(dataset, sensitivity=float(params.get("sensitivity", 2.5)))
        artifact = {"sensitivity": params.get("sensitivity", 2.5)}
        metrics = res.get("metrics", {})
    elif mt in ("recommend", "recommendation"):
        artifact = {"transactions": dataset[:5000]}
        metrics = {"n_transactions": len(dataset)}
    else:
        raise ValueError(f"Unknown model_type: {model_type}")

    ver = registry.create_version(model_id, metrics, artifact, db_session=db_session)
    registry.record_training_run(model_id, ver["id"], mt, params, metrics, status="done", db_session=db_session)
    registry.promote(model_id, ver["id"], "VALIDATED", db_session=db_session)
    return {"model_id": model_id, "version_id": ver["id"], "version": ver["version"],
            "metrics": metrics, "status": "VALIDATED"}
