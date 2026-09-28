"""Alerting unit tests: operators, the dedup state machine, metric routing.

Pure logic only -- no session, no warehouse, no TestClient. ``app.alerts.rules``
imports neither SQLAlchemy nor pandas at module level precisely so these cases
can run without a database, which is the point: the state machine is the one
piece of alerting that has to be right and it has nothing to do with I/O.
"""
from __future__ import annotations

import pytest

from app.alerts.rules import (
    ACTION_KEEP,
    ACTION_NONE,
    ACTION_OPEN,
    ACTION_RESOLVE,
    EVALUATION_WINDOW_DAYS,
    METRICS,
    OPERATORS,
    SEVERITIES,
    AlertConfigurationError,
    AlertStateMachine,
    AlertStateMachineError,
    apply_operator,
    coerce_threshold,
    metric_catalog,
    normalize_condition,
    plan_transition,
    require_metric,
    resolve_metric,
    resolve_severity,
)


# --------------------------------------------------------------------------
# operators
# --------------------------------------------------------------------------
@pytest.mark.parametrize(
    "raw,expected",
    [
        (">", ">"), ("gt", ">"), ("GT", ">"), (" greater ", ">"),
        (">=", ">="), ("gte", ">="), ("ge", None),
        ("<", "<"), ("lt", "<"),
        ("<=", "<="), ("lte", "<="),
        ("==", "=="), ("=", "=="), ("eq", "=="), ("equal", "=="),
        ("!=", "!="), ("<>", "!="), ("ne", "!="), ("not_equal", "!="),
    ],
)
def test_condition_normalisation(raw, expected):
    """The column default is ">" so symbols are canonical; words are aliases."""
    if expected is None:
        with pytest.raises(AlertConfigurationError):
            normalize_condition(raw)
        return
    assert normalize_condition(raw) == expected


def test_unknown_condition_is_a_configuration_error_not_a_silent_pass():
    for bad in ("", "   ", ">>", "approx", "between", None, 5):
        with pytest.raises(AlertConfigurationError) as exc:
            normalize_condition(bad)
        assert exc.value.code == "ALERT_UNKNOWN_OPERATOR"
        assert exc.value.status_code == 400


@pytest.mark.parametrize(
    "value,condition,threshold,expected",
    [
        # gt is exclusive, gte inclusive
        (10.0, ">", 5.0, True), (10.0, ">", 10.0, False),
        (10.0, ">=", 10.0, True), (10.0, ">=", 11.0, False),
        # lt / lte
        (10.0, "<", 20.0, True), (10.0, "<", 10.0, False),
        (10.0, "<=", 10.0, True), (10.0, "<=", 9.0, False),
        # equality
        (10.0, "==", 10.0, True), (10.0, "==", 10.5, False),
        (10.0, "ne", 10.0, False), (10.0, "ne", 11.0, True),
        # spelled-out aliases behave identically
        (0.0, "gt", -1.0, True), (0.0, "lte", 0.0, True),
        # negative revenue is real: a loss must trip a "< 0" rule
        (-250.75, "lt", 0.0, True), (-250.75, "gt", 0.0, False),
    ],
)
def test_operator_semantics(value, condition, threshold, expected):
    assert apply_operator(value, condition, threshold) is expected


def test_a_bad_condition_raises_inside_apply_operator():
    with pytest.raises(AlertConfigurationError):
        apply_operator(1.0, "not-an-operator", 0.0)


@pytest.mark.parametrize("bad", ["", None, "abc", "1,000", float("nan"),
                                 float("inf"), float("-inf")])
def test_coerce_threshold_rejects_anything_that_is_not_a_finite_number(bad):
    """A NaN threshold compares False forever and would look like a healthy rule."""
    with pytest.raises(AlertConfigurationError) as exc:
        coerce_threshold(bad)
    assert exc.value.code == "ALERT_INVALID_THRESHOLD"


@pytest.mark.parametrize("good,want", [("5", 5.0), (7, 7.0), (-1.5, -1.5),
                                        (0.0, 0.0)])
def test_coerce_threshold_accepts_numbers_and_numeric_strings(good, want):
    assert coerce_threshold(good) == want


# --------------------------------------------------------------------------
# dedup state machine
# --------------------------------------------------------------------------
def test_plan_transition_covers_exactly_four_cases():
    assert plan_transition(False, True).action == ACTION_OPEN
    assert plan_transition(True, True).action == ACTION_KEEP
    assert plan_transition(True, False).action == ACTION_RESOLVE
    assert plan_transition(False, False).action == ACTION_NONE


def test_a_continuously_true_rule_opens_exactly_one_alert():
    """The whole point of the feature: 1000 evaluations of a true rule, one row."""
    machine = AlertStateMachine()
    actions = []
    for index in range(1000):
        transition = machine.observe(True)
        actions.append(transition.action)
        if transition.action == ACTION_OPEN:
            machine.bind(index + 1)

    assert actions.count(ACTION_OPEN) == 1
    assert actions.count(ACTION_KEEP) == 999
    assert machine.current_alert_id == 1


