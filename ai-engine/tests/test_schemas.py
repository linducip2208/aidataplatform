"""Schema contracts for app/schemas/analytics.py and app/schemas/ml.py.

These instantiate the pydantic models directly: no database, no HTTP client, no
fixtures. They pin the field names the Laravel side reads, so a rename in a schema
fails here instead of as a null in a Blade view.
"""
import pytest
from pydantic import ValidationError


def _keys(model):
    return set(model.model_fields)


def test_analytics_filter_defaults():
    from app.schemas.analytics import AnalyticsFilter

    f = AnalyticsFilter()
    assert _keys(AnalyticsFilter) == {"date_from", "date_to", "branch", "category", "granularity"}
    assert f.granularity == "daily"
    assert f.date_from is None and f.date_to is None
    assert f.branch is None and f.category is None


def test_kpi_response_fields():
    from app.schemas.analytics import KpiResponse

    assert _keys(KpiResponse) == {"revenue", "orders", "units", "aov", "growth_pct", "margin_pct"}
    k = KpiResponse(revenue=450, orders=3)
    assert k.aov == 0.0 and k.growth_pct == 0.0 and k.margin_pct == 0.0
    assert k.units == 0.0


def test_trend_point_fields():
    from app.schemas.analytics import TrendPoint

    assert _keys(TrendPoint) == {"period", "revenue", "orders", "units"}
    with pytest.raises(ValidationError):
        TrendPoint()


def test_rfm_row_fields():
    from app.schemas.analytics import RfmRow

    assert _keys(RfmRow) == {
        "customer", "recency_days", "frequency", "monetary",
        "r_score", "f_score", "m_score", "segment",
    }
    assert RfmRow(customer="Budi").segment == ""


def test_abc_row_fields():
    from app.schemas.analytics import AbcRow

    assert _keys(AbcRow) == {"product", "revenue", "share_pct", "cumulative_pct", "grade"}
    assert AbcRow(product="Laptop").grade == "C"


def test_cohort_cell_fields():
    from app.schemas.analytics import CohortCell

    assert _keys(CohortCell) == {"cohort", "period_offset", "retention_pct", "active_customers"}
    c = CohortCell(cohort="2024-01", period_offset=0)
    assert c.retention_pct == 0.0 and c.active_customers == 0


def test_inventory_health_fields():
    from app.schemas.analytics import InventoryHealth

    assert _keys(InventoryHealth) == {
        "product", "stock_qty", "avg_daily_sales", "days_of_stock",
        "turnover", "stockout_risk", "reorder_point", "dead_stock",
    }
    h = InventoryHealth(product="P1")
    assert h.stockout_risk == "low" and h.dead_stock is False


def test_branch_kpi_fields():
    from app.schemas.analytics import BranchKpi

    assert _keys(BranchKpi) == {"branch", "revenue", "orders", "share_pct"}
    assert BranchKpi(branch="JKT").share_pct == 0.0


def test_finance_summary_fields():
    from app.schemas.analytics import FinanceSummary

    assert _keys(FinanceSummary) == {
        "total_revenue", "total_cogs", "total_expenses",
        "gross_profit", "net_profit", "margin_pct",
    }


def test_train_request_requires_model_type():
    from app.schemas.ml import TrainRequest

    with pytest.raises(ValidationError):
        TrainRequest()
    t = TrainRequest(model_type="forecast")
    assert _keys(TrainRequest) == {"model_type", "name", "params", "dataset"}
    assert t.name == "model" and t.params == {} and t.dataset is None


def test_train_response_fields():
    from app.schemas.ml import TrainResponse

    assert _keys(TrainResponse) == {"model_id", "version_id", "version", "metrics", "status"}
    r = TrainResponse(model_id=1, version_id=2, version="1.0.0")
    assert r.metrics == {} and r.status == "VALIDATED"
    with pytest.raises(ValidationError):
        TrainResponse(model_id=1, version_id=2)


def test_predict_request_defaults():
    from app.schemas.ml import PredictRequest

    assert _keys(PredictRequest) == {"model_name", "model_type", "payload"}
    p = PredictRequest()
    assert p.model_name is None and p.model_type == "forecast" and p.payload == {}


def test_forecast_point_is_fully_required():
    from app.schemas.ml import ForecastPoint

    assert _keys(ForecastPoint) == {"date", "yhat", "yhat_lower", "yhat_upper"}
    with pytest.raises(ValidationError):
        ForecastPoint(date="2024-01-01", yhat=1.0)


def test_forecast_request_horizon_is_bounded():
    from app.schemas.ml import ForecastRequest

    assert _keys(ForecastRequest) == {"history", "horizon", "granularity"}
    assert ForecastRequest().horizon == 30
    with pytest.raises(ValidationError):
        ForecastRequest(horizon=0)
    with pytest.raises(ValidationError):
        ForecastRequest(horizon=366)


def test_forecast_response_defaults():
    from app.schemas.ml import ForecastResponse

    assert _keys(ForecastResponse) == {"forecast", "method", "metrics"}
    f = ForecastResponse()
    assert f.forecast == [] and f.method == "baseline" and f.metrics == {}


def test_ml_request_bounds_are_enforced():
    from app.schemas.ml import AnomalyRequest, RecommendRequest, SegmentRequest

    assert _keys(SegmentRequest) == {"customers", "n_clusters"}
    assert _keys(AnomalyRequest) == {"series", "sensitivity"}
    assert _keys(RecommendRequest) == {"customer_id", "product_id", "top_k"}
    assert SegmentRequest(customers=[]).n_clusters == 4
    assert AnomalyRequest(series=[]).sensitivity == 2.5
    assert RecommendRequest().top_k == 5
    with pytest.raises(ValidationError):
        SegmentRequest(customers=[], n_clusters=1)
    with pytest.raises(ValidationError):
        AnomalyRequest(series=[], sensitivity=9.0)
    with pytest.raises(ValidationError):
        RecommendRequest(top_k=0)
    with pytest.raises(ValidationError):
        RecommendRequest(top_k=51)


def test_churn_request_requires_customers():
    from app.schemas.ml import ChurnRequest

    with pytest.raises(ValidationError):
        ChurnRequest()


def test_eval_metrics_defaults():
    from app.schemas.ml import EvalMetrics

    assert _keys(EvalMetrics) == {"metrics"}
    assert EvalMetrics().metrics == {}
