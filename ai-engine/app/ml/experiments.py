"""Experiment tracking: splits, per-split metrics, comparison, promotion.

An experiment pins the full training context — model type, dataset reference and
version, feature list, params, and the train/validate/test split plan — then
trains once on the full dataset through :mod:`app.ml.training` (so the artifact
is a real registry version, not a side-channel pickle) while recording honest
per-split metrics computed with the same functions that serve predictions.

Splits are deterministic (``seed``) and strategy-aware:

* ``time_aware`` — chronological, contiguous splits for series data (forecast
  types, or any payload carrying a date column). No shuffling: the future never
  leaks into the training window.
* ``stratified`` — class-proportioned splits for classification (churn types)
  when every class can support them, plain shuffle otherwise.
* ``random`` — seeded shuffle for everything else.

Persistence is the ``ml_experiments`` table declared below on the shared
``Base`` (DDL spec for master is in the ``MLExperiment`` docstring). The
registry tables (``ml_models`` / ``model_versions`` / ``training_runs``) keep
owning models, versions and runs; an experiment only *links* to the version its
full-dataset fit produced via ``model_id`` / ``version_id``.
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

from sqlalchemy import JSON, DateTime, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.database.connection import Base

#: Model types that accept experiments. Mirrors the dispatcher in
#: ``app.ml.training`` plus the aliases it accepts.
SUPPORTED_TYPES = frozenset({
    "forecast", "churn", "segmentation", "segment",
    "anomaly", "recommend", "recommendation",
})

#: Series-shaped types always split chronologically.
SERIES_TYPES = frozenset({"forecast"})
#: Labelled types split class-proportioned when the labels allow it.
CLASSIFICATION_TYPES = frozenset({"churn"})

#: (default ranking split, default ranking metric, higher-is-better) per type.
#: Every default names a metric the per-split trainers below actually emit, so a
#: ranking never keys off a missing value; callers may override all three.
DEFAULT_RANKING: Dict[str, Tuple[str, str, bool]] = {
    "forecast": ("validate", "mae", False),
    "churn": ("validate", "f1", True),
    "segmentation": ("train", "silhouette", True),
    "segment": ("train", "silhouette", True),
    "anomaly": ("train", "n_anomalies", False),
    "recommend": ("train", "n_transactions", True),
    "recommendation": ("train", "n_transactions", True),
}


class ExperimentError(ValueError):
    """Base class for experiment failures (subclasses ``ValueError`` so the
    routers' ``except ValueError`` handlers render the engine error envelope)."""


class UnknownExperiment(ExperimentError):
    """No ``ml_experiments`` row with that id."""


class MLExperiment(Base):
    """Experiment tracking row.

    DDL spec for master (single alembic revision at integration)::

        CREATE TABLE ml_experiments (
            id SERIAL PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            model_type VARCHAR(64) NOT NULL,
            dataset_ref VARCHAR(512) NOT NULL DEFAULT '',
            dataset_version VARCHAR(64) NOT NULL DEFAULT '',
            feature_list JSON NOT NULL DEFAULT '[]',
            params JSON NOT NULL DEFAULT '{}',
            split_config JSON NOT NULL DEFAULT '{}',
            metrics JSON NOT NULL DEFAULT '{}',
            status VARCHAR(32) NOT NULL DEFAULT 'PLANNED',
            model_id INTEGER NULL REFERENCES ml_models(id),
            version_id INTEGER NULL REFERENCES model_versions(id),
            created_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ NULL
        );
        CREATE INDEX ix_ml_experiments_model_type ON ml_experiments (model_type);
    """

    __tablename__ = "ml_experiments"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(128), default="")
    model_type: Mapped[str] = mapped_column(String(64), default="", index=True)
    dataset_ref: Mapped[str] = mapped_column(String(512), default="")
    dataset_version: Mapped[str] = mapped_column(String(64), default="")
    feature_list: Mapped[list] = mapped_column(JSON, default=list)
    params: Mapped[dict] = mapped_column(JSON, default=dict)
    split_config: Mapped[dict] = mapped_column(JSON, default=dict)
    metrics: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(32), default="PLANNED")
    model_id: Mapped[int | None] = mapped_column(
        ForeignKey("ml_models.id"), nullable=True, default=None)
    version_id: Mapped[int | None] = mapped_column(
        ForeignKey("model_versions.id"), nullable=True, default=None)
    created_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, default=None)
    updated_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, default=None,
        onupdate=lambda: datetime.now(timezone.utc))


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _session(db_session):
    if db_session is None:
        from app.database.connection import SessionLocal

        db = SessionLocal()
        return db, True
    return db_session, False


