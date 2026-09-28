"""ML schemas."""
from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class TrainRequest(BaseModel):
    model_type: str = Field(description="forecast|churn|segmentation|anomaly|recommend")
    name: str = Field(default="model")
    params: Dict[str, Any] = Field(default_factory=dict)
    dataset: Optional[List[Dict[str, Any]]] = None


class TrainResponse(BaseModel):
    model_id: int
    version_id: int
    version: str
    metrics: Dict[str, Any] = Field(default_factory=dict)
    status: str = "VALIDATED"


class PredictRequest(BaseModel):
    model_name: Optional[str] = None
    model_type: str = "forecast"
    payload: Dict[str, Any] = Field(default_factory=dict)


class ForecastPoint(BaseModel):
    date: str
    yhat: float
    yhat_lower: float
    yhat_upper: float


class ForecastRequest(BaseModel):
    history: List[Dict[str, Any]] = Field(default_factory=list)
    horizon: int = Field(default=30, ge=1, le=365)
    granularity: str = "daily"


class ForecastResponse(BaseModel):
    forecast: List[ForecastPoint] = Field(default_factory=list)
    method: str = "baseline"
    metrics: Dict[str, Any] = Field(default_factory=dict)


class ChurnRequest(BaseModel):
    customers: List[Dict[str, Any]]


class SegmentRequest(BaseModel):
    customers: List[Dict[str, Any]]
    n_clusters: int = Field(default=4, ge=2, le=10)


class AnomalyRequest(BaseModel):
    series: List[Dict[str, Any]]
    sensitivity: float = Field(default=2.5, ge=0.5, le=6.0)


class RecommendRequest(BaseModel):
    customer_id: Optional[str] = None
    product_id: Optional[str] = None
    top_k: int = Field(default=5, ge=1, le=50)


class EvalMetrics(BaseModel):
    metrics: Dict[str, Any] = Field(default_factory=dict)
