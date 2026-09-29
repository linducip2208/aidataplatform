"""Business-rules layer + recommendation scoring.

Rules are explicit threshold checks over collected evidence. They are
versioned: :data:`RULES_VERSION` applies to every rule, and each rule dict
carries its own ``version`` so an audit row can state exactly which logic
fired.

Scoring formula (documented, deterministic)::

    mean_conf = mean(confidence of referenced evidence) or 0.0
    score = round(100 * (0.6 * severity + 0.4 * mean_conf), 2)

``severity`` is the rule's 0..1 assessment of how strongly the evidence
warrants action; ``mean_conf`` is how much the underlying evidence can be
trusted. The 0.6/0.4 weights keep a severe-but-uncertain finding below a
moderate-but-certain one. Scores clamp to [0, 100].
"""
from __future__ import annotations

import math
from typing import Any, Callable, Dict, List

RULES_VERSION = "1.0.0"


def _finite(value: Any, default: float = 0.0) -> float:
    try:
        out = float(value)
    except (TypeError, ValueError):
        return default
    return out if math.isfinite(out) else default


def _by_kind(evidence: List[Dict[str, Any]], kind: str) -> Dict[str, Any] | None:
    for e in evidence or []:
        if isinstance(e, dict) and e.get("kind") == kind and e.get("status") == "available":
            return e
    return None


def _kpi_values(kpi_item: Dict[str, Any] | None) -> Dict[str, Any]:
    if not kpi_item:
        return {}
    metrics = kpi_item.get("metrics", {}) if isinstance(kpi_item, dict) else {}
    return dict(metrics.get("values", {}) or {})


def _kpi_statuses(kpi_item: Dict[str, Any] | None) -> Dict[str, str]:
    if not kpi_item:
        return {}
    metrics = kpi_item.get("metrics", {}) if isinstance(kpi_item, dict) else {}
    raw = metrics.get("statuses", {}) or {}
    return {str(k): str(v) for k, v in raw.items()} if isinstance(raw, dict) else {}


def _rule_revenue_drop(evidence: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fires when second-half revenue growth is materially negative."""
    kpi = _by_kind(evidence, "kpi")
    if kpi is None:
        return {"fired": False, "severity": 0.0, "evidence_ids": [],
                "detail": "kpi evidence unavailable"}
    values = _kpi_values(kpi)
    growth = _finite(values.get("growth_pct"), 0.0)
    if growth >= -5.0:
        return {"fired": False, "severity": 0.0, "evidence_ids": [kpi["id"]],
                "detail": f"growth_pct {growth:.2f}% is within tolerance (>= -5%)"}
    severity = max(0.0, min(1.0, abs(growth) / 20.0))
    return {"fired": True, "severity": round(severity, 3),
            "evidence_ids": [kpi["id"]],
            "detail": f"growth_pct {growth:.2f}% below -5% threshold"}


def _rule_margin_crit(evidence: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fires when net margin breaches its warn/crit thresholds."""
    kpi = _by_kind(evidence, "kpi")
    if kpi is None:
        return {"fired": False, "severity": 0.0, "evidence_ids": [],
                "detail": "kpi evidence unavailable"}
    statuses = _kpi_statuses(kpi)
    values = _kpi_values(kpi)
    status = statuses.get("margin_pct", "ok")
    if status == "crit":
        severity = 0.9
    elif status == "warn":
        severity = 0.5
    else:
        return {"fired": False, "severity": 0.0, "evidence_ids": [kpi["id"]],
                "detail": "margin_pct within thresholds"}
    return {"fired": True, "severity": severity, "evidence_ids": [kpi["id"]],
            "detail": f"margin_pct {values.get('margin_pct')} status={status}"}


def _rule_anomaly_spike(evidence: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fires when the anomaly detector flagged points in the window."""
    item = _by_kind(evidence, "anomaly")
    if item is None:
        return {"fired": False, "severity": 0.0, "evidence_ids": [],
                "detail": "anomaly evidence unavailable"}
    metrics = item.get("metrics", {}) or {}
    n_anom = int(metrics.get("n_anomalies", 0) or 0)
    if n_anom <= 0:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "no anomalies flagged"}
    anomalies = metrics.get("anomalies", []) or []
    try:
        peak = max(_finite(a.get("score"), 0.0) for a in anomalies) if anomalies else 0.5
    except Exception:
        peak = 0.5
    n = max(1, int(metrics.get("n", 1) or 1))
    rate = min(1.0, (n_anom / n) * 5.0)
    severity = max(0.0, min(1.0, 0.5 * rate + 0.5 * min(1.0, peak)))
    return {"fired": True, "severity": round(severity, 3),
            "evidence_ids": [item["id"]],
            "detail": f"{n_anom}/{n} points flagged anomalous"}


def _rule_churn_risk(evidence: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fires when segmentation shows an at-risk/dormant customer pocket."""
    item = _by_kind(evidence, "ml_prediction")
    if item is None:
        return {"fired": False, "severity": 0.0, "evidence_ids": [],
                "detail": "ml_prediction evidence unavailable"}
    metrics = item.get("metrics", {}) or {}
    segments = metrics.get("segments", []) or []
    if not segments:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "no customer segments computed"}
    risky = [s for s in segments
             if str(s.get("segment", "")).lower() in ("at_risk", "dormant", "low_value")]
    if not risky:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "no at-risk segment observed"}
    share = len(risky) / max(1, len(segments))
    severity = max(0.0, min(1.0, 0.3 + 0.7 * share))
    return {"fired": True, "severity": round(severity, 3),
            "evidence_ids": [item["id"]],
            "detail": f"{len(risky)}/{len(segments)} sampled customers in at-risk segments"}


