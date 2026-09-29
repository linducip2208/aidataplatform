"""SQLAlchemy models for the AI/Data platform warehouse + ops tables."""
from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import (
    JSON, BigInteger, Boolean, Date, DateTime, Float, ForeignKey, Index,
    Integer, Numeric, String, Text,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

try:  # pgvector optional
    from pgvector.sqlalchemy import Vector  # type: ignore
    _HAS_PGVECTOR = True
except Exception:  # pragma: no cover - fallback for sqlite/tests and MySQL stacks
    _HAS_PGVECTOR = False
    Vector = None  # type: ignore

from app.database.connection import Base


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=_utcnow, onupdate=_utcnow
    )


# ---------------- ingestion ----------------
class RawUpload(Base, TimestampMixin):
    __tablename__ = "raw_uploads"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    filename: Mapped[str] = mapped_column(String(512))
    stored_path: Mapped[str] = mapped_column(String(1024))
    size_bytes: Mapped[int] = mapped_column(BigInteger, default=0)
    mime: Mapped[str] = mapped_column(String(128), default="")
    checksum_sha256: Mapped[str] = mapped_column(String(64), default="")
    status: Mapped[str] = mapped_column(String(32), default="received")
    row_count: Mapped[int] = mapped_column(Integer, default=0)


class ImportJob(Base, TimestampMixin):
    __tablename__ = "import_jobs"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    upload_id: Mapped[int | None] = mapped_column(ForeignKey("raw_uploads.id"), nullable=True)
    dataset_type: Mapped[str] = mapped_column(String(64), default="sales")
    status: Mapped[str] = mapped_column(String(32), default="queued")
    progress: Mapped[float] = mapped_column(Float, default=0.0)
    total_rows: Mapped[int] = mapped_column(Integer, default=0)
    processed_rows: Mapped[int] = mapped_column(Integer, default=0)
    error_rows: Mapped[int] = mapped_column(Integer, default=0)
    mapping: Mapped[dict] = mapped_column(JSON, default=dict)
    report: Mapped[dict] = mapped_column(JSON, default=dict)
    error_log: Mapped[list] = mapped_column(JSON, default=list)


class StagingTable(Base, TimestampMixin):
    __tablename__ = "staging_tables"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    import_job_id: Mapped[int] = mapped_column(ForeignKey("import_jobs.id"))
    table_name: Mapped[str] = mapped_column(String(128))
    row_count: Mapped[int] = mapped_column(Integer, default=0)
    columns_meta: Mapped[dict] = mapped_column(JSON, default=dict)


class MappingTemplate(Base, TimestampMixin):
    __tablename__ = "mapping_templates"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(128), unique=True)
    dataset_type: Mapped[str] = mapped_column(String(64), default="sales")
    mapping: Mapped[dict] = mapped_column(JSON, default=dict)


# ---------------- dimensions ----------------
class DimCustomer(Base):
    __tablename__ = "dim_customer"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    customer_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    customer_name: Mapped[str] = mapped_column(String(256), default="")
    segment: Mapped[str] = mapped_column(String(64), default="")
    city: Mapped[str] = mapped_column(String(128), default="")
    extra: Mapped[dict] = mapped_column(JSON, default=dict)


class DimProduct(Base):
    __tablename__ = "dim_product"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    product_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    product_name: Mapped[str] = mapped_column(String(256), default="")
    category: Mapped[str] = mapped_column(String(128), default="")
    unit: Mapped[str] = mapped_column(String(32), default="pcs")
    cost_price: Mapped[float] = mapped_column(Float, default=0.0)
    selling_price: Mapped[float] = mapped_column(Float, default=0.0)
    description: Mapped[str] = mapped_column(String(1024), default="")
    image_url: Mapped[str] = mapped_column(String(1024), default="")


class DimBranch(Base):
    __tablename__ = "dim_branch"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    branch_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    branch_name: Mapped[str] = mapped_column(String(256), default="")
    city: Mapped[str] = mapped_column(String(128), default="")


class DimSupplier(Base):
    __tablename__ = "dim_supplier"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    supplier_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    supplier_name: Mapped[str] = mapped_column(String(256), default="")


