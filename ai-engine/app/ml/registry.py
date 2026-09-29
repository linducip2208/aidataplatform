"""Model registry service: create/version/promote/load production model."""
from __future__ import annotations

import logging
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

import joblib
from sqlalchemy import DateTime, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.config import settings
from app.database.connection import Base

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


def create_version(model_id: int, metrics: Dict, artifact: Any, version: Optional[str] = None, db_session=None,
                   dataset_version: Optional[str] = None,
                   features: Optional[list] = None,
                   params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
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
        # Provenance travels with the version row under reserved keys so that
        # model_detail() can surface dataset version / features / params without
        # a schema change to model_versions. They are stripped back out there
        # and never merge into the trainer-reported metrics a caller compares.
        stored_metrics = dict(metrics or {})
        if dataset_version is not None:
            stored_metrics["_dataset_version"] = str(dataset_version)
        if features is not None:
            stored_metrics["_features"] = [str(f) for f in features]
        if params is not None:
            stored_metrics["_params"] = dict(params)
        row = ModelVersion(model_id=model_id, version=ver, artifact_path=str(apath),
                           metrics=stored_metrics, status="VALIDATED")
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
        from_status = v.status
        retired_predecessors: List[int] = []
        if target == "PRODUCTION":
            # Collected before the bulk update below, so the audit trail can
            # name every version this promotion retires. Without this the
            # predecessor silently flips to ARCHIVED with no lifecycle entry.
            retired_predecessors = [
                row.id for row in db.query(ModelVersion.id).filter(
                    ModelVersion.model_id == model_id,
                    ModelVersion.status == "PRODUCTION",
                    ModelVersion.id != version_id).all()]
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
        _record_lifecycle_event(db, model_id, version_id, from_status, target)
        for old_id in retired_predecessors:
            _record_lifecycle_event(db, model_id, old_id, "PRODUCTION", "ARCHIVED",
                                    note=f"superseded by version {version_id}")
            _record_deployment_event(db, model_id, old_id, "RETIRED",
                                     note=f"superseded by version {version_id}")
        if target == "PRODUCTION":
            _record_deployment_event(db, model_id, version_id, "SERVING")
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


# --------------------------------------------------------------------------
# Enterprise: audit-trail events, rollback, deployment status, full metadata.
# --------------------------------------------------------------------------

#: Deployment axis, orthogonal to the lifecycle ``FLOW``. A version's lifecycle
#: says what reviewers decided; its deployment says what is actually serving.
DEPLOYMENT_STATUSES = frozenset({"PENDING", "STAGING", "SERVING", "FAILED", "RETIRED"})

#: Legal deployment changes. An absent entry means the state is terminal.
#: ``FAILED`` may return to ``STAGING`` for an explicit redeploy; nothing may
#: leave ``RETIRED`` except a rollback, which writes its own events.
DEPLOYMENT_TRANSITIONS: Dict[str, frozenset] = {
    "PENDING": frozenset({"STAGING", "FAILED"}),
    "STAGING": frozenset({"SERVING", "FAILED"}),
    "SERVING": frozenset({"RETIRED", "FAILED"}),
    "FAILED": frozenset({"STAGING", "RETIRED"}),
}

#: Event types written to ``ml_model_events``.
EVENT_LIFECYCLE = "lifecycle"
EVENT_DEPLOYMENT = "deployment"
EVENT_ROLLBACK = "rollback"


class ModelEventConflict(RegistryError):
    """A deployment-status change that is not legal from the current status."""


class ModelEvent(Base):
    """One audit-trail entry for a model version.

    DDL spec for master (single alembic revision at integration)::

        CREATE TABLE ml_model_events (
            id SERIAL PRIMARY KEY,
            model_id INTEGER NOT NULL REFERENCES ml_models(id),
            version_id INTEGER NULL REFERENCES model_versions(id),
            event_type VARCHAR(32) NOT NULL DEFAULT 'lifecycle',
            from_status VARCHAR(32) NOT NULL DEFAULT '',
            to_status VARCHAR(32) NOT NULL DEFAULT '',
            actor VARCHAR(128) NOT NULL DEFAULT '',
            note VARCHAR(1024) NOT NULL DEFAULT '',
            created_at TIMESTAMPTZ NULL
        );
        CREATE INDEX ix_ml_model_events_model ON ml_model_events (model_id);
    """

    __tablename__ = "ml_model_events"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    model_id: Mapped[int] = mapped_column(
        ForeignKey("ml_models.id"), index=True)
    version_id: Mapped[int | None] = mapped_column(
        ForeignKey("model_versions.id"), nullable=True, default=None)
    event_type: Mapped[str] = mapped_column(String(32), default=EVENT_LIFECYCLE)
    from_status: Mapped[str] = mapped_column(String(32), default="")
    to_status: Mapped[str] = mapped_column(String(32), default="")
    actor: Mapped[str] = mapped_column(String(128), default="")
    note: Mapped[str] = mapped_column(String(1024), default="")
    created_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, default=None)