def _rule_forecast_decline(evidence: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Fires when the forecast points materially below the recent level."""
    item = _by_kind(evidence, "forecast")
    kpi = _by_kind(evidence, "kpi")
    if item is None:
        return {"fired": False, "severity": 0.0, "evidence_ids": [],
                "detail": "forecast evidence unavailable"}
    metrics = item.get("metrics", {}) or {}
    points = metrics.get("points", []) or []
    if len(points) < 2:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "forecast has fewer than 2 points"}
    try:
        first = _finite(points[0].get("yhat"), 0.0)
        last = _finite(points[-1].get("yhat"), 0.0)
    except Exception:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "forecast points unreadable"}
    base = first if first > 0 else None
    if base is None and kpi is not None:
        base_val = _finite(_kpi_values(kpi).get("revenue"), 0.0)
        base = base_val if base_val > 0 else None
    if base is None or base <= 0:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": "no positive baseline to compare the forecast against"}
    decline_pct = (base - last) / base * 100.0
    if decline_pct < 5.0:
        return {"fired": False, "severity": 0.0, "evidence_ids": [item["id"]],
                "detail": f"forecast decline {decline_pct:.2f}% within tolerance (< 5%)"}
    severity = max(0.0, min(1.0, decline_pct / 30.0))
    return {"fired": True, "severity": round(severity, 3),
            "evidence_ids": [item["id"]],
            "detail": f"forecast declines {decline_pct:.2f}% vs baseline"}


_RULES: List[Dict[str, Any]] = [
    {"name": "revenue_drop_rule", "version": RULES_VERSION,
     "description": "Second-half revenue growth below -5%.",
     "action": "Review pricing, promotions and branch performance for the declining window.",
     "evaluate": _rule_revenue_drop},
    {"name": "margin_crit_rule", "version": RULES_VERSION,
     "description": "Net margin_pct breached warn/crit thresholds.",
     "action": "Audit cost of goods and operating expenses; pause low-margin lines.",
     "evaluate": _rule_margin_crit},
    {"name": "anomaly_spike_rule", "version": RULES_VERSION,
     "description": "Anomaly detector flagged points in the sales window.",
     "action": "Investigate the flagged dates for stockouts, data errors or demand shocks.",
     "evaluate": _rule_anomaly_spike},
    {"name": "churn_risk_rule", "version": RULES_VERSION,
     "description": "Customer segmentation shows an at-risk/dormant pocket.",
     "action": "Run a win-back campaign for the at-risk customer pocket.",
     "evaluate": _rule_churn_risk},
    {"name": "forecast_decline_rule", "version": RULES_VERSION,
     "description": "Forecast points materially below the recent level.",
     "action": "Prepare demand-stimulus and inventory alignment for the forecast window.",
     "evaluate": _rule_forecast_decline},
]


def list_rules() -> List[Dict[str, Any]]:
    """Return the versioned rule catalogue (no evaluation)."""
    return [{"name": r["name"], "version": r["version"],
             "description": r["description"], "action": r["action"]} for r in _RULES]


def score_recommendation(severity: Any, confidences: List[Any]) -> float:
    """Score one recommendation.

    ``score = round(100 * (0.6 * severity + 0.4 * mean_conf), 2)`` where
    ``mean_conf`` is the mean of the referenced evidence confidences (0.0
    when there are none). Both inputs clamp to [0, 1]; the result clamps to
    [0, 100]. Pure function: deterministic, no I/O.
    """
    sev = max(0.0, min(1.0, _finite(severity, 0.0)))
    vals = []
    for c in confidences or []:
        v = _finite(c, 0.0)
        vals.append(max(0.0, min(1.0, v)))
    mean_conf = sum(vals) / len(vals) if vals else 0.0
    return round(100.0 * (0.6 * sev + 0.4 * mean_conf), 2)


def evaluate_rules(evidence: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Evaluate every rule over ``evidence``. Deterministic rule order."""
    by_id = {str(e.get("id")): e for e in (evidence or [])
             if isinstance(e, dict) and e.get("id")}
    out: List[Dict[str, Any]] = []
    for rule in _RULES:
        fn: Callable[[List[Dict[str, Any]]], Dict[str, Any]] = rule["evaluate"]
        try:
            res = fn(list(evidence or []))
        except Exception as exc:
            res = {"fired": False, "severity": 0.0, "evidence_ids": [],
                   "detail": f"rule error: {type(exc).__name__}"}
        confs = [_finite(by_id[ref].get("confidence"), 0.0)
                 for ref in res.get("evidence_ids", []) if ref in by_id]
        out.append({
            "rule": rule["name"],
            "version": rule["version"],
            "description": rule["description"],
            "action": rule["action"],
            "fired": bool(res.get("fired", False)),
            "severity": round(max(0.0, min(1.0, _finite(res.get("severity"), 0.0))), 3),
            "confidence": round(sum(confs) / len(confs), 3) if confs else 0.0,
            "score": score_recommendation(res.get("severity"), confs),
            "evidence_ids": list(res.get("evidence_ids", [])),
            "detail": str(res.get("detail", "")),
        })
    return out
