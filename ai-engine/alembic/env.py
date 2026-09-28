"""Alembic environment."""
from __future__ import annotations

import os
from logging.config import fileConfig

from alembic import context
from sqlalchemy import create_engine, pool

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

_ASYNC_TO_SYNC = {
    "postgresql+asyncpg": "postgresql+psycopg2",
    "postgresql+psycopg": "postgresql+psycopg2",
    "mysql+aiomysql": "mysql+pymysql",
}


def _sync_url(url: str) -> str:
    """Alembic drives a sync engine, so async driver URLs cannot be used as-is."""
    for async_driver, sync_driver in _ASYNC_TO_SYNC.items():
        prefix = f"{async_driver}://"
        if url.startswith(prefix):
            return f"{sync_driver}{url[len(async_driver):]}"
    return url


def get_url() -> str:
    raw = (
        os.getenv("SYNC_DATABASE_URL")
        or os.getenv("DATABASE_URL")
        or config.get_main_option("sqlalchemy.url")
        or ""
    )
    return _sync_url(raw)


# app.database.connection builds its engine from DATABASE_URL at import time, and the
# service is configured with the asyncpg URL, so the sync URL must be in place first.
url = get_url()
os.environ["DATABASE_URL"] = url

from app.database.connection import Base  # noqa: E402

import app.database.models  # noqa: E402,F401  (register models)

target_metadata = Base.metadata


def run_migrations_offline() -> None:
    context.configure(url=url, target_metadata=target_metadata, literal_binds=True,
                      dialect_opts={"paramstyle": "named"})
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = create_engine(url, poolclass=pool.NullPool)
    with connectable.connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
