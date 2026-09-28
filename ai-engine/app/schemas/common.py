"""Common API schemas."""
from __future__ import annotations

from typing import Any, Dict, Generic, List, Optional, TypeVar

from pydantic import BaseModel, Field

T = TypeVar("T")


class Pagination(BaseModel):
    page: int = Field(default=1, ge=1)
    page_size: int = Field(default=20, ge=1, le=500)
    total: int = Field(default=0, ge=0)


class ApiResponse(BaseModel):
    success: bool = True
    data: Any = None
    pagination: Optional[Pagination] = None
    request_id: str = "-"


class ErrorDetail(BaseModel):
    module: str = ""
    operation: str = ""
    error_type: str = ""
    code: str = "APP_ERROR"
    message: str = ""
    technical: str = ""
    request_id: str = "-"
    resolution: str = ""
    details: Dict[str, Any] = Field(default_factory=dict)


class ErrorResponse(BaseModel):
    success: bool = False
    error: ErrorDetail


class HealthResponse(BaseModel):
    status: str = "ok"
    app: str = "ai-engine"
    env: str = "dev"
    version: str = "1.0.0"
