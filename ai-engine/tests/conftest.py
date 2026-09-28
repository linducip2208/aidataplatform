"""Pytest fixtures: in-memory app, tmp CSV fixtures (malformed/duplicates/missing/empty)."""
from __future__ import annotations

import os
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

os.environ.setdefault("APP_ENV", "dev")
os.environ.setdefault("DATABASE_URL", "sqlite:///./test.db")
os.environ.setdefault("SERVICE_API_KEY", "test-key")


@pytest.fixture(scope="session")
def client():
    from app.main import create_app

    app = create_app()
    with TestClient(app) as c:
        yield c


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
