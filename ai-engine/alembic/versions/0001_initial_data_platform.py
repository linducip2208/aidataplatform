"""Initial schema: every table declared in app/database/models.py.

Mirrors the SQLAlchemy models one-for-one (names, types, nullability, indexes,
foreign keys). Tables stay in the default ``public`` schema because the models
declare no schema. Python-side ``default=`` in the models is left to the ORM, so
no server defaults are emitted here.

Revision ID: 0001
Revises:
Create Date: 2026-09-28
"""
from __future__ import annotations

import logging
from typing import NamedTuple

import sqlalchemy as sa
from alembic import op

revision = "0001"
down_revision = None
branch_labels = None
depends_on = None

log = logging.getLogger("alembic.runtime.migration")

try:  # mirrors the optional-pgvector branch in app/database/models.py
    from pgvector.sqlalchemy import Vector  # type: ignore

    _EMBEDDING_TYPE: sa.types.TypeEngine = Vector(1536)
except Exception:  # pragma: no cover - no pgvector: keep sqlite/local stacks working
    _EMBEDDING_TYPE = sa.JSON()


# Plain NamedTuples, not dataclasses: alembic loads version modules without
# registering them in sys.modules, and dataclasses on Python 3.13+ resolve
# string annotations through sys.modules (the KW_ONLY lookup), which crashes
# the import with `AttributeError: 'NoneType' object has no attribute
# '__dict__'`. NamedTuple evaluates no annotations at class creation and is
# immune. Behaviour is identical (immutable struct holders with defaults).
class Index(NamedTuple):
    name: str
    columns: tuple[str, ...]
    unique: bool = False


class Table(NamedTuple):
    name: str
    columns: tuple[sa.Column, ...]
    indexes: tuple[Index, ...] = ()


def _pk() -> sa.Column:
    return sa.Column("id", sa.Integer(), primary_key=True, autoincrement=True)


def _timestamps() -> tuple[sa.Column, ...]:
    return (
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
    )