def _normalise_ratios(train: float, val: float, test: float) -> Tuple[float, float, float]:
    """Validate and normalise split ratios to sum to 1.

    Raises :class:`ExperimentError` for non-positive or non-finite ratios
    instead of silently producing an empty split downstream.
    """
    import math

    vals = [float(train), float(val), float(test)]
    if any(not math.isfinite(v) or v <= 0 for v in vals):
        raise ExperimentError(
            f"split ratios must each be positive, got train={train!r} "
            f"val={val!r} test={test!r}")
    total = sum(vals)
    return vals[0] / total, vals[1] / total, vals[2] / total


def _normalise_model_type(model_type: str) -> str:
    mt = str(model_type or "").strip().lower()
    if mt not in SUPPORTED_TYPES:
        raise ExperimentError(
            f"unsupported model_type {model_type!r}; expected one of: "
            + ", ".join(sorted(SUPPORTED_TYPES)))
    return mt


def _date_key_of(rows: List[Dict[str, Any]],
                 hint: Optional[str] = None) -> Optional[str]:
    """Return the date column to split on, or ``None`` when there is none.

    An explicit ``hint`` wins when it names a real column; otherwise the first
    column whose name carries a date token and whose cells parse as dates.
    """
    if not rows:
        return None
    import pandas as pd

    cols = list(rows[0].keys()) if isinstance(rows[0], dict) else []
    if hint and hint in cols:
        return hint
    tokens = ("date", "ds", "period", "time", "timestamp", "tanggal", "tgl")
    for col in cols:
        lc = str(col).lower()
        if any(tok in lc for tok in tokens):
            try:
                parsed = pd.to_datetime(
                    [r.get(col) for r in rows if isinstance(r, dict)],
                    errors="coerce", format="mixed")
                if bool((~parsed.isna()).any()):
                    return col
            except Exception:
                continue
    return None


def _label_values(rows: List[Dict[str, Any]],
                  label_key: Optional[str] = None) -> Optional[List[int]]:
    """Return integer labels for stratification, or ``None`` when the payload
    carries no usable two-class label."""
    if not rows:
        return None
    import pandas as pd

    keys = list(rows[0].keys()) if isinstance(rows[0], dict) else []
    candidates = ([label_key] if label_key else []) + ["churn", "churned", "label", "target", "y"]
    for key in candidates:
        if key and key in keys:
            try:
                # pd.to_numeric on a plain list returns an ndarray (no
                # fillna/nunique), so wrap in a Series first.
                vals = pd.Series(pd.to_numeric(
                    [r.get(key) for r in rows], errors="coerce")).fillna(0).astype(int)
                if int(vals.nunique()) >= 2:
                    return [int(v) for v in vals.tolist()]
            except Exception:
                continue
    return None