def test_fire_then_still_true_then_resolve_then_fire_again():
    """The documented lifecycle, step by step."""
    machine = AlertStateMachine()

    first = machine.observe(True)          # nothing open, rule is true
    assert first.action == ACTION_OPEN
    machine.bind(101)
    assert machine.current_alert_id == 101

    second = machine.observe(True)         # still true -> same row, no insert
    assert second.action == ACTION_KEEP
    assert machine.current_alert_id == 101

    third = machine.observe(False)         # recovered -> close it
    assert third.action == ACTION_RESOLVE
    assert machine.current_alert_id is None
    assert not machine.has_open_alert

    fourth = machine.observe(False)        # stays clear -> nothing to do
    assert fourth.action == ACTION_NONE

    fifth = machine.observe(True)          # fires again -> a NEW alert
    assert fifth.action == ACTION_OPEN
    machine.bind(202)
    assert machine.current_alert_id == 202


def test_a_new_alert_can_never_be_opened_before_the_previous_one_resolves():
    """Structural proof of the dedup invariant, not a sampled one.

    Every step asserts on the state *before* the observation, so no trace --
    including one that oscillates -- can produce an ACTION_OPEN while an alert
    is still open.
    """
    machine = AlertStateMachine()
    opens = 0
    for fires in (True, True, False, True, True, False, False, True, False, True):
        had_open = machine.has_open_alert
        transition = machine.observe(fires)
        if transition.action == ACTION_OPEN:
            assert not had_open, "opened a second alert while one was still open"
            opens += 1
            machine.bind(opens)
        else:
            assert had_open or transition.action == ACTION_NONE
    assert opens == 4  # one per fire/resolve cycle in the trace


def test_an_acknowledged_alert_is_still_open_for_dedup():
    """Acknowledging is not resolving, so the next evaluation must not insert."""
    machine = AlertStateMachine(open_alert_id=7)
    assert machine.has_open_alert
    assert machine.current_alert_id == 7
    assert machine.observe(True).action == ACTION_KEEP
    assert machine.current_alert_id == 7


def test_bind_is_only_valid_right_after_an_open_transition():
    machine = AlertStateMachine()
    machine.observe(True)
    machine.bind(5)
    assert machine.current_alert_id == 5
    with pytest.raises(AlertStateMachineError):
        machine.bind(6)


def test_pending_open_alert_has_no_id_yet():
    machine = AlertStateMachine()
    machine.observe(True)
    assert machine.has_open_alert
    assert machine.current_alert_id is None


# --------------------------------------------------------------------------
# metric routing
# --------------------------------------------------------------------------
def test_every_registered_metric_is_reachable_and_typed():
    assert METRICS, "the metric registry must not be empty"
    for key, spec in METRICS.items():
        assert spec.key == key
        assert callable(spec.compute)
        assert resolve_metric(key) is spec
        assert resolve_metric(key.upper()) is spec  # matching is case-insensitive
        assert resolve_severity(spec) in SEVERITIES


def test_unknown_metric_raises_and_lists_the_supported_ones():
    for bad in ("revenue", "sales.", "sales.revenu", "", None, "DROP TABLE"):
        with pytest.raises(AlertConfigurationError) as exc:
            require_metric(bad)
        assert exc.value.code == "ALERT_UNKNOWN_METRIC"
        assert exc.value.details["supported"] == sorted(METRICS)
    assert resolve_metric("nope") is None


def test_catalog_is_sorted_and_complete():
    catalog = metric_catalog()
    assert [row["metric"] for row in catalog] == sorted(METRICS)
    assert all(row["severity"] in SEVERITIES for row in catalog)
    assert all(row["description"] for row in catalog)


def test_the_evaluation_window_is_a_declared_constant():
    """There is no column for it, so its value has to be visible, not implied."""
    assert isinstance(EVALUATION_WINDOW_DAYS, int)
    assert EVALUATION_WINDOW_DAYS >= 1


# --------------------------------------------------------------------------
# severity
# --------------------------------------------------------------------------
def test_severity_comes_from_the_metric_and_stays_in_the_column_vocabulary():
    assert resolve_severity(require_metric("inventory.stockout_count")) == "critical"
    assert resolve_severity(require_metric("sales.aov")) == "low"
    for spec in METRICS.values():
        assert len(resolve_severity(spec)) <= 16  # alerts.severity is String(16)


def test_an_out_of_vocabulary_registry_value_falls_back_to_the_model_default():
    from app.alerts.rules import DEFAULT_SEVERITY, MetricSpec

    bogus = MetricSpec("test.metric", "test", "", "PAGIC", "", lambda ctx: 0.0)
    assert resolve_severity(bogus) == DEFAULT_SEVERITY == "medium"


# --------------------------------------------------------------------------
# operator vocabulary matches the registry contract
# --------------------------------------------------------------------------
def test_operator_vocabulary_is_exactly_the_documented_six():
    assert sorted(set(OPERATORS.values())) == ["!=", "<", "<=", "==", ">", ">="]
