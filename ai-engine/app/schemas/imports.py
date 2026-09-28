"""Import / ingestion schemas."""
from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class UploadInit(BaseModel):
    filename: str
    dataset_type: str = Field(default="sales")
    size_bytes: int = 0


class ColumnProfile(BaseModel):
    name: str
    dtype: str
    missing: int = 0
    missing_pct: float = 0.0
    unique: int = 0
    sample: List[Any] = Field(default_factory=list)


class PreviewResponse(BaseModel):
    filename: str
    size_bytes: int = 0
    row_count: int = 0
    column_count: int = 0
    columns: List[ColumnProfile] = Field(default_factory=list)
    sample_rows: List[Dict[str, Any]] = Field(default_factory=list)
    duplicate_count: int = 0
    warnings: List[str] = Field(default_factory=list)
    errors: List[str] = Field(default_factory=list)


class MappingSuggestion(BaseModel):
    source_column: str
    target_field: Optional[str] = None
    confidence: float = 0.0
    method: str = "none"


class MappingRequest(BaseModel):
    import_job_id: Optional[int] = None
    dataset_type: str = "sales"
    mappings: Dict[str, str] = Field(default_factory=dict)
    save_as_template: Optional[str] = None


class QualityBreakdown(BaseModel):
    completeness: float = 1.0
    uniqueness: float = 1.0
    validity: float = 1.0
    consistency: float = 1.0


class QualityIssue(BaseModel):
    rule: str
    column: Optional[str] = None
    count: int = 0
    sample_rows: List[int] = Field(default_factory=list)
    message: str = ""


class QualityResponse(BaseModel):
    score: float = 0.0
    breakdown: QualityBreakdown = Field(default_factory=QualityBreakdown)
    issues: List[QualityIssue] = Field(default_factory=list)
    passed: bool = False


class ImportCommit(BaseModel):
    import_job_id: int
    dataset_type: str = "sales"
    mappings: Dict[str, str] = Field(default_factory=dict)
    run_async: bool = False