def split_dataset(dataset: List[Dict[str, Any]], model_type: str,
                  train_ratio: float = 0.7, val_ratio: float = 0.15,
                  test_ratio: float = 0.15, seed: int = 42,
                  label_key: Optional[str] = None,
                  date_key: Optional[str] = None) -> Dict[str, Any]:
    """Split ``dataset`` into train/validate/test row lists, deterministically.

    Returns ``{"train", "validate", "test", "strategy", "sizes", "seed",
    "ratios"}``. ``sizes`` always sums to ``len(dataset)``; with fewer than 3
    rows every row stays in ``train`` and the strategy is reported as
    ``"too_small_to_split"`` rather than inventing empty-but-valid splits.
    """
    rows = [dict(r) for r in (dataset or []) if isinstance(r, dict)]
    n = len(rows)
    mt = _normalise_model_type(model_type)
    train_r, val_r, test_r = _normalise_ratios(train_ratio, val_ratio, test_ratio)
    seed = int(seed or 0)

    if n < 3:
        return {"train": rows, "validate": [], "test": [],
                "strategy": "too_small_to_split",
                "sizes": {"train": n, "validate": 0, "test": 0},
                "seed": seed,
                "ratios": {"train": train_r, "validate": val_r, "test": test_r}}

    date_col = date_key or _date_key_of(rows)
    if mt in SERIES_TYPES or (date_col and mt not in CLASSIFICATION_TYPES):
        strategy = "time_aware"
        order: List[int] = list(range(n))
        if date_col:
            import pandas as pd

            try:
                parsed = pd.to_datetime(
                    [r.get(date_col) for r in rows], errors="coerce", format="mixed")
                order = sorted(range(n),
                               key=lambda i: (bool(pd.isna(parsed[i])), str(parsed[i])))
            except Exception:
                order = list(range(n))
        n_train = max(1, int(round(n * train_r)))
        n_val = max(0, int(round(n * val_r)))
        if n_train + n_val >= n:
            n_val = max(0, n - n_train - 1)
        n_test = n - n_train - n_val
        train_idx = order[:n_train]
        val_idx = order[n_train:n_train + n_val]
        test_idx = order[n_train + n_val:]
        return {"train": [rows[i] for i in train_idx],
                "validate": [rows[i] for i in val_idx],
                "test": [rows[i] for i in test_idx],
                "strategy": strategy,
                "sizes": {"train": len(train_idx), "validate": len(val_idx),
                          "test": len(test_idx)},
                "seed": seed,
                "ratios": {"train": train_r, "validate": val_r, "test": test_r}}

    labels = _label_values(rows, label_key) if mt in CLASSIFICATION_TYPES else None
    if labels is not None:
        from collections import Counter

        from sklearn.model_selection import train_test_split

        counts = Counter(labels)
        strategy = "stratified" if min(counts.values()) >= 2 else "random"
        idx = list(range(n))
        try:
            if strategy == "stratified":
                tr, rest = train_test_split(idx, train_size=train_r, random_state=seed,
                                            stratify=labels)
                rest_labels = [labels[i] for i in rest]
                val_frac = val_r / (val_r + test_r)
                if min(Counter(rest_labels).values()) >= 2:
                    va, te = train_test_split(rest, train_size=val_frac,
                                              random_state=seed, stratify=rest_labels)
                else:
                    va, te = train_test_split(rest, train_size=val_frac,
                                              random_state=seed)
            else:
                tr, rest = train_test_split(idx, train_size=train_r, random_state=seed)
                va, te = train_test_split(rest, train_size=val_r / (val_r + test_r),
                                          random_state=seed)
        except Exception:
            strategy = "random"
            tr, rest = train_test_split(idx, train_size=train_r, random_state=seed)
            va, te = train_test_split(rest, train_size=val_r / (val_r + test_r),
                                      random_state=seed)
        return {"train": [rows[i] for i in tr],
                "validate": [rows[i] for i in va],
                "test": [rows[i] for i in te],
                "strategy": strategy,
                "sizes": {"train": len(tr), "validate": len(va), "test": len(te)},
                "seed": seed,
                "ratios": {"train": train_r, "validate": val_r, "test": test_r}}

    from sklearn.model_selection import train_test_split

    idx = list(range(n))
    tr, rest = train_test_split(idx, train_size=train_r, random_state=seed)
    va, te = train_test_split(rest, train_size=val_r / (val_r + test_r),
                              random_state=seed)
    return {"train": [rows[i] for i in tr],
            "validate": [rows[i] for i in va],
            "test": [rows[i] for i in te],
            "strategy": "random",
            "sizes": {"train": len(tr), "validate": len(va), "test": len(te)},
            "seed": seed,
            "ratios": {"train": train_r, "validate": val_r, "test": test_r}}


