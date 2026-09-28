"""SQLAlchemy engine / session / Base.

The engine is **synchronous**, and that is a property of the whole codebase, not
a preference: every router annotates its session as ``Session`` and calls
``db.query(...)`` (``AsyncSession`` has no ``.query()``), the Celery tasks and
``app.cli`` are sync, ``api/v1/health.py`` uses ``with engine.connect()`` and
Alembic drives a sync engine. ``DeclarativeBase`` in ``models.py`` is
SQLAlchemy 2.0 *mapping* style and is driver-agnostic, so it does not imply async.

Compose passes ``DATABASE_URL=postgresql+asyncpg://...``. A sync
``create_engine`` cannot use an async driver, so ``app.core.config`` rewrites it
to ``postgresql+psycopg2://`` and prefers ``SYNC_DATABASE_URL`` when present
(the same precedence ``alembic/env.py`` uses). ``_assert_sync_url`` below turns
any future regression into an import-time error instead of a request-time one.
"""
from __future__ import annotations

from contextlib import contextmanager
from typing import Dict, Generator, Iterator

from sqlalchemy import create_engine
from sqlalchemy.engine import Engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.core.config import is_async_url, settings

__all__ = [
    "Base",
    "engine",
    "SessionLocal",
    "get_db",
    "session_scope",
    "dispose_engine",
    "sync_database_url",
]


class Base(DeclarativeBase):
    pass


def sync_database_url() -> str:
    """The resolved, synchronous SQLAlchemy URL (never an async driver)."""
    return settings.database_url


def _assert_sync_url(url: str) -> None:
    if is_async_url(url):
        raise RuntimeError(
            f"async SQLAlchemy driver in {url.split(':', 1)[0]}://... cannot back a "
            "synchronous engine; check DATABASE_URL / SYNC_DATABASE_URL handling in "
            "app/core/config.py"
        )


def _engine_kwargs(url: str) -> Dict[str, object]:
    if url.startswith("sqlite"):
        return {"connect_args": {"check_same_thread": False}}
    return {
        "pool_pre_ping": True,
        "pool_recycle": 1800,
        "pool_size": 10,
        "max_overflow": 20,
    }


_URL = sync_database_url()
_assert_sync_url(_URL)

engine: Engine = create_engine(_URL, **_engine_kwargs(_URL))
SessionLocal = sessionmaker(
    bind=engine, autoflush=False, autocommit=False, expire_on_commit=False
)


def get_db() -> Generator[Session, None, None]:
    """FastAPI dependency yielding a session.

    Commits when the route returns, rolls back if it raises, and always closes.
    Routers that commit their own writes are unaffected: a second commit with
    nothing pending is a no-op, and ``expire_on_commit=False`` keeps loaded
    attributes usable after the session is closed.
    """
    db = SessionLocal()
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


@contextmanager
def session_scope() -> Iterator[Session]:
    """Same commit/rollback/close contract for non-request callers (scripts, workers)."""
    db = SessionLocal()
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


def dispose_engine() -> None:
    engine.dispose()
