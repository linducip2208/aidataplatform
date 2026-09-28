"""Query-driven indexes: close the gaps the mechanical model mirror left, and
drop the duplicate ``fact_sales.transaction_date`` index.

``0001`` was derived from ``app/database/models.py`` one-for-one, so it carries
every ``index=True`` the models declare and nothing the *queries* need. This
revision is the diff between those two things.

Added
-----
``import_job_id`` on ``fact_inventory``, ``fact_purchases`` and ``fact_expenses``:

    app/ingestion/etl.py:294
        session.query(model).filter_by(import_job_id=import_job_id).delete()

``_purge_job_rows`` is the idempotency handle for the whole ETL (see
``docs/data-ingestion.md``): it runs before every load and again on every
re-run, as ``DELETE FROM <fact> WHERE import_job_id = ?``. ``fact_sales``
already carries ``ix_fact_sales_import_job_id`` because its model declared
``index=True``; the other three fact models declare the same column without it,
so the identical delete seq-scans three unbounded, append-only tables. Same
query, same shape -- the gap is purely mechanical.

Dropped
-------
``ix_fact_sales_date``. ``0001`` creates it *and*
``ix_fact_sales_transaction_date`` on the same column (lines 206-207), because
models.py declares both: ``FactSales.transaction_date`` with ``index=True``,
plus a standalone ``Index("ix_fact_sales_date", FactSales.transaction_date)``
at models.py:318. Two btrees over one column is pure write amplification on the
table that grows fastest.

``ix_fact_sales_transaction_date`` is the one kept: it is the name SQLAlchemy
derives from ``index=True`` on the column, so it is the one that survives any
future ``Base.metadata.create_all()``. ``ix_fact_sales_date`` exists only
because of the hand-written ``Index()`` at the bottom of models.py, so dropping
it here is the half of the fix the code owner still has to make in the model.

Deliberately NOT added
----------------------
* ``fact_sales (transaction_date, branch_id)`` and the other date+dimension
  composites. The analytics endpoints *read* like date/branch/customer/product
  filters, but the predicate is applied in pandas after the fetch:
  ``app/ai/tools.py:150`` runs an unfiltered
  ``SELECT ... FROM fact_sales LEFT JOIN dim_* ... LIMIT 5000`` and
  ``app/analytics/sales.py:46 apply_filters`` then subsets the DataFrame. No
  predicate ever reaches the database, so no composite index can help until the
  filter is pushed into the query. That is a query change, not a migration.
* Referencing-side FK indexes (``fact_sales.customer_id``, ``.product_id``,
  ``.branch_id``, ``staging_tables.import_job_id``,
  ``data_quality_reports.import_job_id``, ``alerts.rule_id``,
  ``training_runs.model_id``/``.version_id``). Postgres does not create these
  automatically, but nothing in the codebase deletes or re-keys a parent row, so
  no query scans the child side. They become necessary the moment a dimension
  row is ever deleted or its natural key rewritten.
* An HNSW/IVFFlat index on ``rag_chunks.embedding``. ``app/ai/rag.py:345`` does
  not perform a similarity search: it selects up to ``SCAN_LIMIT`` (2000) chunks
  and scores them in Python, so an ANN index would be dead weight paid on every
  insert and never read. It is warranted only once retrieval becomes
  ``ORDER BY embedding <=> :q LIMIT :k`` -- and the operator class has to match
  that operator (``vector_cosine_ops`` for ``<=>``, ``vector_l2_ops`` for
  ``<->``). A mismatched opclass does not error; it silently returns different
  neighbours.
* ``rag_documents (source, title)`` for ``_find_existing`` (rag.py:207). One row
  per ingest and one lookup per ingest, so the scan is bounded; and ``source``
  is ``varchar(1024)``, so a btree over it can blow past the 2704-byte index
  tuple limit on a long non-ASCII path and fail the INSERT outright.

Revisable: plain ``CREATE INDEX`` / ``DROP INDEX``. Alembic wraps a migration in
``context.begin_transaction()`` (alembic/env.py:63) and Postgres refuses
CONCURRENTLY inside a transaction block, so the concurrent form would require
driving the whole revision under
``op.get_bind().execution_options(isolation_level="AUTOCOMMIT")``, which commits
away the migration's own bookkeeping. The deployment target is a fresh container
running ``alembic upgrade head`` on every boot against an empty database, so
there is no large populated table to keep writeable. Against a populated
production table, run these by hand and take them out of the migration:
``CREATE INDEX CONCURRENTLY ix_fact_inventory_import_job_id ON fact_inventory (import_job_id);``
and ``DROP INDEX CONCURRENTLY IF EXISTS ix_fact_sales_date;``.

Revision ID: 0002
Revises: 0001
Create Date: 2026-09-29
"""
from __future__ import annotations

import logging

import sqlalchemy as sa
from alembic import op

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None

log = logging.getLogger("alembic.runtime.migration")

# (index name, table, columns)
INDEXES: tuple[tuple[str, str, tuple[str, ...]], ...] = (
    ("ix_fact_inventory_import_job_id", "fact_inventory", ("import_job_id",)),
    ("ix_fact_purchases_import_job_id", "fact_purchases", ("import_job_id",)),
    ("ix_fact_expenses_import_job_id", "fact_expenses", ("import_job_id",)),
)

# Redundant indexes: (index name, table, columns it duplicates)
REDUNDANT: tuple[tuple[str, str, tuple[str, ...]], ...] = (
    ("ix_fact_sales_date", "fact_sales", ("transaction_date",)),
)


def _has_table(table: str) -> bool:
    return sa.inspect(op.get_bind()).has_table(table)


def _has_index(table: str, index: str) -> bool:
    return sa.inspect(op.get_bind()).has_index(table, index)


def upgrade() -> None:
    for name, table, columns in INDEXES:
        # Guarded exactly as 0001 does, and for the same reason: a fresh
        # container runs `alembic upgrade head` on every boot, so an unguarded
        # CREATE INDEX raises "relation already exists" on the second boot and
        # takes the whole revision -- and the container -- down with it.
        if not _has_table(table):
            log.info("skip %s: table not present", table)
            continue
        if _has_index(table, name):
            log.info("skip %s: already present", name)
            continue
        op.create_index(name, table, list(columns))
        log.info("created %s on %s", name, table)

    # Dropped last, so fact_sales.transaction_date stays indexed for the whole
    # upgrade: ix_fact_sales_date is redundant, not the only cover.
    for name, table, _ in REDUNDANT:
        if not _has_table(table) or not _has_index(table, name):
            continue
        op.drop_index(name, table_name=table)
        log.info("dropped redundant %s on %s", name, table)


def downgrade() -> None:
    for name, table, columns in REDUNDANT:
        # Restored so downgrade() returns the schema to the exact pre-0002
        # state, which is the state 0001 created -- duplicate and all. Once the
        # standalone Index("ix_fact_sales_date", ...) is removed from models.py,
        # this branch should be deleted rather than left to resurrect a
        # redundant index.
        if not _has_table(table) or _has_index(table, name):
            continue
        op.create_index(name, table, list(columns))
        log.info("restored %s on %s", name, table)

    for name, table, _ in reversed(INDEXES):
        if not _has_table(table) or not _has_index(table, name):
            continue
        op.drop_index(name, table_name=table)
        log.info("dropped %s on %s", name, table)
