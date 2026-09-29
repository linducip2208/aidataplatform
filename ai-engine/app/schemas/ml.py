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


class ExperimentCreate(BaseModel):
    model_type: str = Field(description="forecast|churn|segmentation|anomaly|recommend")
    name: str = Field(default="experiment", max_length=128)
    dataset: Optional[List[Dict[str, Any]]] = None
    dataset_ref: str = Field(default="")
    dataset_version: str = Field(default="")
    feature_list: List[str] = Field(default_factory=list)
    params: Dict[str, Any] = Field(default_factory=dict)
    train_ratio: float = Field(default=0.7, gt=0)
    val_ratio: float = Field(default=0.15, gt=0)
    test_ratio: float = Field(default=0.15, gt=0)
    seed: int = Field(default=42)
    label_key: Optional[str] = None
    date_key: Optional[str] = None
    run_training: bool = Field(default=True)


class ExperimentCompareRequest(BaseModel):
    experiment_ids: List[int] = Field(default_factory=list)
    metric: Optional[str] = None
    split: Optional[str] = None
    higher_is_better: Optional[bool] = None


class ExperimentPromoteRequest(BaseModel):
    version_id: Optional[int] = Field(default=None, ge=1)


class BatchPredictRequest(BaseModel):
    model_type: str = Field(default="churn",
                            description="forecast|churn|segmentation|anomaly|recommend")
    dataset: Optional[List[Dict[str, Any]]] = None
    csv_text: Optional[str] = None
    model_name: Optional[str] = None
    model_id: Optional[int] = Field(default=None, ge=1)
    version_id: Optional[int] = Field(default=None, ge=1)
    chunk_size: int = Field(default=500, ge=1, le=5000)
    params: Dict[str, Any] = Field(default_factory=dict)


class RollbackRequest(BaseModel):
    actor: str = Field(default="")
    note: str = Field(default="")
