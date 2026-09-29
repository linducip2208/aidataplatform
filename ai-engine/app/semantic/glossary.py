"""Business glossary: the certified meaning of every metric the AI may quote.

The warehouse computes these numbers in ``app/analytics``; this module is the
one place that says what they *mean*. Every entry is grounded in the function
that produces it (see ``source``), so a definition can never drift from the
implementation without the mismatch being visible here.

Two consumers, both deterministic and LLM-free:

* ``metric_context(question)`` -- definitions for the certified metrics the
  question mentions, prepended to the agent prompt as a ``glossary`` evidence
  block (``app/ai/agent.py::_synthesise``) so the model quotes certified
  formulas instead of inventing its own.
* ``generate_sql`` -- the same definitions ride along with the schema so a
  generated SELECT aggregates the certified way.

Matching is alias-based and conservative: an unknown word matches nothing,
and ``metric_context`` returns ``[]`` rather than the whole book.
"""
from __future__ import annotations

import re
from typing import Any, Dict, List, Optional

GLOSSARY_VERSION = "v1"

# name -> definition. ``formula`` quotes the producing code path verbatim;
# ``source`` names the module/function/endpoint that owns the number.
_METRICS: Dict[str, Dict[str, Any]] = {
    "revenue": {
        "aliases": ["revenue", "pendapatan", "omzet", "omset", "penjualan"],
        "definition": "Total pendapatan kotor dari seluruh baris penjualan pada periode terpilih.",
        "formula": "SUM(fact_sales.revenue)",
        "source": "app/analytics/sales.py::sales_kpi",
        "owner": "data-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "orders": {
        "aliases": ["orders", "pesanan", "order", "transaksi", "transactions"],
        "definition": "Jumlah baris transaksi. Satu baris fact = satu item, bukan satu struk: tanpa kunci order, hitungan baris adalah pendekatan terdekat.",
        "formula": "COUNT(fact_sales.*)",
        "source": "app/analytics/sales.py::sales_kpi",
        "owner": "data-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "units": {
        "aliases": ["units", "unit", "kuantitas", "quantity", "qty", "jumlah barang"],
        "definition": "Total kuantitas item terjual pada periode terpilih.",
        "formula": "SUM(fact_sales.quantity)",
        "source": "app/analytics/sales.py::sales_kpi",
        "owner": "data-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "aov": {
        "aliases": ["aov", "nilai pesanan rata-rata", "rata-rata order", "average order"],
        "definition": "Nilai pesanan rata-rata: pendapatan dibagi jumlah baris transaksi.",
        "formula": "revenue / orders (0 bila orders = 0)",
        "source": "app/analytics/sales.py::sales_kpi",
        "owner": "data-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "growth_pct": {
        "aliases": ["growth", "pertumbuhan", "growth_pct", "kenaikan"],
        "definition": "Pertumbuhan pendapatan paruh kedua vs paruh pertama frame, diurut tanggal; 0 bila paruh pertama tanpa revenue.",
        "formula": "(r2 - r1) / r1 * 100",
        "source": "app/analytics/sales.py::sales_kpi",
        "owner": "data-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "margin_pct": {
        "aliases": ["margin", "margin_pct", "marjin"],
        "definition": "Margin bersih atas pendapatan dari ringkasan keuangan.",
        "formula": "net_profit / total_revenue * 100 (0 bila revenue = 0)",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "total_revenue": {
        "aliases": ["total_revenue", "total pendapatan"],
        "definition": "Total pendapatan pada ringkasan keuangan.",
        "formula": "SUM(fact_sales.revenue)",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "total_cogs": {
        "aliases": ["total_cogs", "hpp", "beban pokok", "cogs", "cost of goods"],
        "definition": "Total beban pokok penjualan dari data pembelian.",
        "formula": "SUM(fact_purchases.cost)",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "total_expenses": {
        "aliases": ["total_expenses", "beban", "expenses", "biaya operasional", "pengeluaran"],
        "definition": "Total beban operasional dari data pengeluaran.",
        "formula": "SUM(fact_expenses.amount)",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "gross_profit": {
        "aliases": ["gross_profit", "laba kotor", "gross"],
        "definition": "Laba kotor: pendapatan dikurangi beban pokok.",
        "formula": "total_revenue - total_cogs",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
    "net_profit": {
        "aliases": ["net_profit", "laba bersih", "net", "profit"],
        "definition": "Laba bersih: laba kotor dikurangi beban operasional.",
        "formula": "gross_profit - total_expenses",
        "source": "app/analytics/finance.py::finance_summary",
        "owner": "finance-team",
        "version": GLOSSARY_VERSION,
        "certified": True,
    },
}

_MAX_CONTEXT_METRICS = 4


def all_metrics() -> List[Dict[str, Any]]:
    """Every certified definition, ordered by name. Read-only view."""
    return [{"name": name, **item} for name, item in sorted(_METRICS.items())]


def _mentions(text: str, alias: str) -> bool:
    return re.search(r"(?<!\w)" + re.escape(alias) + r"(?!\w)", text) is not None


def metric_context(question: Any, limit: int = _MAX_CONTEXT_METRICS) -> List[Dict[str, Any]]:
    """Definitions for the certified metrics ``question`` mentions.

    Longest-alias-first so "nilai pesanan rata-rata" wins over "rata-rata";
    one entry per metric; ``[]`` when nothing certified is mentioned.
    """
    text = str(question or "").casefold()
    if not text.strip():
        return []
    hits: List[Dict[str, Any]] = []
    for name in sorted(_METRICS):
        item = _METRICS[name]
        aliases = sorted(item["aliases"], key=len, reverse=True)
        if any(_mentions(text, a.casefold()) for a in aliases):
            hits.append({"name": name, **item})
        if len(hits) >= limit:
            break
    return hits


def render_context(definitions: List[Dict[str, Any]]) -> str:
    """One prompt-safe block: name, definition, formula, source."""
    lines = ["Definisi metrik tersertifikasi (pakai rumus ini, jangan menebak):"]
    for d in definitions:
        lines.append(
            f"- {d['name']}: {d['definition']} Rumus: {d['formula']} "
            f"(sumber: {d['source']}, {d['version']})."
        )
    return "\n".join(lines)


def glossary_block_for(question: Any) -> Optional[tuple]:
    """A ``(source, text)`` evidence block for the agent prompt, or ``None``."""
    defs = metric_context(question)
    if not defs:
        return None
    return ("glossary", render_context(defs))
