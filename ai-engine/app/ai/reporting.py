"""Executive summary generator: real KPIs -> LLM narration -> JSON plus escaped HTML.

``executive_summary`` is the engine's only path that produces markup. The narrative is
model output and the period is caller input, so both are stripped of tags and HTML-escaped
before they are interpolated into the document: the ``html`` key is rendered as
``HTMLResponse`` by ``app/api/v1/ai.py`` and must stay safe even if a future Blade view is
ever pointed at it with ``{!! !!}``. The Blade reports page escapes ``narrative`` and
``sections`` itself, so the values here are plain text, never markup.

The function never raises. It backs the reports page, and a 500 there takes out a screen
whose warehouse numbers are still perfectly good, so a failing tool, an unreachable provider
and a partial completion all resolve to the same thing: a complete report built from the
numbers, with ``degraded`` set.
"""
from __future__ import annotations

import html as html_lib
import json
import re
from typing import Any, Dict, List

from app.ai import llm as llm_client
from app.ai.tools import _clip, _jsonable
from app.core.logging import get_logger

log = get_logger("ai.reporting", "executive_summary")

PERIODS = ("daily", "weekly", "monthly")
DEFAULT_PERIOD = "weekly"
MAX_NARRATIVE_CHARS = 20000
MAX_SECTION_ITEMS = 8
MAX_SECTION_ITEM_CHARS = 400
MAX_FACT_CHARS = 4000
SECTION_KEYS = ("highlight", "risiko", "rekomendasi")

_TAG_RE = re.compile(r"<[^>]*>")
_CONTROL_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")

SYSTEM_PROMPT = (
    "Kamu analis bisnis senior. Balas hanya dengan objek JSONvalid dengan kunci: "
    "narrative (string, 3-6 kalimat bahasa Indonesia), highlight (array of string), "
    "risiko (array of string), rekomendasi (array of string). "
    "Jangan membuat angka yang tidak ada di blok data. Tanpa HTML dan tanpa blok kode."
)


def _normalise_period(period: Any) -> str:
    """Return ``period`` if it is one of the three known buckets, else the default."""
    value = str(period or "").strip().lower()
    return value if value in PERIODS else DEFAULT_PERIOD


def _as_rows(data: Any) -> List[Any]:
    """Return the row list behind a tool result, whatever shape the tool used.

    ``app.ai.tools.evidence_data`` wraps a list result as ``{"rows": [...], "count": n}``,
    and a single mapping stays a mapping, so both cases are handled here.
    """
    if isinstance(data, dict):
        rows = data.get("rows")
        return rows if isinstance(rows, list) else []
    return data if isinstance(data, list) else []


def _tool_data(tool_mod: Any, name: str, args: Dict[str, Any], db_session: Any) -> Any:
    """Run one tool and return its ``data`` payload, or ``{}`` when the tool cannot answer.

    ``executive_summary`` is the backing call for the reports page, so a tool that raises --
    an analytics import that fails, a session that has already been closed, a shape change
    in ``execute_tool`` -- must not propagate. An uncaught exception here is a 500 on a page
    whose numbers would otherwise still render. Only the exception type is logged, never the
    message: a SQLAlchemy error text carries the statement, its bound parameters and
    sometimes the DSN.
    """
    try:
        result = tool_mod.execute_tool(name, args, db_session=db_session)
    except Exception as exc:
        log.warning(f"report tool {name} failed, reporting it as empty: {type(exc).__name__}")
        return {}
    if not isinstance(result, dict):
        log.warning(f"report tool {name} returned {type(result).__name__}, not a mapping")
        return {}
    return result.get("data", {})


def _clean_text(value: Any, limit: int) -> str:
    """Strip tags and control characters from model or caller text and cap its length.

    Line breaks survive because ``reports/index.blade.php`` renders the narrative with
    ``whitespace-pre-line``.
    """
    text = _CONTROL_RE.sub(" ", str(value or ""))
    text = _TAG_RE.sub(" ", text)
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return _clip(text.strip(), limit)


def _clean_items(value: Any) -> List[str]:
    """Coerce a model-supplied section into a list of short plain-text strings."""
    if isinstance(value, str):
        value = [value]
    if not isinstance(value, list):
        return []
    out: List[str] = []
    for item in value[:MAX_SECTION_ITEMS]:
        text = item if isinstance(item, str) else json.dumps(_jsonable(item), ensure_ascii=False, default=str)
        text = _clean_text(text, MAX_SECTION_ITEM_CHARS)
        if text:
            out.append(text)
    return out


def _fallback_narrative(period: str, kpi: Dict[str, Any], finance: Dict[str, Any]) -> str:
    """Build the narrative from the warehouse numbers when no LLM is available.

    It interpolates only values the tools returned, so the offline path cannot invent a
    figure, and it states plainly that the text was assembled without a model.
    """
    return (f"Periode {period}: revenue {kpi.get('revenue', 0)}, orders {kpi.get('orders', 0)}, "
            f"unit {kpi.get('units', 0)}, nilai pesanan rata-rata {kpi.get('aov', 0)}. "
            f"Pertumbuhan {kpi.get('growth_pct', 0)}%, margin {kpi.get('margin_pct', 0)}%. "
            f"Laba bersih {finance.get('net_profit', 0)}.")