def _event_available(db) -> bool:
    """True when the ``ml_model_events`` table exists in this database.

    The table is created by master's migration at integration; until then the
    lifecycle keeps working and the audit trail degrades to a warning instead
    of failing the promotion itself.
    """
    try:
        from sqlalchemy import inspect as _inspect

        return _inspect(db.get_bind()).has_table(ModelEvent.__tablename__)
    except Exception:
        return False


def record_event(model_id: int, version_id: Optional[int], event_type: str,
                 from_status: str, to_status: str, actor: str = "",
                 note: str = "", db_session=None) -> Optional[Dict[str, Any]]:
    """Append one audit-trail entry. Returns the entry, or ``None`` when the
    events table has not been migrated yet (the caller keeps working)."""
    db, own = _session(db_session)
    try:
        if not _event_available(db):
            log.warning("ml_model_events table is missing; skipping %s event "
                        "for model %s", event_type, model_id)
            return None
        _require_model(db, int(model_id))
        row = ModelEvent(
            model_id=int(model_id),
            version_id=int(version_id) if version_id else None,
            event_type=str(event_type or EVENT_LIFECYCLE),
            from_status=str(from_status or ""), to_status=str(to_status or ""),
            actor=str(actor or "")[:128], note=str(note or "")[:1024],
            created_at=datetime.now(timezone.utc))
        db.add(row)
        db.commit()
        db.refresh(row)
        return {"id": row.id, "model_id": row.model_id, "version_id": row.version_id,
                "event_type": row.event_type, "from_status": row.from_status,
                "to_status": row.to_status, "actor": row.actor, "note": row.note,
                "created_at": row.created_at.isoformat() if row.created_at else None}
    finally:
        if own:
            db.close()


def _record_lifecycle_event(db, model_id: int, version_id: int,
                            from_status: str, to_status: str,
                            actor: str = "", note: str = "") -> None:
    """Best-effort lifecycle entry inside :func:`promote` / :func:`rollback`.

    Never raises: the status change it documents has already committed, and a
    missing events table must not turn a successful promotion into a 500.
    """
    try:
        if not _event_available(db):
            return
        db.add(ModelEvent(
            model_id=int(model_id), version_id=int(version_id),
            event_type=EVENT_LIFECYCLE,
            from_status=str(from_status or ""), to_status=str(to_status or ""),
            actor=str(actor or "")[:128], note=str(note or "")[:1024],
            created_at=datetime.now(timezone.utc)))
        db.commit()
    except Exception as exc:
        log.warning("could not record lifecycle event for model %s version %s: %s",
                    model_id, version_id, type(exc).__name__)
        try:
            db.rollback()
        except Exception:
            pass


def _record_deployment_event(db, model_id: int, version_id: int,
                             to_status: str, actor: str = "",
                             note: str = "") -> None:
    """Best-effort deployment entry; resolves the current deployment status
    from the event trail so the ``from_status`` is always the truth."""
    try:
        if not _event_available(db):
            return
        current = _current_deployment_status(db, model_id, version_id)
        db.add(ModelEvent(
            model_id=int(model_id), version_id=int(version_id),
            event_type=EVENT_DEPLOYMENT,
            from_status=current, to_status=str(to_status or ""),
            actor=str(actor or "")[:128], note=str(note or "")[:1024],
            created_at=datetime.now(timezone.utc)))
        db.commit()
    except Exception as exc:
        log.warning("could not record deployment event for model %s version %s: %s",
                    model_id, version_id, type(exc).__name__)
        try:
            db.rollback()
        except Exception:
            pass


def _current_deployment_status(db, model_id: int, version_id: int) -> str:
    """Latest deployment ``to_status`` for a version, ``PENDING`` when the
    trail is empty. A version that was never deployed is pending — an explicit
    starting state, not a guess."""
    try:
        last = (db.query(ModelEvent)
                .filter_by(model_id=int(model_id), version_id=int(version_id),
                           event_type=EVENT_DEPLOYMENT)
                .order_by(ModelEvent.id.desc()).first())
        if last and last.to_status in DEPLOYMENT_STATUSES:
            return last.to_status
    except Exception:
        pass
    return "PENDING"


