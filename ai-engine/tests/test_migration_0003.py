"""Alembic 0003 runs head-to-tail on a fresh database, twice.

The container runs ``alembic upgrade head`` on every boot, so a migration
that works once but fails on the second boot takes the service down. This
runs the real entrypoint path (0001 -> 0003, twice) against a scratch
SQLite file and asserts the product display columns exist.
"""
from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

ENGINE_ROOT = Path(__file__).resolve().parents[1]


def _alembic(db_path: Path) -> subprocess.CompletedProcess:
    env = dict(os.environ)
    env["APP_ENV"] = "dev"
    env["SERVICE_API_KEY"] = "migration-smoke-not-a-key"
    env["SYNC_DATABASE_URL"] = f"sqlite:///{db_path}"
    env["DATABASE_URL"] = f"sqlite:///{db_path}"
    return subprocess.run(
        [sys.executable, "-m", "alembic", "upgrade", "head"],
        cwd=str(ENGINE_ROOT),
        env=env,
        capture_output=True,
        text=True,
        timeout=300,
    )


def test_upgrade_head_twice_and_product_columns_exist(tmp_path):
    db_path = tmp_path / "mig0003.db"

    first = _alembic(db_path)
    assert first.returncode == 0, f"first upgrade failed:\n{first.stdout}\n{first.stderr}"

    second = _alembic(db_path)
    assert second.returncode == 0, f"second boot upgrade failed:\n{second.stdout}\n{second.stderr}"

    import sqlalchemy as sa

    insp = sa.inspect(sa.create_engine(f"sqlite:///{db_path}"))
    cols = {c["name"] for c in insp.get_columns("dim_product")}
    assert "description" in cols
    assert "image_url" in cols
    assert insp.has_table("import_jobs")