def _fallback_sections(kpi: Dict[str, Any], finance: Dict[str, Any], period: str) -> Dict[str, List[str]]:
    """Derive the per-section detail from the same numbers as the narrative."""
    highlight = [
        f"Revenue {kpi.get('revenue', 0)} dari {kpi.get('orders', 0)} pesanan.",
        f"Rata-rata nilai pesanan {kpi.get('aov', 0)}, unit terjual {kpi.get('units', 0)}.",
    ]
    risiko = [
        f"Pertumbuhan tercatat {kpi.get('growth_pct', 0)}%.",
        f"Margin tercatat {kpi.get('margin_pct', 0)}%.",
    ]
    rekomendasi = [
        f"Verifikasi angka periode {period} pada data kas sebelum dipakai mengambil keputusan.",
    ]
    if finance.get("net_profit") is not None:
        rekomendasi.append(f"Laba bersih tercatat {finance.get('net_profit')}.")
    return {"highlight": highlight, "risiko": risiko, "rekomendasi": rekomendasi}


def _parse_sections(content: str) -> Dict[str, Any]:
    """Pull the JSON object out of a completion, tolerating a fenced or padded reply.

    Returns ``{}`` when the model produced anything that is not a JSON object, so the
    caller falls back to the deterministic narrative.
    """
    text = (content or "").strip()
    if text.startswith("```"):
        text = re.sub(r"^```[a-zA-Z]*\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end <= start:
        return {}
    try:
        parsed = json.loads(text[start:end + 1])
    except Exception:
        return {}
    return parsed if isinstance(parsed, dict) else {}


def _render_html(period: str, narrative: str) -> str:
    """Wrap an escaped narrative in a fixed document skeleton.

    ``period`` is re-validated through :func:`_normalise_period` on the way in as well as
    escaped, so the two controls are independent: the allow-list is what makes the value
    known, the escaping is what makes it safe if the allow-list is ever widened. Both
    interpolated values are escaped, so model output cannot introduce an element, an
    attribute or a ``javascript:`` URL into the response.
    """
    safe_period = html_lib.escape(_normalise_period(period))
    return (
        "<!DOCTYPE html><html lang=\"id\"><head><meta charset=\"utf-8\">"
        f"<title>Executive Summary ({safe_period})</title></head><body>"
        f"<h1>Executive Summary ({safe_period})</h1>"
        f"<p>{html_lib.escape(narrative).replace(chr(10), '<br>')}</p>"
        "</body></html>"
    )


def executive_summary(db_session=None, period: str = DEFAULT_PERIOD) -> Dict[str, Any]:
    """Summarise the warehouse for ``period``.

    Returns ``{"period": str, "kpi": dict, "finance": dict, "narrative": str, "sections":
    {"highlight": [...], "risiko": [...], "rekomendasi": [...]}, "html": str, "degraded":
    bool}`` — the keys ``app/api/v1/ai.py`` forwards and ``reports/index.blade.php``
    renders. Every value is JSON-serialisable because the Celery task returns this mapping
    through the result backend.

    This function never raises. Every tool call is isolated by :func:`_tool_data`, the
    completion is optional, and a missing narrative or a missing section is filled from the
    same warehouse numbers, so an environment with no ``LLM_API_KEY``, an unreachable
    provider or a failing analytics import still renders a complete report with
    ``degraded=True``.

    ``period`` is the only caller-supplied value that reaches the document. There is no
    ``branch`` parameter: ``ReportRequest`` accepts one, but ``app/api/v1/ai.py`` does not
    forward it, so today no branch name can reach this HTML. When that is wired up it has to
    go through the same ``_normalise_period`` allow-list plus ``html.escape``.
    """
    from app.ai import tools as tool_mod

    period = _normalise_period(period)
    kpi_raw = _tool_data(tool_mod, "get_kpi", {}, db_session)
    sales_raw = _tool_data(tool_mod, "query_sales", {"granularity": "daily"}, db_session)
    inv_raw = _tool_data(tool_mod, "query_inventory", {}, db_session)
    fin_raw = _tool_data(tool_mod, "query_finance", {}, db_session)

    kpi = _jsonable(kpi_raw) if isinstance(kpi_raw, dict) else {}
    finance = _jsonable(fin_raw) if isinstance(fin_raw, dict) else {}
    sales = _as_rows(sales_raw)
    inventory = _as_rows(inv_raw)
    facts = {"period": period, "kpi": kpi, "trend_points": len(sales),
             "inventory_items": len(inventory), "finance": finance}
    fact_text = json.dumps(facts, ensure_ascii=False, default=str)[:MAX_FACT_CHARS]

    narrative = ""
    sections: Dict[str, List[str]] = {}
    try:
        out = llm_client.chat([
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": (
                "Buat executive summary dari blok DATA berikut. Jangan menambah angka baru.\n"
                "<data>\n" + fact_text + "\n</data>\n"
                "Format: narrative, highlight, risiko, rekomendasi (3-5 poin tiap bagian).")},
        ], json_mode=True)
        if isinstance(out, dict) and not out.get("offline"):
            parsed = _parse_sections(out.get("content") or "")
            narrative = _clean_text(parsed.get("narrative") or "", MAX_NARRATIVE_CHARS)
            sections = {k: _clean_items(parsed.get(k)) for k in SECTION_KEYS}
    except Exception as exc:
        log.warning(f"report narration failed, using warehouse numbers: {type(exc).__name__}")
        narrative = ""

    # A completion that carries only a narrative is a partial answer, not a finished
    # report. Any missing piece is filled from the same numbers, and the report is marked
    # degraded so the page can say so rather than rendering three empty sections.
    fallback_narrative = _fallback_narrative(period, kpi, finance)
    fallback_sections = _fallback_sections(kpi, finance, period)
    degraded = not narrative
    if degraded:
        narrative = fallback_narrative
    for key in SECTION_KEYS:
        if not sections.get(key):
            sections[key] = fallback_sections[key]
            degraded = True
    narrative = _clean_text(narrative, MAX_NARRATIVE_CHARS) or fallback_narrative

    return {"period": period, "kpi": kpi, "finance": finance, "narrative": narrative,
            "sections": sections, "html": _render_html(period, narrative),
            "degraded": degraded}
