"""Executive summary generator: real KPIs -> LLM narration -> JSON+HTML."""
from __future__ import annotations

from typing import Any, Dict

from app.ai import llm as llm_client


def executive_summary(db_session=None, period: str = "weekly") -> Dict[str, Any]:
    from app.ai import tools as tool_mod

    kpi = tool_mod.execute_tool("get_kpi", {}, db_session=db_session).get("data", {})
    sales = tool_mod.execute_tool("query_sales", {"granularity": "daily"}, db_session=db_session).get("data", [])
    inv = tool_mod.execute_tool("query_inventory", {}, db_session=db_session).get("data", [])
    fin = tool_mod.execute_tool("query_finance", {}, db_session=db_session).get("data", {})
    facts = {"period": period, "kpi": kpi, "trend_points": len(sales), "inventory_items": len(inv), "finance": fin}
    prompt = (f"Buat executive summary {period} dari DATA berikut (jangan tambah angka baru): {facts}. "
              "Format: highlight, risiko, rekomendasi (3-5 poin tiap bagian).")
    try:
        out = llm_client.chat([{"role": "system", "content": "Kamu analis bisnis senior."},
                               {"role": "user", "content": prompt}])
        narrative = out.get("content", "")
    except Exception:
        narrative = ""
    if not narrative:
        narrative = (f"Periode {period}: revenue {kpi.get('revenue', 0)}, orders {kpi.get('orders', 0)}, "
                     f"units {kpi.get('units', 0)}. Net profit {fin.get('net_profit', 0)}.")
    html = f"<html><body><h1>Executive Summary ({period})</h1><p>{narrative}</p></body></html>"
    return {"period": period, "kpi": kpi, "finance": fin, "narrative": narrative, "html": html}
