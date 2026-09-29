"""Pytest fixtures: in-memory app, real in-memory SQLite warehouse, tmp CSV
fixtures (malformed/duplicates/missing/empty).

Additive by design. Every fixture that was here before is still here, with the
same name and the same contract, because the other test modules in this
directory depend on them. What is new is the database: the suite never had one,
so every test that touched the warehouse failed with "no such table".
"""
from __future__ import annotations

import os
import shutil
import tempfile
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

# A deliberately fake credential. It must NOT collide with
# app/core/security.py::_PLACEHOLDER_SECRETS: a placeholder is treated as "not
# configured" and every guarded endpoint then answers 401, so the whole suite
# would fail on a value that looks like a key but is not one.
TEST_SERVICE_API_KEY = "pytest-not-a-real-service-key"

# Shared-cache in-memory SQLite. A bare "sqlite://" is served by
# SingletonThreadPool, which hands every thread its OWN empty database, so a
# schema created on the pytest thread is invisible to the anyio worker thread
# TestClient runs the routes in ("no such table: raw_uploads"). cache=shared
# makes every connection in this process see the one in-memory database, and
# still leaves nothing on disk.
TEST_DATABASE_URL = (
    "sqlite:///file:aidata_pytest_warehouse?mode=memory&cache=shared&uri=true"
)

# POST /imports/upload writes under settings.storage_path; keep that out of the
# working tree.
_TEST_STORAGE = tempfile.mkdtemp(prefix="aidata-pytest-storage-")

os.environ.setdefault("APP_ENV", "dev")
os.environ.setdefault("SERVICE_API_KEY", TEST_SERVICE_API_KEY)
os.environ.setdefault("DATABASE_URL", TEST_DATABASE_URL)
os.environ.setdefault("STORAGE_PATH", _TEST_STORAGE)


def _reset_schema(engine) -> None:
    """Empty every table without dropping the schema.

    Reversed sorted order puts children before parents, so this stays correct on
    a Postgres that actually enforces the foreign keys the SQLite test database
    does not.
    """
    from sqlalchemy import delete

    from app.database.connection import Base

    with engine.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            conn.execute(delete(table))


@pytest.fixture(scope="session", autouse=True)
def _cleanup_test_storage():
    """Remove the temp storage directory at the end of the session."""
    yield
    shutil.rmtree(_TEST_STORAGE, ignore_errors=True)


@pytest.fixture(scope="session")
def warehouse():
    """The real schema, created with ``Base.metadata.create_all``.

    This is a real database, not a mock: every test that goes near the warehouse
    goes through it. Skipped unless the engine is SQLite, so a developer whose
    DATABASE_URL points at a real Postgres can never have that database's schema
    created or emptied by a test run.
    """
    import app.database.models  # noqa: F401  (import registers every mapper on Base)
    from app.database.connection import Base, engine

    if engine.dialect.name != "sqlite":
        pytest.skip(
            f"the warehouse fixture needs a SQLite DATABASE_URL; "
            f"got {engine.dialect.name} ({engine.url.render_as_string(hide_password=True)})"
        )
    Base.metadata.create_all(engine)
    return engine


@pytest.fixture()
def clean_warehouse(warehouse):
    """The warehouse, emptied. Use before a test that writes rows through the API."""
    _reset_schema(warehouse)
    return warehouse


@pytest.fixture()
def db_session(warehouse):
    """A real session on an emptied warehouse, rolled back and closed afterwards.

    Request this instead of building your own session so a test cannot pass on
    rows another test left behind.
    """
    from app.database.connection import SessionLocal

    _reset_schema(warehouse)
    session = SessionLocal()
    try:
        yield session
    finally:
        session.rollback()
        session.close()


@pytest.fixture(scope="session")
def client(warehouse):
    from app.main import create_app

    app = create_app()
    with TestClient(app) as c:
        yield c


@pytest.fixture(scope="session")
def service_headers() -> dict:
    """Auth headers for guarded endpoints.

    The header name is the one the engine actually resolves (SERVICE_API_KEY_HEADER
    with a default of X-Service-Key), not a literal, so a deployment that renames it
    does not silently invalidate every request in this suite. The value is the
    configured key, so a placeholder/blank key makes these tests fail loudly instead
    of passing on a bypass.
    """
    from app.core.config import settings
    from app.core.security import SERVICE_KEY_HEADER

    return {SERVICE_KEY_HEADER: settings.service_api_key}


@pytest.fixture()
def tmp_dir(tmp_path: Path) -> Path:
    return tmp_path


@pytest.fixture()
def sample_csv(tmp_path: Path) -> Path:
    p = tmp_path / "sales.csv"
    p.write_text(
        "Tanggal Transaksi,Nm Customer,Nm Brg,Jml,Harga Jual,Nm Cabang\n"
        "2024-01-01,Budi,Laptop,2,5000000,Jakarta\n"
        "2024-01-02,Ani,Mouse,5,150000,Bandung\n"
        "2024-01-03,Budi,Keyboard,1,400000,Jakarta\n",
        encoding="utf-8",
    )
    return p


@pytest.fixture()
def malformed_csv(tmp_path: Path) -> Path:
    p = tmp_path / "malformed.csv"
    p.write_text("a,b,c\n1,2\n\"unclosed,3,4\n5,6,7,8,9\n", encoding="utf-8")
    return p


@pytest.fixture()
def duplicates_csv(tmp_path: Path) -> Path:
    p = tmp_path / "duplicates.csv"
    p.write_text("a,b\n1,2\n1,2\n1,2\n3,4\n", encoding="utf-8")
    return p


@pytest.fixture()
def missing_csv(tmp_path: Path) -> Path:
    p = tmp_path / "missing.csv"
    p.write_text("a,b,c\n1,,3\n,2,\n4,5,6\n", encoding="utf-8")
    return p


@pytest.fixture()
def empty_csv(tmp_path: Path) -> Path:
    p = tmp_path / "empty.csv"
    p.write_text("", encoding="utf-8")
    return p
