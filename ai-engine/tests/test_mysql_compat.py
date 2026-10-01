"""MySQL compatibility guards (static).

Production runs MySQL, but most of the suite runs on SQLite, which accepts
syntax MySQL rejects. These tests scan the shipped source so a
SQLite-only construct can never reach production again:

* ``NULLS LAST`` (``.nullslast()``) broke every AI warehouse frame on MySQL
  with a syntax error (found live 2026-10-01). Ordering must use portable
  constructs instead.
"""
from __future__ import annotations

from pathlib import Path

APP_ROOT = Path(__file__).resolve().parents[1] / "app"

# SQLite-only SQLAlchemy constructs rejected by MySQL.
BANNED = (
    "nullslast",
    "nullsfirst",
    "NULLS LAST",
    "NULLS FIRST",
)


def _python_files():
    return [p for p in APP_ROOT.rglob("*.py") if "__pycache__" not in p.parts]


def test_no_sqlite_only_ordering_constructs():
    offenders = []
    for path in _python_files():
        for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            stripped = line.split("#", 1)[0]
            for banned in BANNED:
                if banned in stripped:
                    offenders.append(f"{path.relative_to(APP_ROOT)}:{lineno}: {stripped.strip()}")
    assert offenders == [], "SQLite-only SQL constructs found:\n" + "\n".join(offenders)


def test_frame_ordering_compiles_clean_on_mysql():
    """The portable nulls-last pattern used by the AI warehouse frames
    compiles on a MySQL dialect with no NULLS LAST clause."""
    from sqlalchemy import select
    from sqlalchemy.dialects import mysql

    from app.database.models import FactSales

    query = (
        select(FactSales.id)
        .order_by(
            FactSales.transaction_date.is_not(None).desc(),
            FactSales.transaction_date.desc(),
        )
        .limit(10)
    )
    sql = str(query.compile(dialect=mysql.dialect(), compile_kwargs={"literal_binds": True}))
    assert "NULLS LAST" not in sql.upper()
    assert "NULLS FIRST" not in sql.upper()
