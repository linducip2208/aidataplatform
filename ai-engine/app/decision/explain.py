"""Explanation builder: every recommendation gets an explanation.

``{summary, drivers[] (evidence refs), confidence, limitations}``. Drivers
reference evidence ids collected for the case; limitations always state the
explicit-unsupported policy so a reader never mistakes an omitted source for
a measured zero.
"""
from __future__ import annotations

from typing import Any, Dict, List

BASE_LIMITATIONS = [
    "Estimates use only available evidence; unavailable sources are omitted, never imputed.",
    "A source marked unavailable contributes no numbers to the recommendation.",
    "Scenario figures state their assumptions; unsupported scenarios carry reasons, not values.",
]


def build_explanation(
    action: str,
    evidence_ids: List[str],
    evidence: List[Dict[str, Any]],
    score: Any = None,
    limitations_extra: List[str] | None = None,
) -> Dict[str, Any]:
    """Build the explanation for one recommended action."""
    by_id = {str(e.get("id")): e for e in (evidence or [])
             if isinstance(e, dict) and e.get("id")}
    drivers: List[Dict[str, Any]] = []
    confs: List[float] = []
    for ref in evidence_ids or []:
        item = by_id.get(str(ref))
        if item is None:
            drivers.append({"evidence_id": str(ref), "kind": "unknown",
                            "source": "unknown",
                            "note": "referenced evidence not present in this case"})
            continue
        try:
            conf = float(item.get("confidence", 0.0))
        except (TypeError, ValueError):
            conf = 0.0
        confs.append(max(0.0, min(1.0, conf)))
        drivers.append({
            "evidence_id": str(item.get("id")),
            "kind": str(item.get("kind", "")),
            "source": str(item.get("source", "")),
            "note": _driver_note(item),
        })
    confidence = round(sum(confs) / len(confs), 3) if confs else 0.0
    top = drivers[0]["kind"] if drivers else "no available evidence"
    summary = (f"{action} Driven by {top}"
               + (f" (score {score})." if score is not None else "."))
    limitations = list(BASE_LIMITATIONS)
    for extra in limitations_extra or []:
        if extra and extra not in limitations:
            limitations.append(str(extra))
    return {
        "summary": summary,
        "drivers": drivers,
        "confidence": confidence,
        "limitations": limitations,
    }


def _driver_note(item: Dict[str, Any]) -> str:
    kind = str(item.get("kind", ""))
    metrics = item.get("metrics", {}) if isinstance(item.get("metrics"), dict) else {}
    if kind == "kpi":
        values = metrics.get("values", {}) if isinstance(metrics.get("values"), dict) else {}
        statuses = metrics.get("statuses", {}) if isinstance(metrics.get("statuses"), dict) else {}
        bad = sorted(k for k, v in statuses.items() if v in ("warn", "crit"))
        if bad:
            return "KPI breach: " + ", ".join(
                f"{k}={values.get(k)} ({statuses.get(k)})" for k in bad[:4])
        return f"revenue={values.get('revenue')} growth={values.get('growth_pct')}%"
    if kind == "anomaly":
        return (f"{metrics.get('n_anomalies', 0)}/{metrics.get('n', 0)} "
                "points flagged anomalous")
    if kind == "forecast":
        points = metrics.get("points", []) or []
        return (f"forecast via {metrics.get('method', '?')} "
                f"over {len(points)} periods")
    if kind == "ml_prediction":
        parts = []
        if metrics.get("recommendations"):
            parts.append(f"{len(metrics['recommendations'])} product suggestions")
        if metrics.get("n_customers") is not None:
            parts.append(f"{metrics['n_customers']} customers segmented")
        return "; ".join(parts) or "ml signals present"
    return str(item.get("reason", "")) or "evidence"