class DimWarehouse(Base):
    __tablename__ = "dim_warehouse"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    warehouse_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    warehouse_name: Mapped[str] = mapped_column(String(256), default="")


class DimDate(Base):
    __tablename__ = "dim_date"
    date_key: Mapped[int] = mapped_column(Integer, primary_key=True)
    full_date: Mapped[datetime | None] = mapped_column(Date, nullable=True)
    year: Mapped[int] = mapped_column(Integer, default=0)
    month: Mapped[int] = mapped_column(Integer, default=0)
    day: Mapped[int] = mapped_column(Integer, default=0)
    weekday: Mapped[int] = mapped_column(Integer, default=0)


class DimDepartment(Base):
    __tablename__ = "dim_department"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    dept_code: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    dept_name: Mapped[str] = mapped_column(String(256), default="")


# ---------------- facts ----------------
class FactSales(Base):
    __tablename__ = "fact_sales"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    transaction_date: Mapped[datetime | None] = mapped_column(Date, nullable=True, index=True)
    customer_id: Mapped[int | None] = mapped_column(ForeignKey("dim_customer.id"), nullable=True)
    product_id: Mapped[int | None] = mapped_column(ForeignKey("dim_product.id"), nullable=True)
    branch_id: Mapped[int | None] = mapped_column(ForeignKey("dim_branch.id"), nullable=True)
    quantity: Mapped[float] = mapped_column(Float, default=0.0)
    selling_price: Mapped[float] = mapped_column(Float, default=0.0)
    discount: Mapped[float] = mapped_column(Float, default=0.0)
    revenue: Mapped[float] = mapped_column(Float, default=0.0)
    import_job_id: Mapped[int | None] = mapped_column(Integer, nullable=True, index=True)


class FactInventory(Base):
    __tablename__ = "fact_inventory"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    snapshot_date: Mapped[datetime | None] = mapped_column(Date, nullable=True, index=True)
    product_id: Mapped[int | None] = mapped_column(ForeignKey("dim_product.id"), nullable=True)
    warehouse_id: Mapped[int | None] = mapped_column(ForeignKey("dim_warehouse.id"), nullable=True)
    stock_qty: Mapped[float] = mapped_column(Float, default=0.0)
    import_job_id: Mapped[int | None] = mapped_column(Integer, nullable=True)


class FactPurchase(Base):
    __tablename__ = "fact_purchases"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    purchase_date: Mapped[datetime | None] = mapped_column(Date, nullable=True, index=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("dim_supplier.id"), nullable=True)
    product_id: Mapped[int | None] = mapped_column(ForeignKey("dim_product.id"), nullable=True)
    quantity: Mapped[float] = mapped_column(Float, default=0.0)
    cost: Mapped[float] = mapped_column(Float, default=0.0)
    import_job_id: Mapped[int | None] = mapped_column(Integer, nullable=True)


class FactExpense(Base):
    __tablename__ = "fact_expenses"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    expense_date: Mapped[datetime | None] = mapped_column(Date, nullable=True, index=True)
    department_id: Mapped[int | None] = mapped_column(ForeignKey("dim_department.id"), nullable=True)
    amount: Mapped[float] = mapped_column(Float, default=0.0)
    category: Mapped[str] = mapped_column(String(128), default="")
    import_job_id: Mapped[int | None] = mapped_column(Integer, nullable=True)


# ---------------- ML registry ----------------
class MLModel(Base, TimestampMixin):
    __tablename__ = "ml_models"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(128), unique=True, index=True)
    model_type: Mapped[str] = mapped_column(String(64))
    status: Mapped[str] = mapped_column(String(32), default="DRAFT")
    production_version_id: Mapped[int | None] = mapped_column(Integer, nullable=True)


class ModelVersion(Base, TimestampMixin):
    __tablename__ = "model_versions"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    model_id: Mapped[int] = mapped_column(ForeignKey("ml_models.id"), index=True)
    version: Mapped[str] = mapped_column(String(32))
    artifact_path: Mapped[str] = mapped_column(String(1024), default="")
    metrics: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(32), default="DRAFT")


