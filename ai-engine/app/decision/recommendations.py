"""Recommendation orchestration: evidence -> rules -> explanations -> history.

``recommend(subject)`` returns::

    {case_id, subject, recommendations[] (action, expected_impact,
     confidence, evidence_ids, scenario_ref, score, explanation),
     generated_at}

History persists only when a ``db_session`` is given: one
``decision_cases`` row, one ``decision_recommendations`` row per fired rule
(including the empty set — the case still records the evidence), and one
``decision_audits`` system row. Read paths (``list_cases`` / ``get_case`` /
audit history) never compute and never write.
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

from app.decision import evidence as ev
from app.decision import explain as ex
from app.decision import rules as ru


def _utcnow_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


_SCENARIO_REF = {
    "revenue_drop_rule": {"type": "price_change_pct",
                          "params": {"price_change_pct": -5.0}},
    "margin_crit_rule": {"type": "inventory_change_pct",
                         "params": {"inventory_change_pct": 10.0}},
    "churn_risk_rule": {"type": "churn_rise_pp",
                        "params": {"churn_rise_pp": 5.0}},
    "forecast_decline_rule": {"type": "price_change_pct",
                              "params": {"price_change_pct": -5.0}},
    "anomaly_spike_rule": None,
}


def _expected_impact(rule_name: str, detail: str,
                     evidence_items: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Qualitative impact with a numeric value only when directly observed.

    The value is set only for the forecast-decline rule (the forecast points
    carry the magnitude); every other rule reports ``value: None`` with the
    rule detail as basis. Never a modelled guess presented as a number.
    """
    return {"summary": detail, "value": None, "unit": "",
            "basis": f"rule {rule_name}"}


def recommend(
    subject: Optional[Dict[str, Any]] = None,
    *,
    sales_df: Any = None,
    db_session: Any = None,
) -> Dict[str, Any]:
    """Collect evidence, evaluate rules, explain, and persist the case."""
    collected = ev.collect_evidence(subject, sales_df=sales_df, db_session=db_session)
    norm_subject = collected["subject"]
    items = collected["evidence"]
    fired_rules = [r for r in ru.evaluate_rules(items) if r.get("fired")]
    fired_rules.sort(key=lambda r: r.get("score", 0.0), reverse=True)

    recommendations: List[Dict[str, Any]] = []
    for r in fired_rules:
        explanation = ex.build_explanation(
            r["action"], r["evidence_ids"], items, score=r["score"])
        recommendations.append({
            "action": r["action"],
            "expected_impact": _expected_impact(r["rule"], r["detail"], items),
            "confidence": r["confidence"],
            "evidence_ids": r["evidence_ids"],
            "scenario_ref": _SCENARIO_REF.get(r["rule"]),
            "score": r["score"],
            "rule": r["rule"],
            "rule_version": r["version"],
            "explanation": explanation,
        })

    case_id: Optional[int] = None
    if db_session is not None:
        case_id = _persist_case(db_session, norm_subject, items, recommendations)

    return {
        "case_id": case_id,
        "subject": norm_subject,
        "recommendations": recommendations,
        "evidence": items,
        "generated_at": _utcnow_iso(),
    }


def _persist_case(db_session: Any, subject: Dict[str, Any],
                  items: List[Dict[str, Any]],
                  recommendations: List[Dict[str, Any]]) -> int:
    from app.decision.models import DecisionAudit, DecisionCase, DecisionRecommendation

    case = DecisionCase(subject=dict(subject), status="recommended")
    db_session.add(case)
    db_session.flush()
    for rec in recommendations:
        db_session.add(DecisionRecommendation(
            case_id=int(case.id),
            action=str(rec["action"])[:512],
            impact=dict(rec["expected_impact"] or {}),
            confidence=float(rec["confidence"] or 0.0),
            evidence={"evidence_ids": list(rec["evidence_ids"] or [])},
            explanation=dict(rec["explanation"] or {}),
            score=float(rec["score"] or 0.0),
            rule=str(rec.get("rule", ""))[:128],
        ))
    summary = (f"{len(recommendations)} recommendation(s): "
               + "; ".join(r["rule"] for r in recommendations)) if recommendations \
        else "no rule fired; evidence recorded"
    db_session.add(DecisionAudit(
        case_id=int(case.id), actor="system",
        decision="recommended", rationale=summary[:2000]))
    db_session.flush()
    return int(case.id)