TABLES: tuple[Table, ...] = (
    # ---------------- ingestion ----------------
    Table(
        "raw_uploads",
        (
            _pk(),
            *_timestamps(),
            sa.Column("filename", sa.String(512), nullable=False),
            sa.Column("stored_path", sa.String(1024), nullable=False),
            sa.Column("size_bytes", sa.BigInteger(), nullable=False),
            sa.Column("mime", sa.String(128), nullable=False),
            sa.Column("checksum_sha256", sa.String(64), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
            sa.Column("row_count", sa.Integer(), nullable=False),
        ),
    ),
    Table(
        "import_jobs",
        (
            _pk(),
            *_timestamps(),
            sa.Column("upload_id", sa.Integer(), sa.ForeignKey("raw_uploads.id"), nullable=True),
            sa.Column("dataset_type", sa.String(64), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
            sa.Column("progress", sa.Float(), nullable=False),
            sa.Column("total_rows", sa.Integer(), nullable=False),
            sa.Column("processed_rows", sa.Integer(), nullable=False),
            sa.Column("error_rows", sa.Integer(), nullable=False),
            sa.Column("mapping", sa.JSON(), nullable=False),
            sa.Column("report", sa.JSON(), nullable=False),
            sa.Column("error_log", sa.JSON(), nullable=False),
        ),
        (Index("ix_import_jobs_upload_id", ("upload_id",)),),
    ),
    Table(
        "staging_tables",
        (
            _pk(),
            *_timestamps(),
            sa.Column("import_job_id", sa.Integer(), sa.ForeignKey("import_jobs.id"), nullable=False),
            sa.Column("table_name", sa.String(128), nullable=False),
            sa.Column("row_count", sa.Integer(), nullable=False),
            sa.Column("columns_meta", sa.JSON(), nullable=False),
        ),
    ),
    Table(
        "mapping_templates",
        (
            _pk(),
            *_timestamps(),
            sa.Column("name", sa.String(128), nullable=False, unique=True),
            sa.Column("dataset_type", sa.String(64), nullable=False),
            sa.Column("mapping", sa.JSON(), nullable=False),
        ),
    ),
    # ---------------- dimensions ----------------
    Table(
        "dim_customer",
        (
            _pk(),
            sa.Column("customer_code", sa.String(64), nullable=False),
            sa.Column("customer_name", sa.String(256), nullable=False),
            sa.Column("segment", sa.String(64), nullable=False),
            sa.Column("city", sa.String(128), nullable=False),
            sa.Column("extra", sa.JSON(), nullable=False),
        ),
        (Index("ix_dim_customer_customer_code", ("customer_code",), unique=True),),
    ),
    Table(
        "dim_product",
        (
            _pk(),
            sa.Column("product_code", sa.String(64), nullable=False),
            sa.Column("product_name", sa.String(256), nullable=False),
            sa.Column("category", sa.String(128), nullable=False),
            sa.Column("unit", sa.String(32), nullable=False),
            sa.Column("cost_price", sa.Float(), nullable=False),
            sa.Column("selling_price", sa.Float(), nullable=False),
        ),
        (Index("ix_dim_product_product_code", ("product_code",), unique=True),),
    ),
    Table(
        "dim_branch",
        (
            _pk(),
            sa.Column("branch_code", sa.String(64), nullable=False),
            sa.Column("branch_name", sa.String(256), nullable=False),
            sa.Column("city", sa.String(128), nullable=False),
        ),
        (Index("ix_dim_branch_branch_code", ("branch_code",), unique=True),),
    ),
    Table(
        "dim_supplier",
        (
            _pk(),
            sa.Column("supplier_code", sa.String(64), nullable=False),
            sa.Column("supplier_name", sa.String(256), nullable=False),
        ),
        (Index("ix_dim_supplier_supplier_code", ("supplier_code",), unique=True),),
    ),
    Table(
        "dim_warehouse",
        (
            _pk(),
            sa.Column("warehouse_code", sa.String(64), nullable=False),
            sa.Column("warehouse_name", sa.String(256), nullable=False),
        ),
        (Index("ix_dim_warehouse_warehouse_code", ("warehouse_code",), unique=True),),
    ),
    Table(
        # date_key is a natural key, so it must not become a SERIAL.
        "dim_date",
        (
            sa.Column("date_key", sa.Integer(), primary_key=True, autoincrement=False),
            sa.Column("full_date", sa.Date(), nullable=True),
            sa.Column("year", sa.Integer(), nullable=False),
            sa.Column("month", sa.Integer(), nullable=False),
            sa.Column("day", sa.Integer(), nullable=False),
            sa.Column("weekday", sa.Integer(), nullable=False),
        ),
    ),
    Table(
        "dim_department",
        (
            _pk(),
            sa.Column("dept_code", sa.String(64), nullable=False),
            sa.Column("dept_name", sa.String(256), nullable=False),
        ),
        (Index("ix_dim_department_dept_code", ("dept_code",), unique=True),),
    ),
    # ---------------- facts ----------------
    Table(
        "fact_sales",
        (
            _pk(),
            sa.Column("transaction_date", sa.Date(), nullable=True),
            sa.Column("customer_id", sa.Integer(), sa.ForeignKey("dim_customer.id"), nullable=True),
            sa.Column("product_id", sa.Integer(), sa.ForeignKey("dim_product.id"), nullable=True),
            sa.Column("branch_id", sa.Integer(), sa.ForeignKey("dim_branch.id"), nullable=True),
            sa.Column("quantity", sa.Float(), nullable=False),
            sa.Column("selling_price", sa.Float(), nullable=False),
            sa.Column("discount", sa.Float(), nullable=False),
            sa.Column("revenue", sa.Float(), nullable=False),
            sa.Column("import_job_id", sa.Integer(), nullable=True),
        ),
        (
            Index("ix_fact_sales_transaction_date", ("transaction_date",)),
            Index("ix_fact_sales_date", ("transaction_date",)),
            Index("ix_fact_sales_import_job_id", ("import_job_id",)),
        ),
    ),
    Table(
        "fact_inventory",
        (
            _pk(),
            sa.Column("snapshot_date", sa.Date(), nullable=True),
            sa.Column("product_id", sa.Integer(), sa.ForeignKey("dim_product.id"), nullable=True),
            sa.Column("warehouse_id", sa.Integer(), sa.ForeignKey("dim_warehouse.id"), nullable=True),
            sa.Column("stock_qty", sa.Float(), nullable=False),
            sa.Column("import_job_id", sa.Integer(), nullable=True),
        ),
        (Index("ix_fact_inventory_snapshot_date", ("snapshot_date",)),),
    ),
    Table(
        "fact_purchases",
        (
            _pk(),
            sa.Column("purchase_date", sa.Date(), nullable=True),
            sa.Column("supplier_id", sa.Integer(), sa.ForeignKey("dim_supplier.id"), nullable=True),
            sa.Column("product_id", sa.Integer(), sa.ForeignKey("dim_product.id"), nullable=True),
            sa.Column("quantity", sa.Float(), nullable=False),
            sa.Column("cost", sa.Float(), nullable=False),
            sa.Column("import_job_id", sa.Integer(), nullable=True),
        ),
        (Index("ix_fact_purchases_purchase_date", ("purchase_date",)),),
    ),
    Table(
        "fact_expenses",
        (
            _pk(),
            sa.Column("expense_date", sa.Date(), nullable=True),
            sa.Column("department_id", sa.Integer(), sa.ForeignKey("dim_department.id"), nullable=True),
            sa.Column("amount", sa.Float(), nullable=False),
            sa.Column("category", sa.String(128), nullable=False),
            sa.Column("import_job_id", sa.Integer(), nullable=True),
        ),
        (Index("ix_fact_expenses_expense_date", ("expense_date",)),),
    ),
    # ---------------- ML registry ----------------
    Table(
        "ml_models",
        (
            _pk(),
            *_timestamps(),
            sa.Column("name", sa.String(128), nullable=False),
            sa.Column("model_type", sa.String(64), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
            sa.Column("production_version_id", sa.Integer(), nullable=True),
        ),
        (Index("ix_ml_models_name", ("name",), unique=True),),
    ),
    Table(
        "model_versions",
        (
            _pk(),
            *_timestamps(),
            sa.Column("model_id", sa.Integer(), sa.ForeignKey("ml_models.id"), nullable=False),
            sa.Column("version", sa.String(32), nullable=False),
            sa.Column("artifact_path", sa.String(1024), nullable=False),
            sa.Column("metrics", sa.JSON(), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
        ),
        (Index("ix_model_versions_model_id", ("model_id",)),),
    ),
    Table(
        "training_runs",
        (
            _pk(),
            *_timestamps(),
            sa.Column("model_id", sa.Integer(), sa.ForeignKey("ml_models.id"), nullable=True),
            sa.Column("version_id", sa.Integer(), sa.ForeignKey("model_versions.id"), nullable=True),
            sa.Column("model_type", sa.String(64), nullable=False),
            sa.Column("params", sa.JSON(), nullable=False),
            sa.Column("metrics", sa.JSON(), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
        ),
    ),
    Table(
        "prediction_runs",
        (
            _pk(),
            *_timestamps(),
            sa.Column("model_id", sa.Integer(), nullable=True),
            sa.Column("model_type", sa.String(64), nullable=False),
            sa.Column("input_summary", sa.JSON(), nullable=False),
            sa.Column("output_summary", sa.JSON(), nullable=False),
        ),
    ),
    # ---------------- AI ----------------
    Table(
        "ai_conversations",
        (
            _pk(),
            *_timestamps(),
            sa.Column("title", sa.String(256), nullable=False),
            sa.Column("meta", sa.JSON(), nullable=False),
        ),
    ),
    Table(
        "ai_messages",
        (
            _pk(),
            *_timestamps(),
            sa.Column("conversation_id", sa.Integer(), sa.ForeignKey("ai_conversations.id"), nullable=False),
            sa.Column("role", sa.String(32), nullable=False),
            sa.Column("content", sa.Text(), nullable=False),
            sa.Column("evidence", sa.JSON(), nullable=False),
        ),
        (Index("ix_ai_messages_conversation_id", ("conversation_id",)),),
    ),
    Table(
        "rag_documents",
        (
            _pk(),
            *_timestamps(),
            sa.Column("source", sa.String(1024), nullable=False),
            sa.Column("title", sa.String(512), nullable=False),
            sa.Column("doc_type", sa.String(32), nullable=False),
            sa.Column("meta", sa.JSON(), nullable=False),
        ),
    ),
    Table(
        "rag_chunks",
        (
            _pk(),
            *_timestamps(),
            sa.Column("document_id", sa.Integer(), sa.ForeignKey("rag_documents.id"), nullable=False),
            sa.Column("chunk_index", sa.Integer(), nullable=False),
            sa.Column("content", sa.Text(), nullable=False),
            sa.Column("embedding", _EMBEDDING_TYPE, nullable=True),
            sa.Column("meta", sa.JSON(), nullable=False),
        ),
        (Index("ix_rag_chunks_document_id", ("document_id",)),),
    ),
    # ---------------- alerts / audit / quality ----------------
    Table(
        "alert_rules",
        (
            _pk(),
            *_timestamps(),
            sa.Column("name", sa.String(128), nullable=False),
            sa.Column("metric", sa.String(64), nullable=False),
            sa.Column("condition", sa.String(16), nullable=False),
            sa.Column("threshold", sa.Float(), nullable=False),
            sa.Column("is_active", sa.Boolean(), nullable=False),
        ),
    ),
    Table(
        "alerts",
        (
            _pk(),
            *_timestamps(),
            sa.Column("rule_id", sa.Integer(), sa.ForeignKey("alert_rules.id"), nullable=True),
            sa.Column("severity", sa.String(16), nullable=False),
            sa.Column("message", sa.Text(), nullable=False),
            sa.Column("status", sa.String(32), nullable=False),
        ),
    ),
    Table(
        "alert_events",
        (
            _pk(),
            *_timestamps(),
            sa.Column("alert_id", sa.Integer(), sa.ForeignKey("alerts.id"), nullable=False),
            sa.Column("event_type", sa.String(64), nullable=False),
            sa.Column("payload", sa.JSON(), nullable=False),
        ),
        (Index("ix_alert_events_alert_id", ("alert_id",)),),
    ),
    Table(
        # `audit_logs` is owned by the Laravel runtime
        # (application/database/migrations/2026_09_28_000400_create_audit_logs_table.php);
        # docs/architecture.md section 1 assigns audit to the app layer. Creating
        # it here too would race the laravel container's `migrate` and one of the
        # two would fail on "relation already exists".
        "data_quality_reports",
        (
            _pk(),
            *_timestamps(),
            sa.Column("import_job_id", sa.Integer(), sa.ForeignKey("import_jobs.id"), nullable=True),
            sa.Column("score", sa.Float(), nullable=False),
            sa.Column("breakdown", sa.JSON(), nullable=False),
            sa.Column("issues", sa.JSON(), nullable=False),
        ),
    ),
)


def upgrade() -> None:
    for table in TABLES:
        # A fresh inspector per table: the reflection cache would not see the
        # DDL emitted earlier in this same transaction.
        inspector = sa.inspect(op.get_bind())
        if inspector.has_table(table.name):
            log.info("skip %s: already present", table.name)
            continue
        op.create_table(table.name, *table.columns)
        for index in table.indexes:
            if inspector.has_index(table.name, index.name):
                continue
            op.create_index(index.name, table.name, list(index.columns), unique=index.unique)
        log.info("created %s", table.name)


def downgrade() -> None:
    for table in reversed(TABLES):
        inspector = sa.inspect(op.get_bind())
        if not inspector.has_table(table.name):
            continue
        for index in table.indexes:
            if inspector.has_index(table.name, index.name):
                op.drop_index(index.name, table_name=table.name)
        op.drop_table(table.name)
        log.info("dropped %s", table.name)