def deployment_status(model_id: int, version_id: int, db_session=None) -> str:
    """Public read of a version's deployment status (``PENDING`` by default)."""
    from app.database.models import ModelVersion

    db, own = _session(db_session)
    try:
        v = db.query(ModelVersion).filter_by(
            id=int(version_id), model_id=int(model_id)).first()
        if not v:
            raise UnknownVersion(
                f"version {version_id} not found for model {model_id}")
        return _current_deployment_status(db, int(model_id), int(version_id))
    finally:
        if own:
            db.close()


def set_deployment_status(model_id: int, version_id: int, to_status: str,
                          actor: str = "", note: str = "",
                          db_session=None) -> Dict[str, Any]:
    """Move a version along the deployment axis with an audit-trail entry.

    Only :data:`DEPLOYMENT_TRANSITIONS` moves are legal; anything else raises
    :class:`ModelEventConflict` naming the allowed targets.
    """
    from app.database.models import ModelVersion

    target = str(to_status or "").strip().upper()
    if target not in DEPLOYMENT_STATUSES:
        raise ModelEventConflict(
            f"unsupported deployment status {to_status!r}; expected one of: "
            + ", ".join(sorted(DEPLOYMENT_STATUSES)))
    db, own = _session(db_session)
    try:
        _require_model(db, int(model_id))
        v = db.query(ModelVersion).filter_by(
            id=int(version_id), model_id=int(model_id)).first()
        if not v:
            raise UnknownVersion(
                f"version {version_id} not found for model {model_id}")
        current = _current_deployment_status(db, int(model_id), int(version_id))
        if current == target:
            return {"model_id": int(model_id), "version_id": int(version_id),
                    "deployment_status": target}
        allowed = DEPLOYMENT_TRANSITIONS.get(current, frozenset())
        if target not in allowed:
            raise ModelEventConflict(
                f"version {version_id} deployment cannot move from {current} "
                f"to {target}; allowed: {', '.join(sorted(allowed)) or 'none'}")
        entry = record_event(int(model_id), int(version_id), EVENT_DEPLOYMENT,
                             current, target, actor, note, db)
        return {"model_id": int(model_id), "version_id": int(version_id),
                "deployment_status": target, "event_id": (entry or {}).get("id")}
    finally:
        if own:
            db.close()


def list_events(model_id: int, db_session=None) -> Dict[str, Any]:
    """Full audit trail for a model, oldest first, plus the current
    deployment status of every version. An empty trail is ``[]`` with the
    model still identified — never a 404 for a model that exists."""
    from app.database.models import ModelVersion

    db, own = _session(db_session)
    try:
        model = _require_model(db, int(model_id))
        events: List[Dict[str, Any]] = []
        if _event_available(db):
            rows = (db.query(ModelEvent).filter_by(model_id=int(model_id))
                    .order_by(ModelEvent.id.asc()).limit(1000).all())
            events = [{"id": e.id, "version_id": e.version_id,
                       "event_type": e.event_type, "from_status": e.from_status,
                       "to_status": e.to_status, "actor": e.actor, "note": e.note,
                       "created_at": e.created_at.isoformat() if e.created_at else None}
                      for e in rows]
        versions = (db.query(ModelVersion).filter_by(model_id=int(model_id))
                    .order_by(ModelVersion.id.desc()).all())
        deployment = {str(v.id): _current_deployment_status(db, int(model_id), v.id)
                      for v in versions}
        return {"model_id": model.id, "model_name": model.name,
                "events": events, "deployment_status": deployment}
    finally:
        if own:
            db.close()


