"""Enterprise ingestion tables (Agent 2 / A2 ownership).

Imports the shared ``Base`` from ``app.database.connection`` — this module
never declares its own Base and ``app/database/models.py`` is never edited.

Tables
------
``import_checkpoints``
    Per-chunk ETL progress so a crashed job can RESUME instead of restarting::

        import_checkpoints(job_id, chunk_index, rows_done, state)

    ``state`` is one of ``started`` / ``done`` / ``failed``. ``chunk_index``
    is the zero-based chunk number produced by ``iter_chunks`` for a fixed
    ``chunksize``; ``rows_done`` is the cumulative row count after the chunk
    committed. ``file_hash`` binds the checkpoints to the exact file bytes —
    a re-uploaded (changed) file must not resume stale progress.

``dead_letter_records``
    Failed rows with their reason, retrievable per job::

        dead_letter_records(job_id, row_index, raw JSON, reason)

DDL spec for master (single alembic revision — PostgreSQL dialect)::

    CREATE TABLE import_checkpoints (
        id SERIAL PRIMARY KEY,
        job_id INTEGER NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
        chunk_index INTEGER NOT NULL,
        rows_done INTEGER NOT NULL DEFAULT 0,
        state VARCHAR(16) NOT NULL DEFAULT 'done',
        file_hash VARCHAR(64) NOT NULL DEFAULT '',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        CONSTRAINT uq_import_checkpoint UNIQUE (job_id, chunk_index)
    );
    CREATE INDEX ix_import_checkpoints_job ON import_checkpoints (job_id);

    CREATE TABLE dead_letter_records (
        id SERIAL PRIMARY KEY,
        job_id INTEGER NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
        row_index INTEGER NOT NULL DEFAULT 0,
        chunk_index INTEGER NOT NULL DEFAULT 0,
        raw JSON NOT NULL DEFAULT '{}',
        reason VARCHAR(1024) NOT NULL DEFAULT '',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
    );
    CREATE INDEX ix_dead_letter_job ON dead_letter_records (job_id);

SQLite (tests) uses the same SQLAlchemy metadata; JSON columns degrade to
TEXT-backed JSON automatically.
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from sqlalchemy import JSON, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.database.connection import Base


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class ImportCheckpoint(Base):
    __tablename__ = "import_checkpoints"
    __table_args__ = (UniqueConstraint("job_id", "chunk_index", name="uq_import_checkpoint"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    job_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    chunk_index: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    rows_done: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    state: Mapped[str] = mapped_column(String(16), nullable=False, default="done")
    file_hash: Mapped[str] = mapped_column(String(64), nullable=False, default="")
    created_at: Mapped[datetime] = mapped_column(default=_utcnow)
    updated_at: Mapped[datetime] = mapped_column(default=_utcnow, onupdate=_utcnow)


class DeadLetterRecord(Base):
    __tablename__ = "dead_letter_records"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    job_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    row_index: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    chunk_index: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    raw: Mapped[dict] = mapped_column(JSON, nullable=False, default=dict)
    reason: Mapped[str] = mapped_column(Text, nullable=False, default="")
    created_at: Mapped[datetime] = mapped_column(default=_utcnow)


def ensure_enterprise_tables(db_or_engine: Any) -> None:
    """Create the two enterprise tables when they are missing.

    The shared-cache SQLite test database is created from
    ``app.database.models`` only (see ``tests/conftest.py``); this module is
    imported lazily by the ETL, so ``create_all`` must be re-runnable at job
    time. ``checkfirst=True`` (the default) makes this a metadata probe with
    no writes when the tables already exist.
    """
    try:
        bind = db_or_engine.get_bind() if hasattr(db_or_engine, "get_bind") else db_or_engine
        Base.metadata.create_all(bind, tables=[ImportCheckpoint.__table__, DeadLetterRecord.__table__],
                                 checkfirst=True)
    except Exception:
        pass
