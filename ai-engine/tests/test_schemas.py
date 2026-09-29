"""Schema contracts for every model in ``app/schemas/``.

These instantiate the pydantic models directly: no database, no HTTP client, no
fixtures. They pin the field names the Laravel side reads, so a rename in a schema
fails here instead of as a null in a Blade view.

Four things are worth pinning, in this order:

1. every documented field exists on every model (a rename is a contract break);
2. a field documented as required really is required;
3. the default really is the documented default;
4. every numeric bound actually rejects out-of-range input.

Pydantic's own default of *ignoring* unknown keys is load-bearing for the Laravel
client (it sends more than the engine needs on some endpoints), so
``test_unknown_fields_are_ignored`` pins that as intentional leniency rather than
leaving it to drift.
"""
import pytest
from pydantic import ValidationError


def _keys(model):
    return set(model.model_fields)


def _required(model):
    return {name for name, f in model.model_fields.items() if f.is_required()}


# --------------------------------------------------------------------------
# app/schemas/analytics.py
# --------------------------------------------------------------------------
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


# --------------------------------------------------------------------------
# app/schemas/ml.py
# --------------------------------------------------------------------------
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


# --------------------------------------------------------------------------
# app/schemas/common.py -- the error envelope the Laravel client parses
# --------------------------------------------------------------------------
def test_common_schema_fields():
    from app.schemas.common import (
        ApiResponse, ErrorDetail, ErrorResponse, HealthResponse, Pagination,
    )

    assert _keys(Pagination) == {"page", "page_size", "total"}
    assert _keys(ApiResponse) == {"success", "data", "pagination", "request_id"}
    assert _keys(ErrorDetail) == {
        "module", "operation", "error_type", "code", "message",
        "technical", "request_id", "resolution", "details",
    }
    assert _keys(ErrorResponse) == {"success", "error"}
    assert _keys(HealthResponse) == {"status", "app", "env", "version"}


def test_error_envelope_defaults_and_required_error():
    from app.schemas.common import ApiResponse, ErrorDetail, ErrorResponse, HealthResponse

    ok = ApiResponse()
    assert ok.success is True and ok.data is None and ok.pagination is None
    # "-" is the documented placeholder, and the Laravel client keys off it.
    assert ok.request_id == "-"

    d = ErrorDetail()
    assert d.code == "APP_ERROR"
    assert d.request_id == "-" and d.details == {} and d.module == ""

    bad = ErrorResponse(error=ErrorDetail(code="X", message="y"))
    assert bad.success is False

    # ``error`` is the one required field: an error envelope without it is meaningless.
    assert _required(ErrorResponse) == {"error"}
    with pytest.raises(ValidationError):
        ErrorResponse()

    h = HealthResponse()
    assert (h.status, h.app, h.env, h.version) == ("ok", "ai-engine", "dev", "1.0.0")


# --------------------------------------------------------------------------
# app/schemas/ai.py
# --------------------------------------------------------------------------
def test_ai_schema_fields():
    from app.schemas.ai import (
        ChatMessage, ChatRequest, ChatResponse, EvidenceRow,
        RagIngestRequest, RagQueryRequest, ReportRequest,
    )

    assert _keys(ChatMessage) == {"role", "content"}
    assert _keys(ChatRequest) == {"message", "conversation_id", "context"}
    assert _keys(EvidenceRow) == {"source", "data"}
    assert _keys(ChatResponse) == {"answer", "conversation_id", "evidence", "steps"}
    assert _keys(ReportRequest) == {"period", "branch", "format"}
    assert _keys(RagIngestRequest) == {"title", "content", "source", "doc_type"}
    assert _keys(RagQueryRequest) == {"query", "top_k"}


def test_ai_schema_defaults_and_requirements():
    from app.schemas.ai import (
        ChatMessage, ChatRequest, ChatResponse, EvidenceRow,
        RagIngestRequest, RagQueryRequest, ReportRequest,
    )

    with pytest.raises(ValidationError):
        ChatMessage()
    with pytest.raises(ValidationError):
        ChatRequest()
    with pytest.raises(ValidationError):
        ChatResponse()
    with pytest.raises(ValidationError):
        RagQueryRequest()

    c = ChatRequest(message="hi")
    assert c.conversation_id is None and c.context == {}
    r = ChatResponse(answer="ok")
    assert r.evidence == [] and r.steps == 0 and r.conversation_id is None
    assert EvidenceRow().source == "" and EvidenceRow().data == {}
    assert ReportRequest().period == "weekly" and ReportRequest().format == "json"
    ingest = RagIngestRequest()
    assert (ingest.title, ingest.content, ingest.source, ingest.doc_type) == ("", "", "api", "txt")
    assert RagQueryRequest(query="q").top_k == 5