# --------------------------------------------------------------------------
# per-split metrics (real trainers, never invented numbers)
# --------------------------------------------------------------------------

def _forecast_train_metrics(rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    from app.ml.forecasting import forecast

    if not rows:
        return {"n_obs": 0, "reason": "empty split, no metrics computed"}
    res = forecast(rows, horizon=min(30, max(1, len(rows))))
    return {"n_obs": res["metrics"].get("n_obs", 0),
            "mae_baseline": res["metrics"].get("mae_baseline", 0.0),
            "resid_std": res["metrics"].get("resid_std", 0.0),
            "method": res.get("method", "baseline")}


def _forecast_eval_metrics(train_rows: List[Dict[str, Any]],
                           eval_rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    """MAE of a model fitted on ``train_rows`` against held-out ``eval_rows``.

    Both sides are normalised through the same daily-series cleaner, so the
    comparison is chronological values against chronological values, aligned by
    order. When either side is unusable the metric is ``None`` with a reason —
    never a zero that would read as a perfect forecast.
    """
    from app.ml.forecasting import SalesForecaster, _daily_series

    if not train_rows or not eval_rows:
        return {"mae": None, "n_eval": 0,
                "reason": "empty train or eval split, no metrics computed"}
    fc = SalesForecaster(horizon=max(1, len(eval_rows)))
    fc.fit(train_rows)
    preds = fc.predict(len(eval_rows))
    actual = _daily_series(eval_rows)
    if not preds or len(actual) == 0:
        return {"mae": None, "n_eval": int(len(actual)),
                "reason": "no forecast or no usable eval points"}
    import numpy as np

    k = min(len(preds), len(actual))
    y_pred = np.array([p["yhat"] for p in preds[:k]], dtype=float)
    y_true = np.asarray(actual["y"].to_numpy(), dtype=float)[:k]
    mae = float(np.mean(np.abs(y_pred - y_true)))
    return {"mae": round(mae, 4), "n_eval": int(k), "method": fc.method}


def _churn_eval_metrics(model, scaler,
                        eval_rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Accuracy/F1 of a fitted churn pair on held-out rows with true labels."""
    from sklearn.metrics import accuracy_score, f1_score

    from app.ml.churn import _frame, predict_churn_proba

    if not eval_rows:
        return {"accuracy": None, "f1": None, "n_eval": 0,
                "reason": "empty eval split, no metrics computed"}
    try:
        frame = _frame(list(eval_rows))
        y_true = frame["churn"].to_numpy(dtype=int).tolist()
    except Exception as exc:
        return {"accuracy": None, "f1": None, "n_eval": len(eval_rows),
                "reason": f"eval labels unreadable: {type(exc).__name__}"}
    scored = predict_churn_proba(model, scaler, list(eval_rows))
    pairs = [(bool(s["churn"]), int(t)) for s, t in zip(scored, y_true)
             if not s.get("error")]
    if not pairs:
        return {"accuracy": None, "f1": None, "n_eval": len(eval_rows),
                "reason": "no eval rows could be scored"}
    y_pred = [1 if p else 0 for p, _ in pairs]
    y_true = [t for _, t in pairs]
    return {"accuracy": round(float(accuracy_score(y_true, y_pred)), 4),
            "f1": round(float(f1_score(y_true, y_pred, zero_division=0)), 4),
            "n_eval": len(pairs)}


def _split_metrics(model_type: str, split: Dict[str, List[Dict[str, Any]]],
                   params: Dict[str, Any]) -> Dict[str, Any]:
    """Compute per-split metrics with the real trainers for ``model_type``."""
    mt = model_type
    train_rows, val_rows, test_rows = split["train"], split["validate"], split["test"]

    if mt == "forecast":
        out = {"train": _forecast_train_metrics(train_rows)}
        out["validate"] = _forecast_eval_metrics(train_rows, val_rows)
        out["test"] = _forecast_eval_metrics(train_rows, test_rows)
        return out

    if mt == "churn":
        from app.ml.churn import train_churn

        res = train_churn(train_rows)
        out = {"train": dict(res.get("metrics", {}), algo=res.get("algo", ""))}
        out["validate"] = _churn_eval_metrics(res["model"], res["scaler"], val_rows)
        out["test"] = _churn_eval_metrics(res["model"], res["scaler"], test_rows)
        return out

    if mt in ("segmentation", "segment"):
        from app.ml.segmentation import segment

        n_clusters = int((params or {}).get("n_clusters", 4))
        out = {}
        for name, rows in (("train", train_rows), ("validate", val_rows),
                           ("test", test_rows)):
            if not rows:
                out[name] = {"silhouette": None, "n_clusters": 0,
                             "reason": "empty split, no metrics computed"}
                continue
            res = segment(rows, n_clusters=n_clusters)
            out[name] = dict(res.get("metrics", {}))
        return out

    if mt == "anomaly":
        from app.ml.anomaly import detect_anomalies

        sensitivity = float((params or {}).get("sensitivity", 2.5))
        out = {}
        for name, rows in (("train", train_rows), ("validate", val_rows),
                           ("test", test_rows)):
            if not rows:
                out[name] = {"n": 0, "n_anomalies": 0,
                             "reason": "empty split, no metrics computed"}
                continue
            res = detect_anomalies(rows, sensitivity)
            out[name] = {"n": res["metrics"].get("n", 0),
                         "n_anomalies": res["metrics"].get("n_anomalies", 0)}
        return out

    # recommend / recommendation: honest counts per split.
    out = {}
    for name, rows in (("train", train_rows), ("validate", val_rows),
                       ("test", test_rows)):
        products = {str(r.get(k)) for r in rows for k in r
                    if str(k).lower() in ("product", "product_name", "nama barang",
                                          "nama produk", "barang", "nm brg")
                    and r.get(k) not in (None, "")}
        customers = {str(r.get(k)) for r in rows for k in r
                     if str(k).lower() in ("customer", "customer_name",
                                           "nama customer", "pelanggan")
                     and r.get(k) not in (None, "")}
        out[name] = {"n_transactions": len(rows),
                     "n_products": len(products), "n_customers": len(customers)}
    return out


# --------------------------------------------------------------------------
# CRUD + lifecycle
# --------------------------------------------------------------------------

def _row_to_dict(row: "MLExperiment") -> Dict[str, Any]:
    return {"id": row.id, "name": row.name, "model_type": row.model_type,
            "dataset_ref": row.dataset_ref, "dataset_version": row.dataset_version,
            "feature_list": list(row.feature_list or []),
            "params": dict(row.params or {}),
            "split_config": dict(row.split_config or {}),
            "metrics": dict(row.metrics or {}), "status": row.status,
            "model_id": row.model_id, "version_id": row.version_id,
            "created_at": row.created_at.isoformat() if row.created_at else None,
            "updated_at": row.updated_at.isoformat() if row.updated_at else None}


def _require_experiment(db, experiment_id: int) -> "MLExperiment":
    row = db.query(MLExperiment).filter_by(id=experiment_id).first()
    if not row:
        raise UnknownExperiment(f"experiment {experiment_id} not found")
    return row


def create_experiment(model_type: str, dataset: List[Dict[str, Any]] | None = None,
                      *, name: str = "experiment",
                      dataset_ref: str = "", dataset_version: str = "",
                      feature_list: List[str] | None = None,
                      params: Dict[str, Any] | None = None,
                      train_ratio: float = 0.7, val_ratio: float = 0.15,
                      test_ratio: float = 0.15, seed: int = 42,
                      label_key: Optional[str] = None,
                      date_key: Optional[str] = None,
                      run_training: bool = True,
                      db_session=None) -> Dict[str, Any]:
    """Create an experiment, split its dataset, and (by default) train it.

    The split plan is stored in ``split_config``; per-split metrics land in
    ``metrics``; the full-dataset fit from :func:`app.ml.training.train_model`
    is linked via ``model_id`` / ``version_id``. With no usable dataset rows
    the experiment is stored as ``PLANNED`` with the split plan and no metrics
    — an explicit non-result, never zero-filled metrics.
    """
    from app.ml import training as training_mod

    mt = _normalise_model_type(model_type)
    rows = [dict(r) for r in (dataset or []) if isinstance(r, dict)]
    params = dict(params or {})
    feature_list = [str(f) for f in (feature_list or [])]

    db, own = _session(db_session)
    try:
        row = MLExperiment(
            name=str(name or "experiment"), model_type=mt,
            dataset_ref=str(dataset_ref or ""),
            dataset_version=str(dataset_version or ""),
            feature_list=feature_list, params=params,
            split_config={}, metrics={}, status="PLANNED",
            created_at=_utcnow(), updated_at=_utcnow())
        db.add(row)
        db.flush()

        split = split_dataset(rows, mt, train_ratio, val_ratio, test_ratio,
                              seed, label_key, date_key)
        row.split_config = {
            "strategy": split["strategy"], "sizes": split["sizes"],
            "seed": split["seed"], "ratios": split["ratios"],
            "label_key": label_key, "date_key": date_key,
            "n_rows": len(rows)}

        if not rows or not run_training:
            row.status = "PLANNED"
            if not rows:
                row.metrics = {"reason": "no dataset rows supplied"}
            db.commit()
            db.refresh(row)
            return _row_to_dict(row)

        try:
            per_split = _split_metrics(mt, split, params)
        except Exception as exc:
            row.status = "FAILED"
            row.metrics = {"reason": f"per-split training failed: {type(exc).__name__}: {exc}"}
            db.commit()
            db.refresh(row)
            return _row_to_dict(row)

        model_name = str(params.get("model_name") or f"experiment-{row.id}-{row.name}")[:128]
        try:
            trained = training_mod.train_model(mt, model_name, params, rows, db)
        except Exception as exc:
            row.status = "FAILED"
            row.metrics = {**per_split,
                           "reason": f"registry training failed: {type(exc).__name__}: {exc}"}
            db.commit()
            db.refresh(row)
            return _row_to_dict(row)

        from app.ml import registry as reg

        reg.record_training_run(
            trained["model_id"], trained["version_id"], mt,
            {**params, "experiment_id": row.id,
             "dataset_ref": row.dataset_ref,
             "dataset_version": row.dataset_version,
             "features": feature_list},
            {"per_split": per_split, **trained.get("metrics", {})},
            status="done", db_session=db)
        row.model_id = trained["model_id"]
        row.version_id = trained["version_id"]
        row.metrics = per_split
        row.status = "DONE"
        row.updated_at = _utcnow()
        db.commit()
        db.refresh(row)
        return _row_to_dict(row)
    finally:
        if own:
            db.close()


def get_experiment(experiment_id: int, db_session=None) -> Dict[str, Any]:
    db, own = _session(db_session)
    try:
        return _row_to_dict(_require_experiment(db, int(experiment_id)))
    finally:
        if own:
            db.close()


def list_experiments(model_type: Optional[str] = None, limit: int = 200,
                     db_session=None) -> List[Dict[str, Any]]:
    db, own = _session(db_session)
    try:
        q = db.query(MLExperiment).order_by(MLExperiment.id.desc())
        if model_type:
            q = q.filter_by(model_type=_normalise_model_type(model_type))
        capped = max(1, min(int(limit or 200), 500))
        return [_row_to_dict(r) for r in q.limit(capped).all()]
    finally:
        if own:
            db.close()


def record_metrics(experiment_id: int, metrics: Dict[str, Any],
                   db_session=None) -> Dict[str, Any]:
    """Attach (or overwrite) per-split metrics on an experiment.

    The payload is stored verbatim under ``metrics``; ranking reads
    ``metrics[split][metric]``, so a manual record must keep that shape.
    """
    db, own = _session(db_session)
    try:
        row = _require_experiment(db, int(experiment_id))
        row.metrics = dict(metrics or {})
        row.updated_at = _utcnow()
        db.commit()
        db.refresh(row)
        return _row_to_dict(row)
    finally:
        if own:
            db.close()


def _ranking_default(model_type: str) -> Tuple[str, str, bool]:
    return DEFAULT_RANKING.get(model_type, ("validate", "f1", True))


def compare_experiments(experiment_ids: List[int], metric: Optional[str] = None,
                        split: Optional[str] = None,
                        higher_is_better: Optional[bool] = None,
                        db_session=None) -> Dict[str, Any]:
    """Rank experiments by one per-split metric, best first.

    Experiments missing the metric sort last with ``value: None`` and an
    explicit ``reason`` — a missing score never outranks a measured one, and
    never silently becomes zero.
    """
    ids = [int(i) for i in (experiment_ids or [])]
    if len(ids) < 1:
        raise ExperimentError("compare needs at least one experiment id")

    db, own = _session(db_session)
    try:
        rows = [db.query(MLExperiment).filter_by(id=i).first() for i in ids]
        missing = [i for i, r in zip(ids, rows) if r is None]
        if missing:
            raise UnknownExperiment(f"experiment(s) not found: {missing}")
        first_type = rows[0].model_type
        if any(r.model_type != first_type for r in rows):
            raise ExperimentError(
                "compare needs experiments of a single model_type, got: "
                + ", ".join(sorted({r.model_type for r in rows})))
        default_split, default_metric, default_higher = _ranking_default(first_type)
        use_split = str(split or default_split)
        use_metric = str(metric or default_metric)
        use_higher = default_higher if higher_is_better is None else bool(higher_is_better)

        ranked = []
        for r in rows:
            block = (r.metrics or {}).get(use_split) or {}
            value = block.get(use_metric)
            try:
                numeric = float(value) if value is not None else None
            except (TypeError, ValueError):
                numeric = None
            ranked.append({"experiment_id": r.id, "name": r.name,
                           "model_type": r.model_type, "status": r.status,
                           "model_id": r.model_id, "version_id": r.version_id,
                           "metric": use_metric, "split": use_split,
                           "value": numeric,
                           "reason": None if numeric is not None
                           else f"no numeric {use_split}.{use_metric} on experiment {r.id}"})
        measured = [e for e in ranked if e["value"] is not None]
        unmeasured = [e for e in ranked if e["value"] is None]
        measured.sort(key=lambda e: e["value"], reverse=use_higher)
        ordered = measured + unmeasured
        for pos, entry in enumerate(ordered, start=1):
            entry["rank"] = pos
        return {"metric": use_metric, "split": use_split,
                "higher_is_better": use_higher, "ranking": ordered,
                "best_experiment_id": ordered[0]["experiment_id"]
                if ordered and ordered[0]["value"] is not None else None}
    finally:
        if own:
            db.close()


def promote_experiment(experiment_id: int, version_id: Optional[int] = None,
                       db_session=None) -> Dict[str, Any]:
    """Promote an experiment's linked version to PRODUCTION via the registry.

    Only a ``DONE`` experiment carrying a linked ``model_id`` / ``version_id``
    can be promoted; anything else is an explicit error, never a silent no-op.
    An explicit ``version_id`` must belong to the same model.
    """
    from app.ml import registry as reg

    db, own = _session(db_session)
    try:
        row = _require_experiment(db, int(experiment_id))
        if row.status != "DONE" or not row.model_id or not row.version_id:
            raise ExperimentError(
                f"experiment {row.id} is {row.status} with no trained version; "
                "only a DONE experiment with a linked version can be promoted")
        target = int(version_id) if version_id else row.version_id
        res = reg.promote(row.model_id, target, "PRODUCTION", db)
        row.updated_at = _utcnow()
        db.commit()
        return {"experiment_id": row.id, **res}
    finally:
        if own:
            db.close()
