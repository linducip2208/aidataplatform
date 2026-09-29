"""Persist quality runs + findings; history and score trend per dataset_ref."""
from __future__ import annotations

from typing import Any, Dict, List

from sqlalchemy.orm import Session

from app.database.connection import Base


def ensure_tables(db: Session) -> None:
    """Create the three quality tables when they are missing (checkfirst).

    The test warehouse is built with ``Base.metadata.create_all`` before this
    package's models are imported by some modules, so endpoints call this
    rather than assuming the tables exist. Never drops or migrates.
    """
    from app.quality import models as _models  # noqa: F401 (register mappers)

    bind = db.get_bind()
    for table in (_models.QualityRule.__table__,
                  _models.QualityRun.__table__,
                  _models.QualityFinding.__table__):
        table.create(bind=bind, checkfirst=True)
    # Keep the shared metadata in sync for session fixtures that reset by table.
    Base.metadata.create_all(bind=bind, checkfirst=True)


def _json_safe(value: Any) -> Any:
    if isinstance(value, dict):
        return {str(k): _json_safe(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(v) for v in value]
    try:
        import math

        import numpy as np
        import pandas as pd

        if value is None or isinstance(value, (str, int, bool)):
            return value
        if isinstance(value, float):
            return value if math.isfinite(value) else None
        if isinstance(value, (np.integer,)):
            return int(value)
        if isinstance(value, (np.floating,)):
            return float(value) if math.isfinite(float(value)) else None
        if isinstance(value, (np.bool_,)):
            return bool(value)
        if isinstance(value, (pd.Timestamp,)):
            return value.isoformat()
    except Exception:
        pass
    try:
        import datetime as _dt

        if isinstance(value, (_dt.datetime, _dt.date)):
            return value.isoformat()
    except Exception:
        pass
    return value


def save_run(db: Session, *, dataset_ref: str | None, job_id: int | None,
             profile: str | None, scores: Dict[str, Any], verdict: str):
    """Insert a ``QualityRun`` row, flush (id assigned), and return it."""
    from app.quality.models import QualityRun

    ensure_tables(db)
    run = QualityRun(
        dataset_ref=dataset_ref,
        job_id=job_id,
        profile=profile,
        scores=_json_safe(dict(scores or {})),
        verdict=str(verdict),
    )
    db.add(run)
    db.flush()
    return run


def save_findings(db: Session, run_id: int, results: List[Dict[str, Any]]) -> List[Any]:
    """Insert one ``QualityFinding`` per *failed* rule; returns the rows."""
    from app.quality.models import QualityFinding

    ensure_tables(db)
    rows = []
    for result in results or []:
        if result.get("passed", True):
            continue
        rows.append(QualityFinding(
            run_id=run_id,
            rule_id=str(result.get("id")) if result.get("id") is not None else None,
            column=result.get("column"),
            sample=_json_safe(list(result.get("sample_failures", []))),
            count=int(result.get("failure_count", 0)),
        ))
    for row in rows:
        db.add(row)
    db.flush()
    return rows


def _run_to_dict(run: Any) -> Dict[str, Any]:
    created = run.created_at.isoformat() if getattr(run, "created_at", None) else None
    return {
        "id": run.id,
        "dataset_ref": run.dataset_ref,
        "job_id": run.job_id,
        "profile": run.profile,
        "scores": run.scores or {},
        "verdict": run.verdict,
        "created_at": created,
    }


def get_history(db: Session, dataset_ref: str | None = None, limit: int = 50) -> List[Dict[str, Any]]:
    """Newest-first run dicts, optionally filtered by ``dataset_ref``. Read-only."""
    from app.quality.models import QualityRun

    ensure_tables(db)
    limit = max(1, min(int(limit or 50), 200))
    query = db.query(QualityRun).order_by(QualityRun.id.desc())
    if dataset_ref:
        query = query.filter(QualityRun.dataset_ref == dataset_ref)
    return [_run_to_dict(run) for run in query.limit(limit).all()]


def get_run_detail(db: Session, run_id: int) -> Dict[str, Any] | None:
    """One run with its findings, or ``None``. Read-only."""
    from app.quality.models import QualityFinding, QualityRun

    ensure_tables(db)
    run = db.query(QualityRun).filter(QualityRun.id == run_id).first()
    if run is None:
        return None
    findings = (db.query(QualityFinding)
                .filter(QualityFinding.run_id == run_id)
                .order_by(QualityFinding.id.asc()).all())
    detail = _run_to_dict(run)
    detail["findings"] = [{
        "id": f.id,
        "rule_id": f.rule_id,
        "column": f.column,
        "sample": f.sample or [],
        "count": f.count,
    } for f in findings]
    return detail


def _score_of(run: Dict[str, Any]) -> float:
    try:
        return float((run.get("scores") or {}).get("score", 0.0))
    except (TypeError, ValueError):
        return 0.0


def get_trend(db: Session, dataset_ref: str, limit: int = 20) -> Dict[str, Any]:
    """Oldest-first scores plus drift direction. Read-only.

    ``direction`` compares the newest score to the oldest in the window:
    ``improving`` / ``degrading`` / ``stable`` (equal within 1e-9); ``delta``
    is ``newest - oldest`` rounded to 4 decimals.
    """
    from app.quality.models import QualityRun

    ensure_tables(db)
    limit = max(1, min(int(limit or 20), 200))
    rows = (db.query(QualityRun)
            .filter(QualityRun.dataset_ref == dataset_ref)
            .order_by(QualityRun.id.asc()).all()[-limit:])
    points = [{
        "run_id": run.id,
        "score": _score_of(_run_to_dict(run)),
        "verdict": run.verdict,
        "created_at": run.created_at.isoformat() if run.created_at else None,
    } for run in rows]
    if len(points) < 2:
        direction, delta = "stable", 0.0
    else:
        delta = round(points[-1]["score"] - points[0]["score"], 4)
        if delta > 1e-9:
            direction = "improving"
        elif delta < -1e-9:
            direction = "degrading"
        else:
            direction = "stable"
    return {
        "dataset_ref": dataset_ref,
        "run_count": len(points),
        "scores": points,
        "direction": direction,
        "delta": delta,
    }