class TrainingRun(Base, TimestampMixin):
    __tablename__ = "training_runs"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    model_id: Mapped[int | None] = mapped_column(ForeignKey("ml_models.id"), nullable=True)
    version_id: Mapped[int | None] = mapped_column(ForeignKey("model_versions.id"), nullable=True)
    model_type: Mapped[str] = mapped_column(String(64), default="")
    params: Mapped[dict] = mapped_column(JSON, default=dict)
    metrics: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(32), default="running")


class PredictionRun(Base, TimestampMixin):
    __tablename__ = "prediction_runs"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    model_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    model_type: Mapped[str] = mapped_column(String(64), default="")
    input_summary: Mapped[dict] = mapped_column(JSON, default=dict)
    output_summary: Mapped[dict] = mapped_column(JSON, default=dict)


# ---------------- AI ----------------
class AIConversation(Base, TimestampMixin):
    __tablename__ = "ai_conversations"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    title: Mapped[str] = mapped_column(String(256), default="")
    meta: Mapped[dict] = mapped_column(JSON, default=dict)


class AIMessage(Base, TimestampMixin):
    __tablename__ = "ai_messages"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    conversation_id: Mapped[int] = mapped_column(ForeignKey("ai_conversations.id"), index=True)
    role: Mapped[str] = mapped_column(String(32))
    content: Mapped[str] = mapped_column(Text, default="")
    evidence: Mapped[dict] = mapped_column(JSON, default=dict)


class RagDocument(Base, TimestampMixin):
    __tablename__ = "rag_documents"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    source: Mapped[str] = mapped_column(String(1024))
    title: Mapped[str] = mapped_column(String(512), default="")
    doc_type: Mapped[str] = mapped_column(String(32), default="txt")
    meta: Mapped[dict] = mapped_column(JSON, default=dict)


if _HAS_PGVECTOR:
    class RagChunk(Base, TimestampMixin):
        __tablename__ = "rag_chunks"
        id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
        document_id: Mapped[int] = mapped_column(ForeignKey("rag_documents.id"), index=True)
        chunk_index: Mapped[int] = mapped_column(Integer, default=0)
        content: Mapped[str] = mapped_column(Text, default="")
        embedding = mapped_column(Vector(1536))
        meta: Mapped[dict] = mapped_column(JSON, default=dict)
else:
    class RagChunk(Base, TimestampMixin):  # type: ignore[no-redef]
        __tablename__ = "rag_chunks"
        id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
        document_id: Mapped[int] = mapped_column(ForeignKey("rag_documents.id"), index=True)
        chunk_index: Mapped[int] = mapped_column(Integer, default=0)
        content: Mapped[str] = mapped_column(Text, default="")
        embedding: Mapped[dict] = mapped_column(JSON, default=dict)
        meta: Mapped[dict] = mapped_column(JSON, default=dict)


# ---------------- alerts / audit / quality ----------------
class AlertRule(Base, TimestampMixin):
    __tablename__ = "alert_rules"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(128))
    metric: Mapped[str] = mapped_column(String(64))
    condition: Mapped[str] = mapped_column(String(16), default=">")
    threshold: Mapped[float] = mapped_column(Float, default=0.0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class Alert(Base, TimestampMixin):
    __tablename__ = "alerts"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    rule_id: Mapped[int | None] = mapped_column(ForeignKey("alert_rules.id"), nullable=True)
    severity: Mapped[str] = mapped_column(String(16), default="medium")
    message: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(32), default="open")


class AlertEvent(Base, TimestampMixin):
    __tablename__ = "alert_events"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    alert_id: Mapped[int] = mapped_column(ForeignKey("alerts.id"), index=True)
    event_type: Mapped[str] = mapped_column(String(64), default="")
    payload: Mapped[dict] = mapped_column(JSON, default=dict)


class DataQualityReport(Base, TimestampMixin):
    __tablename__ = "data_quality_reports"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    import_job_id: Mapped[int | None] = mapped_column(ForeignKey("import_jobs.id"), nullable=True)
    score: Mapped[float] = mapped_column(Float, default=0.0)
    breakdown: Mapped[dict] = mapped_column(JSON, default=dict)
    issues: Mapped[list] = mapped_column(JSON, default=list)