# --------------------------------------------------------------------------
# app/schemas/imports.py
# --------------------------------------------------------------------------
def test_import_schema_fields():
    from app.schemas.imports import (
        ColumnProfile, ImportCommit, MappingRequest, MappingSuggestion,
        PreviewResponse, QualityBreakdown, QualityIssue, QualityResponse, UploadInit,
    )

    assert _keys(UploadInit) == {"filename", "dataset_type", "size_bytes"}
    assert _keys(ColumnProfile) == {"name", "dtype", "missing", "missing_pct", "unique", "sample"}
    assert _keys(PreviewResponse) == {
        "filename", "size_bytes", "row_count", "column_count",
        "columns", "sample_rows", "duplicate_count", "warnings", "errors",
    }
    # This is the mapper's row shape; the ingestion routes serialise it verbatim.
    assert _keys(MappingSuggestion) == {"source_column", "target_field", "confidence", "method"}
    assert _keys(MappingRequest) == {"import_job_id", "dataset_type", "mappings", "save_as_template"}
    assert _keys(QualityBreakdown) == {"completeness", "uniqueness", "validity", "consistency"}
    assert _keys(QualityIssue) == {"rule", "column", "count", "sample_rows", "message"}
    assert _keys(QualityResponse) == {"score", "breakdown", "issues", "passed"}
    assert _keys(ImportCommit) == {"import_job_id", "dataset_type", "mappings", "run_async"}


def test_import_schema_defaults_and_requirements():
    from app.schemas.imports import (
        ColumnProfile, ImportCommit, MappingRequest, MappingSuggestion,
        PreviewResponse, QualityBreakdown, QualityIssue, QualityResponse, UploadInit,
    )

    assert UploadInit(filename="a.csv").dataset_type == "sales"
    assert UploadInit(filename="a.csv").size_bytes == 0
    assert ColumnProfile(name="c", dtype="int").missing_pct == 0.0
    assert ColumnProfile(name="c", dtype="int").sample == []
    assert PreviewResponse(filename="a.csv").row_count == 0
    assert PreviewResponse(filename="a.csv").columns == []

    # An untargeted column is target_field None with confidence 0.0 -- the mapper
    # emits exactly this and the preview screen renders it.
    s = MappingSuggestion(source_column="Kolom Misterius")
    assert s.target_field is None and s.confidence == 0.0 and s.method == "none"

    m = MappingRequest()
    assert m.dataset_type == "sales" and m.mappings == {} and m.import_job_id is None
    assert m.save_as_template is None

    # QualityBreakdown defaults to a perfect score, not a zero: the default is the
    # "nothing was measured wrong yet" state, and QualityResponse.passed stays False
    # until a real report is computed.
    qb = QualityBreakdown()
    assert (qb.completeness, qb.uniqueness, qb.validity, qb.consistency) == (1.0, 1.0, 1.0, 1.0)
    qr = QualityResponse()
    assert qr.score == 0.0 and qr.passed is False and qr.issues == []
    assert isinstance(qr.breakdown, QualityBreakdown)
    assert QualityIssue(rule="dup").count == 0 and QualityIssue(rule="dup").column is None

    c = ImportCommit(import_job_id=7)
    assert c.dataset_type == "sales" and c.mappings == {} and c.run_async is False

    for model, kwargs in (
        (UploadInit, {}),
        (ColumnProfile, {"dtype": "int"}),
        (PreviewResponse, {}),
        (MappingSuggestion, {}),
        (QualityIssue, {}),
        (ImportCommit, {}),
    ):
        with pytest.raises(ValidationError):
            model(**kwargs)


# --------------------------------------------------------------------------
# every numeric bound, at the boundary
# --------------------------------------------------------------------------
BOUNDS = [
    # (factory, base kwargs, field, minimum, maximum)
    ("app.schemas.common:Pagination", {}, "page", 1, None),
    ("app.schemas.common:Pagination", {}, "page_size", 1, 500),
    ("app.schemas.common:Pagination", {}, "total", 0, None),
    ("app.schemas.ai:RagQueryRequest", {"query": "q"}, "top_k", 1, 20),
    ("app.schemas.ml:ForecastRequest", {}, "horizon", 1, 365),
    ("app.schemas.ml:SegmentRequest", {"customers": []}, "n_clusters", 2, 10),
    ("app.schemas.ml:AnomalyRequest", {"series": []}, "sensitivity", 0.5, 6.0),
    ("app.schemas.ml:RecommendRequest", {}, "top_k", 1, 50),
]


def _resolve(dotted):
    import importlib

    module_name, _, attr = dotted.partition(":")
    return getattr(importlib.import_module(module_name), attr)


@pytest.mark.parametrize("dotted,base,field,low,high", BOUNDS,
                         ids=[f"{d.split(':')[1]}.{f}" for d, _, f, _, _ in BOUNDS])
