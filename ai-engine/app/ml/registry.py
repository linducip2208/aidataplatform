"""Model registry service: create/version/promote/load production model."""
from __future__ import annotations

import logging
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Optional

import joblib

from app.core.config import settings

log = logging.getLogger(__name__)

FLOW = ["DRAFT", "TRAINING", "VALIDATED", "PRODUCTION", "ARCHIVED", "FAILED"]
#: ``STAGED`` is not a lifecycle state; ``Api\MlController`` still offers it as a
#: label, so it is accepted as a target but is not a state anything may leave.
STAGED = "STAGED"
PROMOTABLE_STATUSES = frozenset(FLOW) | {STAGED}
#: Legal status changes. An absent entry means the state is terminal.
TRANSITIONS: Dict[str, frozenset] = {
    "DRAFT": frozenset({"TRAINING", "VALIDATED", "FAILED", "ARCHIVED"}),
    "TRAINING": frozenset({"VALIDATED", "FAILED", "ARCHIVED"}),
    "VALIDATED": frozenset({"PRODUCTION", STAGED, "FAILED", "ARCHIVED"}),
    "PRODUCTION": frozenset({"ARCHIVED"}),
    STAGED: frozenset({"PRODUCTION", "ARCHIVED"}),
    "FAILED": frozenset({"ARCHIVED"}),
}
#: States that are never re-promotable.
RETIRED = frozenset({"ARCHIVED"})


class RegistryError(ValueError):
    """Base class for registry state failures.

    Subclasses ``ValueError`` so the ``except ValueError`` in
    ``app/api/v1/models.py`` keeps turning these into the engine's error
    envelope instead of an unhandled 500.
    """


class UnknownModel(RegistryError):
    """No ``ml_models`` row with that id."""


class UnknownVersion(RegistryError):
    """No ``model_versions`` row for that id under that model."""


class InvalidTransition(RegistryError):
    """The requested status change is not legal from the current status."""


class ArtifactError(RegistryError):
    """The artifact could not be written, or its path does not resolve to a file."""


def _session(db_session):
    if db_session is None:
        from app.database.connection import SessionLocal

        db = SessionLocal()
        return db, True
    return db_session, False


def _require_model(db, model_id: int):
    from app.database.models import MLModel

    model = db.query(MLModel).filter_by(id=model_id).first()
    if not model:
        raise UnknownModel(f"model {model_id} not found")
    return model


def create_model(name: str, model_type: str, db_session=None) -> Dict[str, Any]:
    from app.database.models import MLModel

    db, own = _session(db_session)
    try:
        row = db.query(MLModel).filter_by(name=name).first()
        if not row:
            row = MLModel(name=name, model_type=model_type, status="DRAFT")
            db.add(row)
            db.commit()
            db.refresh(row)
        return {"id": row.id, "name": row.name, "model_type": row.model_type, "status": row.status}
    finally:
        if own:
            db.close()


def _next_version(db, model_id: int) -> str:
    """Next ``v<n>`` for this model, counting the highest existing suffix.

    A plain ``count() + 1`` reused a number as soon as a version was deleted, and
    the unique-name style of the artifact file would then be overwritten by an
    unrelated model state.
    """
    from app.database.models import ModelVersion

    existing = [v.version for v in
                db.query(ModelVersion.version).filter_by(model_id=model_id).all()]
    highest = 0
    for raw in existing:
        text = str(raw or "")
        if text.startswith("v") and text[1:].isdigit():
            highest = max(highest, int(text[1:]))
    return f"v{highest + 1}"


def create_version(model_id: int, metrics: Dict, artifact: Any, version: Optional[str] = None, db_session=None) -> Dict[str, Any]:
    from app.database.models import ModelVersion

    db, own = _session(db_session)
    try:
        _require_model(db, model_id)
        ver = version or _next_version(db, model_id)
        settings.model_path.mkdir(parents=True, exist_ok=True)
        apath = settings.model_path / f"model_{model_id}_{ver}.joblib"
        try:
            joblib.dump(artifact, apath)
        except Exception as exc:
            # Previously this wrote "{}" and still stored the row as VALIDATED,
            # so a version that could never be loaded looked trainable. The row
            # is only written once a real artifact is on disk.
            raise ArtifactError(
                f"could not write artifact for model {model_id} version {ver}: "
                f"{type(exc).__name__}") from exc
        if not apath.is_file() or apath.stat().st_size == 0:
            raise ArtifactError(
                f"artifact for model {model_id} version {ver} is missing at {apath}")
        row = ModelVersion(model_id=model_id, version=ver, artifact_path=str(apath),
                           metrics=metrics or {}, status="VALIDATED")
        db.add(row)
        db.commit()
        db.refresh(row)
        return {"id": row.id, "version": row.version, "artifact_path": row.artifact_path,
                "metrics": row.metrics, "status": row.status}
    finally:
        if own:
            db.close()