def list_cases(db_session: Any, limit: int = 50) -> List[Dict[str, Any]]:
    """Newest-first case headers. Read-only; empty list without a session."""
    if db_session is None:
        return []
    try:
        from app.decision.models import DecisionCase

        n = max(1, min(200, int(limit)))
        rows = (db_session.query(DecisionCase)
                .order_by(DecisionCase.created_at.desc(), DecisionCase.id.desc())
                .limit(n).all())
    except Exception:
        return []
    out = []
    for row in rows:
        try:
            out.append({
                "id": int(row.id),
                "subject": dict(row.subject or {}),
                "status": str(row.status or ""),
                "created_at": row.created_at.isoformat() if row.created_at else "",
            })
        except Exception:
            continue
    return out


def get_case(db_session: Any, case_id: Any) -> Optional[Dict[str, Any]]:
    """Full case detail with recommendations + audits. Read-only."""
    if db_session is None:
        return None
    try:
        cid = int(case_id)
    except (TypeError, ValueError):
        return None
    try:
        from app.decision.models import (
            DecisionAudit, DecisionCase, DecisionRecommendation,
        )

        case = db_session.query(DecisionCase).filter_by(id=cid).first()
        if case is None:
            return None
        recs = (db_session.query(DecisionRecommendation)
                .filter_by(case_id=cid).order_by(DecisionRecommendation.id).all())
        audits = (db_session.query(DecisionAudit)
                  .filter_by(case_id=cid).order_by(DecisionAudit.id).all())
    except Exception:
        return None
    return {
        "id": int(case.id),
        "subject": dict(case.subject or {}),
        "status": str(case.status or ""),
        "created_at": case.created_at.isoformat() if case.created_at else "",
        "recommendations": [{
            "id": int(r.id), "action": str(r.action or ""),
            "impact": dict(r.impact or {}),
            "confidence": float(r.confidence or 0.0),
            "evidence": dict(r.evidence or {}),
            "explanation": dict(r.explanation or {}),
            "score": float(r.score or 0.0),
            "rule": str(r.rule or ""),
        } for r in recs],
        "audits": [{
            "id": int(a.id), "actor": str(a.actor or ""),
            "decision": str(a.decision or ""),
            "rationale": str(a.rationale or ""),
            "created_at": a.created_at.isoformat() if a.created_at else "",
        } for a in audits],
    }


def add_audit(db_session: Any, case_id: Any, actor: str,
              decision: str, rationale: str = "") -> Optional[Dict[str, Any]]:
    """Append one audit row to a case. Returns the row dict, or None when the
    case does not exist. Raises ``ValueError`` for blank actor/decision."""
    actor_name = str(actor or "").strip()
    choice = str(decision or "").strip()
    if not actor_name:
        raise ValueError("actor is required")
    if not choice:
        raise ValueError("decision is required")
    try:
        cid = int(case_id)
    except (TypeError, ValueError):
        return None
    if db_session is None:
        raise ValueError("db_session is required to record an audit")
    from app.decision.models import DecisionAudit, DecisionCase

    case = db_session.query(DecisionCase).filter_by(id=cid).first()
    if case is None:
        return None
    row = DecisionAudit(case_id=cid, actor=actor_name[:128],
                        decision=choice[:64], rationale=str(rationale or ""))
    db_session.add(row)
    db_session.flush()
    return {
        "id": int(row.id or 0), "case_id": cid, "actor": row.actor,
        "decision": row.decision, "rationale": row.rationale,
        "created_at": row.created_at.isoformat() if row.created_at else "",
    }
