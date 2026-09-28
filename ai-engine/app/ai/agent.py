"""Business assistant agent: intent parse, plan tool calls (max 8), synthesize with evidence."""
from __future__ import annotations

import re
from typing import Any, Dict, List

from app.ai import llm as llm_client
from app.ai import tools as tool_mod


def parse_intent(message: str) -> List[str]:
    m = message.lower()
    plan: List[str] = []
    if any(k in m for k in ("kpi", "ringkas", "summary", "pendapatan", "revenue", "omzet")):
        plan.append("get_kpi")
    if any(k in m for k in ("tren", "trend", "penjualan", "sales", "grafik")):
        plan.append("query_sales")
    if any(k in m for k in ("stok", "stock", "inventory", "gudang")):
        plan.append("query_inventory")
    if any(k in m for k in ("customer", "pelanggan", "rfm", "churn")):
        plan.append("query_customer")
    if any(k in m for k in ("produk", "product", "abc", "kategori")):
        plan.append("query_product")
    if any(k in m for k in ("keuangan", "finance", "margin", "laba", "profit")):
        plan.append("query_finance")
    if any(k in m for k in ("forecast", "prediksi", "ramal", "proyeksi")):
        plan.append("get_forecast")
    if any(k in m for k in ("anomali", "anomaly", "janggal", "outlier")):
        plan.append("get_anomaly")
    if any(k in m for k in ("segmen", "segment", "cluster")):
        plan.append("get_customer_segment")
    if any(k in m for k in ("laporan", "report", "eksekutif", "mingguan", "bulanan")):
        plan.append("generate_report")
    if not plan:
        plan = ["get_kpi", "query_sales"]
    return plan[:8]


def run_agent(message: str, db_session=None, conversation_id=None) -> Dict[str, Any]:
    plan = parse_intent(message)
    evidence: List[Dict[str, Any]] = []
    for tool_name in plan[:8]:
        try:
            res = tool_mod.execute_tool(tool_name, {}, db_session=db_session)
            evidence.append({"source": res.get("source", tool_name), "data": res.get("data")})
        except Exception as exc:
            evidence.append({"source": tool_name, "data": {"error": str(exc)}})
    # synthesize via LLM with strict grounding instruction
    ev_text = str(evidence)[:6000]
    sys = ("Kamu asisten bisnis. Jawab HANYA berdasarkan EVIDENCE berikut. "
           "Jangan membuat angka baru. Jika evidence kosong, katakan data belum tersedia. "
           "Sertakan tabel ringkas bila relevan. Bahasa: Indonesia.")
    try:
        out = llm_client.chat([
            {"role": "system", "content": sys},
            {"role": "user", "content": f"Pertanyaan: {message}\nEVIDENCE: {ev_text}"},
        ])
        answer = out.get("content", "")
    except Exception:
        answer = _fallback_answer(message, evidence)
    if not answer:
        answer = _fallback_answer(message, evidence)
    # persist conversation
    cid = conversation_id
    try:
        if db_session is not None:
            from app.database.models import AIConversation, AIMessage

            if cid is None:
                conv = AIConversation(title=message[:80])
                db_session.add(conv)
                db_session.commit()
                db_session.refresh(conv)
                cid = conv.id
            db_session.add(AIMessage(conversation_id=cid, role="user", content=message))
            db_session.add(AIMessage(conversation_id=cid, role="assistant", content=answer,
                                     evidence={"evidence": evidence}))
            db_session.commit()
    except Exception:
        try:
            db_session.rollback()
        except Exception:
            pass
    return {"answer": answer, "conversation_id": cid, "evidence": evidence, "steps": len(plan)}


def _fallback_answer(message: str, evidence: List[Dict]) -> str:
    lines = [f"Ringkasan untuk: {message}"]
    for ev in evidence:
        lines.append(f"- Sumber {ev.get('source')}: {str(ev.get('data'))[:400]}")
    lines.append("(LLM offline — jawaban disusun dari evidence langsung.)")
    return "\n".join(lines)