def rollback(model_id: int, db_session=None, actor: str = "",
             note: str = "") -> Dict[str, Any]:
    """Re-activate the most recent pre-production version.

    The version currently serving (``ml_models.production_version_id``) is
    archived and the newest ``ARCHIVED`` version takes its place as
    ``PRODUCTION``. ``ARCHIVED`` is terminal for :func:`promote` by design, so
    this is the only path back — and it writes both a ``rollback`` lifecycle
    entry and the matching deployment transitions (old ``SERVING`` → retired,
    new → ``SERVING``). With no serving version, or no archived predecessor,
    the failure is explicit, never a silent re-promote of the same version.
    """
    from app.database.models import MLModel, ModelVersion

    db, own = _session(db_session)
    try:
        model = _require_model(db, int(model_id))
        current = None
        if model.production_version_id:
            current = db.query(ModelVersion).filter_by(
                id=model.production_version_id, model_id=int(model_id)).first()
        if current is None:
            current = (db.query(ModelVersion)
                       .filter_by(model_id=int(model_id), status="PRODUCTION")
                       .order_by(ModelVersion.id.desc()).first())
        if current is None:
            raise InvalidTransition(
                f"model {model_id} has no serving version to roll back from")
        target = (db.query(ModelVersion)
                  .filter(ModelVersion.model_id == int(model_id),
                          ModelVersion.status == "ARCHIVED",
                          ModelVersion.id != current.id)
                  .order_by(ModelVersion.id.desc()).first())
        if target is None:
            raise InvalidTransition(
                f"model {model_id} has no archived predecessor to restore; "
                f"version {current.id} stays in PRODUCTION")
        from_status = current.status
        current.status = "ARCHIVED"
        target_from = target.status
        target.status = "PRODUCTION"
        model.status = "PRODUCTION"
        model.production_version_id = target.id
        db.commit()
        _record_lifecycle_event(db, int(model_id), current.id, from_status, "ARCHIVED",
                                actor, note or f"rolled back in favour of version {target.id}")
        _record_lifecycle_event(db, int(model_id), target.id, target_from, "PRODUCTION",
                                actor, note or f"restored by rollback from version {current.id}")
        try:
            if _event_available(db):
                db.add(ModelEvent(
                    model_id=int(model_id), version_id=target.id,
                    event_type=EVENT_ROLLBACK,
                    from_status=str(current.id), to_status=str(target.id),
                    actor=str(actor or "")[:128],
                    note=str(note or f"rollback {current.id} -> {target.id}")[:1024],
                    created_at=datetime.now(timezone.utc)))
                db.commit()
        except Exception as exc:
            log.warning("could not record rollback event for model %s: %s",
                        model_id, type(exc).__name__)
            try:
                db.rollback()
            except Exception:
                pass
        _record_deployment_event(db, int(model_id), current.id, "RETIRED",
                                 actor, "superseded by rollback")
        _record_deployment_event(db, int(model_id), target.id, "SERVING",
                                 actor, "restored by rollback")
        return {"model_id": int(model_id),
                "rolled_back_from": current.id,
                "rolled_back_to": target.id,
                "status": "PRODUCTION"}
    finally:
        if own:
            db.close()


def _clean_metrics(metrics: Any) -> Dict[str, Any]:
    """Trainer-reported metrics without the reserved provenance keys that
    :func:`create_version` stores alongside them."""
    return {k: v for k, v in (dict(metrics or {}).items())
            if not str(k).startswith("_")}


def model_detail(model_id: int, db_session=None) -> Dict[str, Any]:
    """Complete metadata for a model: every version exposes its version string,
    training timestamp, dataset version, feature list, metrics, params,
    artifact path, lifecycle status and deployment status.

    Provenance resolves in order — version-row reserved keys, then the linked
    training run, then ``None``. ``None`` means "not recorded", which is the
    honest answer for versions trained before provenance existed; nothing here
    is back-filled or guessed.
    """
    from app.database.models import MLModel, ModelVersion, TrainingRun

    db, own = _session(db_session)
    try:
        model = _require_model(db, int(model_id))
        versions = (db.query(ModelVersion).filter_by(model_id=int(model_id))
                    .order_by(ModelVersion.id.desc()).all())
        runs = (db.query(TrainingRun).filter_by(model_id=int(model_id))
                .order_by(TrainingRun.id.desc()).all())
        run_by_version: Dict[Any, Any] = {}
        for r in runs:
            if r.version_id is not None and r.version_id not in run_by_version:
                run_by_version[r.version_id] = r
        out_versions = []
        for v in versions:
            stored = dict(v.metrics or {})
            dataset_version = stored.get("_dataset_version")
            features = stored.get("_features")
            params = stored.get("_params")
            linked = run_by_version.get(v.id)
            if dataset_version is None and linked is not None:
                dataset_version = (linked.metrics or {}).get("dataset_version")
                if dataset_version is None:
                    dataset_version = (linked.params or {}).get("dataset_version")
            if features is None and linked is not None:
                features = (linked.metrics or {}).get("features")
                if features is None:
                    features = (linked.params or {}).get("features")
            if params is None and linked is not None:
                params = dict(linked.params or {})
            out_versions.append({
                "id": v.id, "version": v.version, "status": v.status,
                "training_timestamp": v.created_at.isoformat() if v.created_at else None,
                "dataset_version": dataset_version,
                "features": list(features) if isinstance(features, (list, tuple)) else None,
                "metrics": _clean_metrics(stored),
                "params": dict(params) if isinstance(params, dict) else None,
                "artifact_path": v.artifact_path,
                "deployment_status": _current_deployment_status(db, int(model_id), v.id),
            })
        return {"id": model.id, "name": model.name, "model_type": model.model_type,
                "status": model.status,
                "production_version_id": model.production_version_id,
                "versions": out_versions}
    finally:
        if own:
            db.close()