def record_training_run(model_id, version_id, model_type, params, metrics, status="done", db_session=None):
    from app.database.models import TrainingRun

    db, own = _session(db_session)
    try:
        r = TrainingRun(model_id=model_id, version_id=version_id, model_type=model_type,
                        params=params or {}, metrics=metrics or {}, status=status)
        db.add(r)
        db.commit()
        db.refresh(r)
        return {"id": r.id, "model_id": r.model_id, "version_id": r.version_id,
                "model_type": r.model_type, "status": r.status}
    finally:
        if own:
            db.close()


def promote(model_id: int, version_id: int, to_status: str, db_session=None) -> Dict[str, Any]:
    from app.database.models import MLModel, ModelVersion

    target = str(to_status or "").strip().upper()
    if target not in PROMOTABLE_STATUSES:
        raise InvalidTransition(
            f"unsupported to_status {to_status!r}; expected one of: "
            + ", ".join(sorted(PROMOTABLE_STATUSES)))

    db, own = _session(db_session)
    try:
        model = _require_model(db, model_id)
        v = db.query(ModelVersion).filter_by(id=version_id, model_id=model_id).first()
        if not v:
            raise UnknownVersion(f"version {version_id} not found for model {model_id}")
        if v.status == target:
            return {"model_id": model_id, "version_id": version_id, "status": target}
        if v.status in RETIRED:
            raise InvalidTransition(
                f"version {version_id} is {v.status} and cannot be promoted to {target}")
        allowed = TRANSITIONS.get(v.status, frozenset())
        if target not in allowed:
            raise InvalidTransition(
                f"version {version_id} cannot move from {v.status} to {target}; "
                f"allowed: {', '.join(sorted(allowed)) or 'none'}")

        previous = model.production_version_id
        v.status = target
        if target == "PRODUCTION":
            # Only one version serves predictions, so promoting a new one retires
            # the version that was serving. Without this two rows both read
            # PRODUCTION while only the newest is reachable from the model row.
            db.query(ModelVersion).filter(
                ModelVersion.model_id == model_id,
                ModelVersion.status == "PRODUCTION",
                ModelVersion.id != version_id,
            ).update({"status": "ARCHIVED"}, synchronize_session=False)
            model.status = "PRODUCTION"
            model.production_version_id = version_id
        elif target == "ARCHIVED" and previous == version_id:
            model.production_version_id = None
            if model.status == "PRODUCTION":
                model.status = "ARCHIVED"
        db.commit()
        return {"model_id": model_id, "version_id": version_id, "status": target}
    finally:
        if own:
            db.close()


def load_production(name: str, db_session=None, strict: bool = False) -> Any:
    """Return the artifact for a model's production version.

    ``None`` means "this model has nothing usable to serve", which is what the
    churn predict endpoint reports as a 404. A version that *is* the production
    version but whose artifact is missing or corrupt is a different condition --
    a broken deployment, not an untrained model -- so it is logged, and raised as
    :class:`ArtifactError` when ``strict`` is set.
    """
    from app.database.models import MLModel, ModelVersion

    db, own = _session(db_session)
    try:
        m = db.query(MLModel).filter_by(name=name).first()
        if not m:
            return None
        if m.production_version_id:
            v = db.query(ModelVersion).filter_by(
                id=m.production_version_id, model_id=m.id).first()
        else:
            # Fallback: newest version that is still in play. This used to ignore
            # status entirely, so it happily loaded an ARCHIVED artifact.
            v = (db.query(ModelVersion)
                 .filter(ModelVersion.model_id == m.id,
                         ModelVersion.status.notin_(sorted(RETIRED)))
                 .order_by(ModelVersion.id.desc()).first())
        if not v:
            return None
        path = Path(v.artifact_path or "")
        if not path.is_file():
            msg = (f"model {name!r} version {v.version} is recorded as "
                   f"{v.status} but its artifact is missing at {path}")
            log.error(msg)
            if strict:
                raise ArtifactError(msg)
            return None
        try:
            return joblib.load(path)
        except Exception as exc:
            msg = (f"model {name!r} version {v.version} artifact at {path} "
                   f"could not be loaded: {type(exc).__name__}")
            log.error(msg)
            if strict:
                raise ArtifactError(msg) from exc
            return None
    finally:
        if own:
            db.close()