def test_every_numeric_bound_accepts_its_edges_and_rejects_the_neighbours(dotted, base, field, low, high):
    """A bound is only pinned if the edge is accepted *and* the next value is not.

    Testing only the rejection side would pass for a model that clamps; testing
    only the acceptance side would pass for a model that ignores the bound.
    """
    model = _resolve(dotted)
    assert _keys(model), dotted

    model(**{**base, field: low})
    with pytest.raises(ValidationError):
        model(**{**base, field: low - 1})

    if high is None:
        return
    model(**{**base, field: high})
    with pytest.raises(ValidationError):
        model(**{**base, field: high + 1})


# --------------------------------------------------------------------------
# defaults that are containers must not be shared between instances
# --------------------------------------------------------------------------
CONTAINER_DEFAULTS = [
    ("app.schemas.ml:TrainRequest", {"model_type": "forecast"}, "params"),
    ("app.schemas.ml:TrainResponse",
     {"model_id": 1, "version_id": 1, "version": "1"}, "metrics"),
    ("app.schemas.ml:PredictRequest", {}, "payload"),
    ("app.schemas.ml:ForecastResponse", {}, "forecast"),
    ("app.schemas.ml:EvalMetrics", {}, "metrics"),
    ("app.schemas.common:ErrorDetail", {}, "details"),
    ("app.schemas.ai:ChatRequest", {"message": "m"}, "context"),
    ("app.schemas.ai:ChatResponse", {"answer": "a"}, "evidence"),
    ("app.schemas.imports:MappingRequest", {}, "mappings"),
    ("app.schemas.imports:ImportCommit", {"import_job_id": 1}, "mappings"),
]


@pytest.mark.parametrize("dotted,base,field", CONTAINER_DEFAULTS,
                         ids=[f"{d.split(':')[1]}.{f}" for d, _, f in CONTAINER_DEFAULTS])
def test_mutable_defaults_are_per_instance(dotted, base, field):
    """``Field(default_factory=...)``, not a shared ``Field(default={})``.

    A shared literal default is the classic pydantic bug: one request appends to
    the dict and the next request inherits the value.
    """
    model = _resolve(dotted)
    first = model(**base)
    second = model(**base)
    assert getattr(first, field) == getattr(second, field)

    container = getattr(first, field)
    if isinstance(container, dict):
        container["injected-by-test"] = True
    else:
        container.append("injected-by-test")
    assert "injected-by-test" not in getattr(second, field)


# --------------------------------------------------------------------------
# documented leniency, pinned on purpose
# --------------------------------------------------------------------------
def test_unknown_fields_are_ignored_rather_than_rejected():
    """Intentional: the Laravel client posts a superset of what some endpoints read.

    If this ever becomes ``extra="forbid"`` the failure shows up as a 422 on a
    payload that used to work, so the leniency is pinned deliberately.
    """
    from app.schemas.analytics import AnalyticsFilter
    from app.schemas.common import ApiResponse

    assert ApiResponse(success=True, data=None, unexpected="x").success is True
    f = AnalyticsFilter(granularity="weekly", branch_code="JKT", nope=1)
    assert f.granularity == "weekly"
    assert not hasattr(f, "branch_code") and not hasattr(f, "nope")


def test_string_vocabulary_fields_are_unconstrained_by_design():
    """Intentional: granularity/period/format carry no ``Literal`` vocabulary.

    The engine is the last hop before a Blade view, and a new granularity must
    not be a 422 on a release. Pinned so adding a ``Literal`` is a deliberate
    change rather than an accident.
    """
    from app.schemas.ai import ReportRequest
    from app.schemas.analytics import AnalyticsFilter
    from app.schemas.ml import ForecastRequest, PredictRequest, TrainRequest

    assert AnalyticsFilter(granularity="fortnightly").granularity == "fortnightly"
    assert ForecastRequest(granularity="weekly").granularity == "weekly"
    assert PredictRequest(model_type="not-a-real-type").model_type == "not-a-real-type"
    assert TrainRequest(model_type="not-a-real-type").model_type == "not-a-real-type"
    assert ReportRequest(period="quarterly", format="html").period == "quarterly"


def test_every_model_in_the_package_exposes_a_non_empty_field_set():
    """Smoke net: a model that loses every field would otherwise pass the tables."""
    import importlib
    import inspect

    from pydantic import BaseModel

    total = 0
    for module_name in ("common", "analytics", "ml", "ai", "imports"):
        module = importlib.import_module(f"app.schemas.{module_name}")
        for _name, obj in vars(module).items():
            if inspect.isclass(obj) and issubclass(obj, BaseModel) and obj.__module__ == module.__name__:
                assert obj.model_fields, f"{module_name}.{_name} has no fields"
                total += 1
    assert total >= 30, f"only {total} schema models discovered; the tables above are incomplete"
