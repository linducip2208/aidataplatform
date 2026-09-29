#!/usr/bin/env python3
"""Stub AI engine for local development without Docker.

This is a DEVELOPMENT AID ONLY. It serves the subset of ``/api/v1/*`` that the
Blade pages call on render, with fixed plausible Indonesian retail data, so a
contributor with no Python environment, no Postgres, no Redis and no running
FastAPI service can still see populated pages.

IT DOES NOT AUTHENTICATE. Any ``X-Service-Key`` header is accepted and never
checked, exactly as the real engine's dependency would never see it, because
this process has no key material at all. The only thing that stands between a
network and the data served here is the fact that this stub is bound to
loopback. That is why it refuses to start on a non-loopback host unless
``--allow-remote`` is passed. Never run this on a shared, staging or
production host, and never let ``AI_ENGINE_URL`` point at it outside a local
checkout.

What is faithful to the real engine, and what is not:

* The ``{"success": true, "data": ...}`` envelope is used everywhere except
  ``/health``, ``/readiness`` and ``/liveness``, which answer with a bare
  object. That asymmetry is real (``AiEngineClient::decode()`` versus
  ``unwrap()``); a stub that enveloped them would teach the wrong contract.
* Every response key matches the pydantic schemas in ``ai-engine/app/schemas``.
  The values are invented. The keys are not.
* ``/forecast``, ``/customers/*``, ``/inventory/health``, ``/anomaly/detect``,
  ``/recommend``, ``/training/*`` and ``/alerts/*`` are NOT stubbed and answer
  ``501`` with an explicit message rather than plausible-looking fiction.

Run it from the repository root::

    python dev/stub-engine.py --port 8010

Then point Laravel at it::

    AI_ENGINE_URL=http://127.0.0.1:8010

See ``dev/README.md`` for the endpoint list and the scenario flags.
"""
from __future__ import annotations

import argparse
import html as html_lib
import json
import random
import re
import sys
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

API_PREFIX = "/api/v1"
HISTORY_DAYS = 180
FIXED_IMPORT_JOB_ID = 1

LOOPBACK_HOSTS = {"127.0.0.1", "::1", "localhost", "localhost.localdomain"}

BRANCHES = ("Cabang Kemang", "Cabang Casablanca")
CATEGORIES = ("Sembako", "Minuman", "Kebutuhan Rumah", "Perawatan Diri",
              "Kertas & Tisu", "Makanan Ringan")

PRODUCTS = (
    ("Beras Pandan Wangi 5 kg", "Sembako", 68_000, 61_500),
    ("Minyak Goreng 2 L", "Sembako", 36_500, 31_200),
    ("Gula Pasir 1 kg", "Sembako", 15_500, 13_100),
    ("Telur Ayam 1 kg", "Sembako", 29_000, 25_400),
    ("Aqua Botol 600 ml", "Minuman", 4_000, 2_900),
    ("Teh Botol Sosro 450 ml", "Minuman", 5_000, 3_600),
    ("Susu Ultra Milk 1 L", "Minuman", 19_500, 17_200),
    ("Kopi Kapal Api Special", "Minuman", 15_000, 12_800),
    ("Sabun Lifebuoy 825 g", "Kebutuhan Rumah", 17_500, 14_300),
    ("Deterjen Rinso 800 g", "Kebutuhan Rumah", 22_000, 18_900),
    ("Pewangi Fabreeze 300 ml", "Kebutuhan Rumah", 13_500, 10_800),
    ("Galon Isi Ulang 19 L", "Kebutuhan Rumah", 6_500, 5_200),
    ("Shampo Lifebuoy 170 ml", "Perawatan Diri", 12_000, 9_700),
    ("Pasta Gigi Pepsodent 190 g", "Perawatan Diri", 16_500, 13_200),
    ("Sabun Mandi Lifebuoy 70 g", "Perawatan Diri", 4_500, 3_300),
    ("Tisu Basah 250s", "Kertas & Tisu", 11_000, 8_600),
    ("Kertas Toilet 12 rol", "Kertas & Tisu", 34_000, 28_700),
    ("Indomie Goreng", "Makanan Ringan", 3_500, 2_700),
    ("Keripik Kentang 68 g", "Makanan Ringan", 10_000, 7_900),
    ("Biskuit Kelapa 300 g", "Makanan Ringan", 18_000, 15_100),
    ("Mi Instan(batch isi 5)", "Makanan Ringan", 14_000, 11_600),
)

CUSTOMERS = (
    "Budi Santoso", "Siti Rahayu", "Agus Prasetyo", "Dewi Lestari",
    "Rudi Hartono", "Nur Aisyah", "Andi Wijaya", "Maya Sari",
    "Joko Susilo", "Rina Kartika", "Hendra Gunawan", "Lina Marlina",
    "Fajar Nugroho", "Tuti Herawati", "Eko Purnomo", "Sri Wahyuni",
)

BASE_DAILY_CATEGORY = {
    "Sembako": 18_500_000,
    "Minuman": 12_200_000,
    "Kebutuhan Rumah": 9_400_000,
    "Perawatan Diri": 6_800_000,
    "Kertas & Tisu": 4_600_000,
    "Makanan Ringan": 7_300_000,
}

BRANCH_WEIGHT = {"Cabang Kemang": 1.0, "Cabang Casablanca": 0.68}
MARGIN_RATE = 0.312
EXPENSE_RATE = 0.146
MARGIN_PCT = 24.6

RFM_RULE = [
    (1, 5), (2, 4), (3, 3), (4, 2), (5, 1),
]

COLUMN_ALIASES = {
    "tanggal transaksi": "transaction_date", "tanggal": "transaction_date",
    "tgl": "transaction_date", "date": "transaction_date",
    "kode customer": "customer_code", "kode pelanggan": "customer_code",
    "nama customer": "customer_name", "nama pelanggan": "customer_name",
    "pelanggan": "customer_name", "customer": "customer_name",
    "kode barang": "product_code", "kode produk": "product_code",
    "sku": "product_code", "nama barang": "product_name",
    "nama produk": "product_name", "barang": "product_name",
    "kategori": "category", "jenis": "category",
    "cabang": "branch_name", "nama cabang": "branch_name", "branch": "branch_name",
    "jumlah": "quantity", "qty": "quantity",
    "harga jual": "selling_price", "harga": "selling_price",
    "diskon": "discount", "disc": "discount",
    "omzet": "revenue", "total": "revenue", "nilai": "revenue",
    "revenue": "revenue",
}

PREVIEW_COLUMNS = (
    ("tanggal_transaksi", "datetime", 0, 12),
    ("kode_pelanggan", "object", 3, 148),
    ("nama_pelanggan", "object", 0, 151),
    ("kode_barang", "object", 0, 64),
    ("nama_barang", "object", 0, 64),
    ("cabang", "object", 0, 2),
    ("jumlah", "int64", 7, 96),
    ("harga_jual", "float64", 0, 58_000),
    ("diskon", "float64", 24, 15_000),
    ("omzet", "float64", 0, 4_850_000),
)

SAMPLE_ROWS = [
    {"tanggal_transaksi": "2026-08-03 09:14:00", "kode_pelanggan": "C-0007",
     "nama_pelanggan": "Budi Santoso", "kode_barang": "P-0011",
     "nama_barang": "Indomie Goreng", "cabang": "Cabang Kemang",
     "jumlah": 12, "harga_jual": 3500, "diskon": 0, "omzet": 42000},
    {"tanggal_transaksi": "2026-08-03 11:52:00", "kode_pelanggan": "C-0031",
     "nama_pelanggan": "Rina Kartika", "kode_barang": "P-0021",
     "nama_barang": "Beras Pandan Wangi 5 kg", "cabang": "Cabang Casablanca",
     "jumlah": 2, "harga_jual": 68000, "diskon": 5000, "omzet": 131000},
    {"tanggal_transaksi": "2026-08-04 08:07:00", "kode_pelanggan": "C-0102",
     "nama_pelanggan": "Eko Purnomo", "kode_barang": "P-0004",
     "nama_barang": "Aqua Botol 600 ml", "cabang": "Cabang Kemang",
     "jumlah": 24, "harga_jual": 4000, "diskon": 0, "omzet": 96000},
    {"tanggal_transaksi": "2026-08-04 16:31:00", "kode_pelanggan": "C-0055",
     "nama_pelanggan": "Maya Sari", "kode_barang": "P-0017",
     "nama_barang": "Kertas Toilet 12 rol", "cabang": "Cabang Casablanca",
     "jumlah": 1, "harga_jual": 34000, "diskon": 2000, "omzet": 32000},
    {"tanggal_transaksi": "2026-08-05 10:19:00", "kode_pelanggan": "C-0019",
     "nama_pelanggan": "Agus Prasetyo", "kode_barang": "P-0009",
     "nama_barang": "Susu Ultra Milk 1 L", "cabang": "Cabang Kemang",
     "jumlah": 6, "harga_jual": 19500, "diskon": 0, "omzet": 117000},
]

MODELS = [
    {"id": 4, "name": "forecast-revenue-harian", "model_type": "forecast",
     "status": "PRODUCTION", "production_version_id": 41},
    {"id": 3, "name": "churn-pelanggan-rfm", "model_type": "churn",
     "status": "VALIDATED", "production_version_id": None},
    {"id": 2, "name": "segmentasi-pelanggan-kmeans", "model_type": "segmentation",
     "status": "PRODUCTION", "production_version_id": 27},
    {"id": 1, "name": "anomaly-omzet", "model_type": "anomaly",
     "status": "TRAINING", "production_version_id": None},
]

MODEL_VERSIONS = {
    4: [
        {"id": 41, "version": "v3", "status": "PRODUCTION",
         "metrics": {"mape": 4.82, "rmse": 1_284_500.0, "wape": 3.91, "trained_rows": 184_920},
         "artifact_path": "models/forecast-revenue-harian/v3.pkl"},
        {"id": 34, "version": "v2", "status": "ARCHIVED",
         "metrics": {"mape": 6.14, "rmse": 1_612_300.0, "wape": 4.88, "trained_rows": 151_004},
         "artifact_path": "models/forecast-revenue-harian/v2.pkl"},
    ],
    3: [
        {"id": 33, "version": "v2", "status": "VALIDATED",
         "metrics": {"auc": 0.842, "precision": 0.611, "recall": 0.577, "f1": 0.593},
         "artifact_path": "models/churn-pelanggan-rfm/v2.pkl"},
    ],
    2: [
        {"id": 27, "version": "v1", "status": "PRODUCTION",
         "metrics": {"silhouette": 0.412, "inertia": 18_940.0, "n_clusters": 4},
         "artifact_path": "models/segmentasi-pelanggan-kmeans/v1.pkl"},
    ],
    1: [
        {"id": 12, "version": "v1", "status": "DRAFT",
         "metrics": {"precision": 0.489, "recall": 0.503, "alerts_30d": 27},
         "artifact_path": "models/anomaly-omzet/v1.pkl"},
    ],
}


class Scenario:
    """Behaviour switch so empty and error states can be worked on deliberately."""

    NORMAL = "normal"
    EMPTY = "empty"
    ERROR = "error"

    CHOICES = (NORMAL, EMPTY, ERROR)

    def __init__(self, name: str) -> None:
        self.name = name

    @property
    def is_empty(self) -> bool:
        return self.name == self.EMPTY

    @property
    def is_error(self) -> bool:
        return self.name == self.ERROR


def envelope(data) -> dict:
    return {"success": True, "data": data}


def error_body(message: str, *, code: str, operation: str,
               status_code: int = 500, resolution: str = "",
               technical: str = "", error_type: str = "") -> dict:
    """The failure envelope of ``app/core/errors.py`` (``build_error_response``)."""
    if not error_type:
        error_type = "internal" if status_code >= 500 else "stub"
    return {
        "success": False,
        "error": {
            "module": "stub_engine",
            "operation": operation,
            "error_type": error_type,
            "code": code,
            "message": message,
            "technical": technical or "Detail suppressed; correlate on request_id in the engine log.",
            "request_id": "-",
            "resolution": resolution,
            "details": {},
        },
    }


class Dataset:
    """A fixed, generated warehouse slice. Built once, never mutated."""

    def __init__(self, seed: int) -> None:
        rnd = random.Random(seed)
        today = date.today()
        start = today - timedelta(days=HISTORY_DAYS - 1)
        self.start = start
        self.end = today
        self.days: list[date] = [start + timedelta(days=i) for i in range(HISTORY_DAYS)]
        self.level: dict[tuple[date, str, str], float] = {}

        for branch in BRANCHES:
            for category in CATEGORIES:
                value = BASE_DAILY_CATEGORY[category] * BRANCH_WEIGHT[branch]
                for day in self.days:
                    growth = 1.0 + rnd.uniform(-0.055, 0.062)
                    value = max(1_000_000.0, value * growth)
                    seasonal = 1.0
                    if day.weekday() >= 5:
                        seasonal = 1.34
                    if day.day >= 25:
                        seasonal *= 1.21
                    if day.day == 1:
                        seasonal *= 0.72
                    self.level[(day, branch, category)] = value * seasonal

    def series(self, branch: str | None = None, category: str | None = None,
               date_from: date | None = None, date_to: date | None = None):
        """Yield ``(day, revenue, orders, units)`` rows inside the filter window."""
        branches = (branch,) if branch else BRANCHES
        categories = (category,) if category else CATEGORIES
        for day in self.days:
            if date_from and day < date_from:
                continue
            if date_to and day > date_to:
                continue
            revenue = 0.0
            for b in branches:
                for c in categories:
                    revenue += self.level.get((day, b, c), 0.0)
            orders = max(1, int(revenue / 78_400.0))
            units = int(orders * 2.6)
            yield day, revenue, orders, units

    def branch_totals(self):
        """Per-branch revenue/orders over the whole window, revenue desc."""
        totals = {b: [0.0, 0] for b in BRANCHES}
        for day in self.days:
            for branch in BRANCHES:
                revenue = sum(self.level.get((day, branch, c), 0.0) for c in CATEGORIES)
                totals[branch][0] += revenue
                totals[branch][1] += max(1, int(revenue / 78_400.0))
        grand = sum(v[0] for v in totals.values()) or 1.0
        rows = [
            {
                "branch": branch,
                "revenue": round(revenue, 2),
                "orders": int(orders),
                "share_pct": round(revenue / grand * 100, 2),
            }
            for branch, (revenue, orders) in totals.items()
        ]
        return sorted(rows, key=lambda r: r["revenue"], reverse=True)


def parse_date(value) -> date | None:
    if not value:
        return None
    text = str(value).strip()[:10]
    try:
        return date.fromisoformat(text)
    except ValueError:
        return None


def period_key(day: date, granularity: str) -> str:
    """Period labels matching pandas ``to_period`` for daily/weekly/monthly."""
    gran = (granularity or "daily").lower()
    if gran == "monthly":
        return f"{day.year:04d}-{day.month:02d}"
    if gran == "weekly":
        end = day + timedelta(days=6 - day.weekday())
        start = end - timedelta(days=6)
        return f"{start.isoformat()}/{end.isoformat()}"
    return day.isoformat()


def kpi_payload(data: Dataset, branch, category, date_from, date_to, scenario: Scenario) -> dict:
    if scenario.is_empty:
        return {"revenue": 0.0, "orders": 0, "units": 0.0, "aov": 0.0,
                "growth_pct": 0.0, "margin_pct": 0.0}
    rows = list(data.series(branch, category, date_from, date_to))
    if not rows:
        return {"revenue": 0.0, "orders": 0, "units": 0.0, "aov": 0.0,
                "growth_pct": 0.0, "margin_pct": 0.0}
    revenue = sum(r[1] for r in rows)
    orders = sum(r[2] for r in rows)
    units = float(sum(r[3] for r in rows))
    half = len(rows) // 2
    first = sum(r[1] for r in rows[:half])
    second = sum(r[1] for r in rows[half:])
    growth = ((second - first) / first * 100.0) if first else 0.0
    return {
        "revenue": round(revenue, 2),
        "orders": int(orders),
        "units": round(units, 2),
        "aov": round(revenue / orders, 2) if orders else 0.0,
        "growth_pct": round(growth, 2),
        "margin_pct": MARGIN_PCT,
    }


def trend_payload(data: Dataset, branch, category, date_from, date_to,
                  granularity, scenario: Scenario) -> list[dict]:
    if scenario.is_empty:
        return []
    buckets: dict[str, list[float]] = {}
    for day, revenue, orders, units in data.series(branch, category, date_from, date_to):
        key = period_key(day, granularity)
        acc = buckets.setdefault(key, [0.0, 0.0, 0.0])
        acc[0] += revenue
        acc[1] += orders
        acc[2] += units
    return [
        {
            "period": key,
            "revenue": round(acc[0], 2),
            "orders": int(acc[1]),
            "units": round(acc[2], 2),
        }
        for key, acc in sorted(buckets.items())
    ]


def rfm_payload(data: Dataset, branch, category, date_from, date_to,
                scenario: Scenario) -> list[dict]:
    if scenario.is_empty:
        return []
    rows = []
    for index, name in enumerate(CUSTOMERS):
        recency = 2 + ((index * 13) % 47)
        frequency = 3 + ((index * 7) % 58)
        monetary = 480_000.0 + ((index * 271_000) % 6_400_000)
        r_score = 5 - int((recency - 1) / 10)
        f_score = 1 + int((frequency - 1) / 12)
        m_score = 1 + int((monetary - 1) / 1_400_000)
        r_score = max(1, min(5, r_score))
        f_score = max(1, min(5, f_score))
        m_score = max(1, min(5, m_score))
        total = r_score + f_score + m_score
        if total >= 13:
            segment = "champions"
        elif total >= 11:
            segment = "loyal"
        elif r_score <= 2:
            segment = "at_risk"
        elif f_score <= 2:
            segment = "new"
        else:
            segment = "potential"
        rows.append({
            "customer": name,
            "recency_days": int(recency),
            "frequency": int(frequency),
            "monetary": round(monetary, 2),
            "r_score": r_score,
            "f_score": f_score,
            "m_score": m_score,
            "segment": segment,
        })
    return sorted(rows, key=lambda r: r["monetary"], reverse=True)


def abc_payload(data: Dataset, branch, category, date_from, date_to,
                scenario: Scenario) -> list[dict]:
    if scenario.is_empty:
        return []
    totals = [(name, float(price) * (37 + (index * 23) % 180))
              for index, (name, _cat, price, _cost) in enumerate(PRODUCTS)]
    totals.sort(key=lambda row: row[1], reverse=True)
    grand = sum(v for _, v in totals) or 1.0
    cumulative = 0.0
    rows = []
    for name, revenue in totals:
        share = revenue / grand * 100.0
        cumulative += share
        grade = "A" if cumulative <= 80 else ("B" if cumulative <= 95 else "C")
        rows.append({
            "product": name,
            "revenue": round(revenue, 2),
            "share_pct": round(share, 2),
            "cumulative_pct": round(cumulative, 2),
            "grade": grade,
        })
    return rows


def cohort_payload(data: Dataset, scenario: Scenario) -> list[dict]:
    if scenario.is_empty:
        return []
    rows = []
    for back in range(5, -1, -1):
        month = data.end.month - back
        year = data.end.year
        while month <= 0:
            month += 12
            year -= 1
        cohort = f"{year:04d}-{month:02d}"
        base = 120 + ((5 - back) * 37) % 160
        for offset in range(0, 7 - back):
            retained = max(0, int(base * (0.74 ** offset) - offset * 4))
            rows.append({
                "cohort": cohort,
                "period_offset": offset,
                "retention_pct": round(retained / base * 100, 2) if base else 0.0,
                "active_customers": retained,
            })
    return rows


def finance_payload(data: Dataset, scenario: Scenario) -> dict:
    if scenario.is_empty:
        return {"total_revenue": 0.0, "total_cogs": 0.0, "total_expenses": 0.0,
                "gross_profit": 0.0, "net_profit": 0.0, "margin_pct": 0.0}
    revenue = sum(v for (day, _b, _c), v in data.level.items())
    cogs = revenue * (1.0 - MARGIN_RATE)
    expenses = revenue * EXPENSE_RATE
    gross = revenue - cogs
    net = gross - expenses
    return {
        "total_revenue": round(revenue, 2),
        "total_cogs": round(cogs, 2),
        "total_expenses": round(expenses, 2),
        "gross_profit": round(gross, 2),
        "net_profit": round(net, 2),
        "margin_pct": round(net / revenue * 100.0, 2) if revenue else 0.0,
    }


def rupiah(value: float) -> str:
    return f"{value:,.0f}".replace(",", ".")


def chat_payload(data: Dataset, message: str, conversation_id,
                 scenario: Scenario) -> dict:
    if scenario.is_empty:
        answer = (
            "Warehouse masih kosong, jadi belum ada angka yang bisa saya "
            "laporkan. Unggah dataset penjualan terlebih dahulu, lalu ajukan "
            "pertanyaan kembali."
        )
        evidence: list[dict] = []
        steps = 0
    else:
        kpi = kpi_payload(data, None, None, None, None, scenario)
        branches = data.branch_totals()
        top_branch = branches[0]
        per_day = kpi["orders"] / HISTORY_DAYS
        answer = (
            f"Omzet {HISTORY_DAYS // 30} bulan terakhir tercatat "
            f"{rupiah(kpi['revenue'])} rupiah dari {rupiah(kpi['orders'])} pesanan, "
            f"atau sekitar {rupiah(per_day)} pesanan per hari, dengan nilai "
            f"pesanan rata-rata {rupiah(kpi['aov'])} rupiah. Pertumbuhan antar "
            f"semester {kpi['growth_pct']}% dan margin {kpi['margin_pct']}%. "
            f"{top_branch['branch']} menyumbang {top_branch['share_pct']} persen "
            "omzet dan menjadi penyumbang terbesar. Kategori Sembako dan Minuman "
            "menyumbang porsi terbesar dari nilai transaksi. Rekomendasi: "
            "fokuskan promo akhir bulan pada produk kelas A dan tinjau ulang "
            "harga beli tiga produk yang persaingannya kuat."
        )
        evidence = [
            {"source": "get_kpi", "data": dict(kpi)},
            {"source": "query_sales", "data": {
                "rows": HISTORY_DAYS, "granularity": "daily",
                "first_period": data.start.isoformat(),
                "last_period": data.end.isoformat()}},
            {"source": "query_inventory", "data": {
                "items": len(PRODUCTS), "below_reorder_point": 5, "dead_stock": 2}},
        ]
        steps = 3
    return {
        "answer": answer,
        "conversation_id": conversation_id if conversation_id is not None else 1,
        "evidence": evidence,
        "steps": steps,
    }


def report_html(period: str, narrative: str) -> str:
    """The same fixed skeleton ``app/ai/reporting.py`` emits, with escaping."""
    return (
        "<!DOCTYPE html><html lang=\"id\"><head><meta charset=\"utf-8\">"
        f"<title>Executive Summary ({html_lib.escape(period)})</title></head><body>"
        f"<h1>Executive Summary ({html_lib.escape(period)})</h1>"
        f"<p>{html_lib.escape(narrative).replace(chr(10), '<br>')}</p>"
        "</body></html>"
    )


def report_payload(data: Dataset, period: str, branch, scenario: Scenario) -> dict:
    period = period if period in ("daily", "weekly", "monthly") else "weekly"
    kpi = kpi_payload(data, branch, None, None, None, scenario)
    finance = finance_payload(data, scenario)
    degraded = scenario.is_empty
    narrative = (
        f"Periode {period}: revenue {kpi['revenue']}, orders {kpi['orders']}, "
        f"unit {kpi['units']}, nilai pesanan rata-rata {kpi['aov']}. "
        f"Pertumbuhan {kpi['growth_pct']}%, margin {kpi['margin_pct']}%. "
        f"Laba bersih {finance['net_profit']}."
    )
    return {
        "period": period,
        "kpi": kpi,
        "finance": finance,
        "narrative": narrative,
        "sections": {
            "highlight": [
                f"Revenue {kpi['revenue']} dari {kpi['orders']} pesanan.",
                f"Rata-rata nilai pesanan {kpi['aov']}, unit terjual {kpi['units']}.",
                "Cabang Kemang menyumbang porsi terbesar terhadap omzet gabungan.",
            ],
            "risiko": [
                f"Pertumbuhan tercatat {kpi['growth_pct']}%.",
                f"Margin tercatat {kpi['margin_pct']}%.",
                "Lima produk berada di bawah titik pesan ulang.",
            ],
            "rekomendasi": [
                f"Verifikasi angka periode {period} pada data kas sebelum dipakai mengambil keputusan.",
                f"Laba bersih tercatat {finance['net_profit']}.",
                "Tinjau ulang harga beli tiga produk paling bersaing.",
            ],
        },
        "html": report_html(period, narrative),
        "degraded": degraded,
    }


def rag_payload(query: str, top_k: int, scenario: Scenario) -> dict:
    top_k = max(1, min(20, int(top_k or 5)))
    if scenario.is_empty:
        return {"answer": "Tidak ada dokumen yang bisa dicari, basis pengetahuan masih kosong.",
                "citations": [], "chunks": [], "n_results": 0}
    seeds = [
        (1, "StandarOperasional.md#alur-pengajuan-retur",
         "Retur barang}&\nRetur diterima maksimal 7 hari setelah pembelian dengan nota asli. "
         "Barang yang sudah dibuka tidak dapat diretur. Proses retur dicatat oleh "
         "Kasir dan disetujui Supervisor pada hari yang sama."),
        (2, "kebijakan-harga.md#aturan-diskon",
         "Diskon total tidak melebihi 15 persen dari harga jual. Diskon di atas "
         "nilai itu wajib dengan persetujuan Supervisor dan dicatat pada kolom diskon."),
        (3, "panduan-gudang.md#stok-menipis",
         "Pemenuhan ulang dilakukan saat stock_qty berada di bawah titik pesan ulang. "
         "Produk kelas A memiliki lead time 2 hari, produk kelas C 7 hari."),
    ]
    citations = [
        {"content": content, "score": round(0.91 - index * 0.13, 4),
         "document_id": document_id, "chunk_index": index}
        for index, (document_id, _title, content) in enumerate(seeds[:top_k])
    ]
    answer = (
        f"Berdasarkan {len(citations)} dokumen internal, prosedur terkait \"{query}\" "
        "mengikuti alur standar tertulis: pengajuan dicatat oleh Kasir, diperiksa "
        "kelengkapan dokumennya, lalu disetujui Supervisor. Deviasi dari alur "
        "tersebut harus dicatat pada log operasional harian."
    )
    return {"answer": answer, "citations": citations, "chunks": citations,
            "n_results": len(citations)}


def suggest_mapping(columns, dataset_type: str = "sales") -> list[dict]:
    rows = []
    for column in columns or []:
        target = COLUMN_ALIASES.get(str(column).strip().lower())
        if target:
            rows.append({"source_column": str(column), "target_field": target,
                         "confidence": 0.93, "method": "exact"})
        else:
            rows.append({"source_column": str(column), "target_field": None,
                         "confidence": 0.0, "method": "none"})
    return rows


def preview_payload() -> dict:
    columns = [
        {
            "name": name,
            "dtype": dtype,
            "missing": missing,
            "missing_pct": round(missing / 1480.0 * 100, 2),
            "unique": unique,
            "sample": [SAMPLE_ROWS[index % len(SAMPLE_ROWS)][name]
                       for index in range(min(3, len(SAMPLE_ROWS)))],
        }
        for name, dtype, missing, unique in PREVIEW_COLUMNS
    ]
    return {
        "filename": "penjualan_agustus_2026.csv",
        "size_bytes": 284_916,
        "row_count": 1480,
        "column_count": len(columns),
        "columns": columns,
        "sample_rows": SAMPLE_ROWS,
        "duplicate_count": 12,
        "warnings": ["12 baris terduplikasi terdeteksi", "Kolom diskon kosong pada 1.62 persen baris"],
        "errors": [],
    }


def quality_payload() -> dict:
    return {
        "score": 0.9412,
        "breakdown": {
            "completeness": 0.9864,
            "uniqueness": 0.9919,
            "validity": 0.9683,
            "consistency": 0.9186,
        },
        "issues": [
            {"rule": "completeness", "column": "diskon", "count": 24,
             "sample_rows": [17, 88, 142, 301, 640],
             "message": "24 baris tanpa nilai diskon"},
            {"rule": "uniqueness", "column": None, "count": 12,
             "sample_rows": [204, 205, 1188],
             "message": "12 baris duplikat persis"},
        ],
        "passed": True,
    }


class Router:
    def __init__(self, data: Dataset, scenario: Scenario) -> None:
        self.data = data
        self.scenario = scenario
        self.routes = [
            ("GET", r"^/health$", self.health),
            ("GET", r"^/readiness$", self.readiness),
            ("GET", r"^/liveness$", self.liveness),
            ("POST", r"^/analytics/kpi$", self.analytics_kpi),
            ("POST", r"^/analytics/trend$", self.analytics_trend),
            ("POST", r"^/analytics/rfm$", self.analytics_rfm),
            ("POST", r"^/analytics/abc$", self.analytics_abc),
            ("POST", r"^/analytics/cohort$", self.analytics_cohort),
            ("GET", r"^/analytics/branches$", self.analytics_branches),
            ("GET", r"^/analytics/finance$", self.analytics_finance),
            ("GET", r"^/models$", self.models),
            ("GET", r"^/models/(\d+)$", self.model_show),
            ("POST", r"^/models/(\d+)/promote$", self.model_promote),
            ("POST", r"^/ai/chat$", self.ai_chat),
            ("POST", r"^/ai/report$", self.ai_report),
            ("POST", r"^/rag/query$", self.rag_query),
            ("POST", r"^/rag/ingest$", self.rag_ingest),
            ("POST", r"^/imports/upload$", self.imports_upload),
            ("GET", r"^/imports/preview/(\d+)$", self.imports_preview),
            ("POST", r"^/imports/mapping/suggest$", self.imports_mapping_suggest),
            ("POST", r"^/imports/mapping$", self.imports_mapping),
            ("GET", r"^/imports/quality/(\d+)$", self.imports_quality),
            ("POST", r"^/imports/commit$", self.imports_commit),
            ("GET", r"^/imports/jobs/(\d+)$", self.imports_job),
        ]

    def resolve(self, method: str, path: str):
        for route_method, pattern, handler in self.routes:
            if route_method != method:
                continue
            match = re.match(pattern, path)
            if match:
                return handler, match.groups()
        return None, ()

    def guard(self, operation: str):
        """Business endpoints fail hard in the ``error`` scenario."""
        if self.scenario.is_error:
            return 500, error_body(
                "Internal server error",
                code="INTERNAL_ERROR",
                operation=operation,
                resolution="Periksa log server lalu ulangi.",
            )
        return None, None

    def filter_from(self, body: dict):
        return (
            body.get("branch") or None,
            body.get("category") or None,
            parse_date(body.get("date_from")),
            parse_date(body.get("date_to")),
        )

    # -- health: bare objects, never enveloped ---------------------------

    def health(self, _groups):
        return 200, {
            "status": "ok",
            "app": "ai-engine",
            "env": "stub",
            "version": "1.0.0",
        }

    def readiness(self, _groups):
        if self.scenario.is_error:
            return 200, {"ready": False,
                         "checks": {"db": "down: stub scenario 'error'",
                                    "redis": "down: stub scenario 'error'"}}
        return 200, {"ready": True, "checks": {"db": "up", "redis": "up"}}

    def liveness(self, _groups):
        return 200, {"alive": True}

    # -- analytics -------------------------------------------------------

    def analytics_kpi(self, _groups, body):
        status, payload = self.guard("analytics.kpi")
        if status:
            return status, payload
        branch, category, date_from, date_to = self.filter_from(body)
        return 200, envelope(kpi_payload(self.data, branch, category,
                                         date_from, date_to, self.scenario))

    def analytics_trend(self, _groups, body):
        status, payload = self.guard("analytics.trend")
        if status:
            return status, payload
        branch, category, date_from, date_to = self.filter_from(body)
        granularity = str(body.get("granularity") or "daily")
        return 200, envelope(trend_payload(self.data, branch, category, date_from,
                                           date_to, granularity, self.scenario))

    def analytics_rfm(self, _groups, body):
        status, payload = self.guard("analytics.rfm")
        if status:
            return status, payload
        branch, category, date_from, date_to = self.filter_from(body)
        return 200, envelope(rfm_payload(self.data, branch, category,
                                         date_from, date_to, self.scenario))

    def analytics_abc(self, _groups, body):
        status, payload = self.guard("analytics.abc")
        if status:
            return status, payload
        branch, category, date_from, date_to = self.filter_from(body)
        return 200, envelope(abc_payload(self.data, branch, category,
                                         date_from, date_to, self.scenario))

    def analytics_cohort(self, _groups, body):
        status, payload = self.guard("analytics.cohort")
        if status:
            return status, payload
        return 200, envelope(cohort_payload(self.data, self.scenario))

    def analytics_branches(self, _groups):
        status, payload = self.guard("analytics.branches")
        if status:
            return status, payload
        if self.scenario.is_empty:
            return 200, envelope([])
        return 200, envelope(self.data.branch_totals())

    def analytics_finance(self, _groups):
        status, payload = self.guard("analytics.finance")
        if status:
            return status, payload
        return 200, envelope(finance_payload(self.data, self.scenario))

    # -- models ----------------------------------------------------------

    def models(self, _groups):
        status, payload = self.guard("models.list")
        if status:
            return status, payload
        if self.scenario.is_empty:
            return 200, envelope([])
        return 200, envelope([
            {"id": m["id"], "name": m["name"], "model_type": m["model_type"],
             "status": m["status"],
             "production_version_id": m["production_version_id"]}
            for m in MODELS
        ])

    def model_show(self, groups):
        model_id = int(groups[0])
        record = next((m for m in MODELS if m["id"] == model_id), None)
        if record is None:
            return 404, {"success": False,
                         "error": {"message": f"model {model_id} not found"}}
        versions = [] if self.scenario.is_empty else MODEL_VERSIONS.get(model_id, [])
        return 200, envelope({
            "id": record["id"],
            "name": record["name"],
            "model_type": record["model_type"],
            "status": record["status"],
            "versions": versions,
        })

    def model_promote(self, groups, body):
        model_id = int(groups[0])
        version_id = int(body.get("version_id") or 0)
        to_status = str(body.get("to_status") or "PRODUCTION")
        if model_id not in {m["id"] for m in MODELS}:
            return 404, {"success": False,
                         "error": {"message": f"model {model_id} not found"}}
        return 200, envelope({"model_id": model_id, "version_id": version_id,
                              "status": to_status})

    # -- ai / rag --------------------------------------------------------

    def ai_chat(self, _groups, body):
        status, payload = self.guard("ai.chat")
        if status:
            return status, payload
        message = str(body.get("message") or "")
        conversation_id = body.get("conversation_id")
        if conversation_id is not None:
            try:
                conversation_id = int(conversation_id)
            except (TypeError, ValueError):
                conversation_id = None
        return 200, envelope(chat_payload(self.data, message, conversation_id, self.scenario))

    def ai_report(self, _groups, body):
        status, payload = self.guard("ai.report")
        if status:
            return status, payload
        period = str(body.get("period") or "weekly")
        branch = body.get("branch") or None
        return 200, envelope(report_payload(self.data, period, branch, self.scenario))

    def rag_query(self, _groups, body):
        status, payload = self.guard("rag.query")
        if status:
            return status, payload
        try:
            top_k = int(body.get("top_k", 5))
        except (TypeError, ValueError):
            top_k = 5
        return 200, envelope(rag_payload(str(body.get("query") or ""), top_k, self.scenario))

    def rag_ingest(self, _groups, body):
        status, payload = self.guard("rag.ingest")
        if status:
            return status, payload
        content = str(body.get("content") or "")
        truncated = len(content) > 50_000
        chunks = max(0, len(content) // 800)
        return 200, envelope({
            "document_id": 1,
            "n_chunks": chunks,
            "status": "created" if chunks else "empty",
            "truncated": truncated,
        })

    # -- imports ---------------------------------------------------------

    def imports_upload(self, _groups, _body):
        status, payload = self.guard("imports.upload")
        if status:
            return status, payload
        return 200, envelope({
            "upload_id": FIXED_IMPORT_JOB_ID,
            "import_job_id": FIXED_IMPORT_JOB_ID,
            "validation": {
                "ok": True,
                "errors": [],
                "warnings": ["Format CSV dengan pemisah koma. Encoding UTF-8."],
                "row_errors": [],
                "meta": {
                    "filename": "penjualan_agustus_2026.csv",
                    "size_bytes": 284_916,
                    "mime": "text/csv",
                    "checksum_sha256": "3f9a1c7e5b2d8046af1e93c5b70d28e6a4c19f37b2d05e8a71c4b60d93f2e18",
                    "extension": ".csv",
                },
            },
            "stored_path": f"data/uploads/stub_{FIXED_IMPORT_JOB_ID}_penjualan_agustus_2026.csv",
        })

    def imports_preview(self, groups):
        status, payload = self.guard("imports.preview")
        if status:
            return status, payload
        return 200, envelope(preview_payload())

    def imports_mapping_suggest(self, _groups, body):
        status, payload = self.guard("imports.mapping.suggest")
        if status:
            return status, payload
        return 200, envelope(suggest_mapping(body.get("columns") or [],
                                             str(body.get("dataset_type") or "sales")))

    def imports_mapping(self, _groups, body):
        status, payload = self.guard("imports.mapping")
        if status:
            return status, payload
        return 200, envelope({"mappings": dict(body.get("mappings") or {})})

    def imports_quality(self, groups):
        status, payload = self.guard("imports.quality")
        if status:
            return status, payload
        return 200, envelope(quality_payload())

    def imports_commit(self, _groups, body):
        status, payload = self.guard("imports.commit")
        if status:
            return status, payload
        job_id = int(body.get("import_job_id") or FIXED_IMPORT_JOB_ID)
        if body.get("run_async"):
            return 200, envelope({"import_job_id": job_id, "status": "queued"})
        quality = quality_payload()
        return 200, envelope({
            "import_job_id": job_id,
            "dataset_type": str(body.get("dataset_type") or "sales"),
            "total_rows": 1480,
            "processed_rows": 1468,
            "error_rows": 12,
            "quality": {
                "score": quality["score"],
                "breakdown": quality["breakdown"],
                "issues": quality["issues"],
                "passed": quality["passed"],
            },
            "error_log": [
                {"row": 204, "rule": "duplicate", "message": "Baris duplikat persis"},
                {"row": 205, "rule": "duplicate", "message": "Baris duplikat persis"},
            ],
        })

    def imports_job(self, groups):
        status, payload = self.guard("imports.jobs")
        if status:
            return status, payload
        return 200, envelope({
            "id": int(groups[0]),
            "status": "succeeded",
            "progress": 100,
            "total_rows": 1480,
            "processed_rows": 1468,
            "error_rows": 12,
            "report": quality_payload(),
        })


class Handler(BaseHTTPRequestHandler):
    server_version = "stub-engine/1.0"
    protocol_version = "HTTP/1.1"
    router: Router = None  # type: ignore[assignment]

    def do_GET(self) -> None:  # noqa: N802
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch("POST")

    def log_message(self, fmt: str, *args) -> None:
        sys.stderr.write("[stub-engine] %s %s\n" % (self.address_string(), fmt % args))

    def _dispatch(self, method: str) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        if not path.startswith(API_PREFIX):
            self._send(404, {"detail": "Not Found"})
            return
        route_path = path[len(API_PREFIX):] or "/"

        handler, groups = self.router.resolve(method, route_path)
        if handler is None:
            known = any(re.match(pattern, route_path) for _m, pattern, _h in self.router.routes)
            if known:
                self._send(405, {"detail": "Method Not Allowed"})
            elif route_path.split("/")[1:2] and route_path.split("/")[1] in {
                "forecast", "customers", "inventory", "anomaly", "recommend",
                "training", "alerts",
            }:
                self._send(501, error_body(
                    "Stub engine tidak mengimplementasikan endpoint ini.",
                    code="NOT_IMPLEMENTED",
                    operation=route_path,
                    status_code=501,
                    error_type="not_implemented",
                    resolution="Jalankan engine FastAPI sungguhan, atau lihat dev/README.md.",
                ))
            else:
                self._send(404, {"detail": "Not Found"})
            return

        body = self._read_body() if method == "POST" else {}
        try:
            if method == "POST":
                status, payload = handler(groups, body)
            else:
                status, payload = handler(groups)
        except Exception as exc:  # pragma: no cover - defensive
            status, payload = 500, error_body(
                "Internal server error", code="INTERNAL_ERROR",
                operation=route_path, technical=type(exc).__name__)
        self._send(status, payload)

    def _read_body(self) -> dict:
        try:
            length = int(self.headers.get("Content-Length") or 0)
        except ValueError:
            length = 0
        if length <= 0:
            return {}
        raw = self.rfile.read(length)
        if not raw.strip():
            return {}
        try:
            parsed = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return {}
        return parsed if isinstance(parsed, dict) else {}

    def _send(self, status: int, payload) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Request-ID", "-")
        self.end_headers()
        self.wfile.write(body)


class Server(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def handle_error(self, request, client_address) -> None:
        """A dev tool must not dump a traceback when a browser aborts a request."""
        exc = sys.exc_info()[1]
        if isinstance(exc, (ConnectionResetError, BrokenPipeError, ConnectionAbortedError)):
            return
        super().handle_error(request, client_address)


def is_loopback(host: str) -> bool:
    if host.lower() in LOOPBACK_HOSTS:
        return True
    try:
        import ipaddress
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="stub-engine.py",
        description="Development stub for the FastAPI AI engine (NOT for shared or production hosts).")
    parser.add_argument("--port", type=int, default=8010, help="TCP port to bind (default 8010).")
    parser.add_argument("--host", default="127.0.0.1", help="Bind address (default 127.0.0.1).")
    parser.add_argument("--scenario", default=Scenario.NORMAL, choices=Scenario.CHOICES,
                        help="normal: full data. empty: empty warehouse. error: engine errors.")
    parser.add_argument("--seed", type=int, default=20260828,
                        help="Seed for the generated series (default 20260828).")
    parser.add_argument("--allow-remote", action="store_true",
                        help="Allow binding a non-loopback host. Development only.")
    args = parser.parse_args(argv)

    if not is_loopback(args.host) and not args.allow_remote:
        print(
            f"stub-engine: refusing to bind {args.host!r}. This stub does not check "
            "X-Service-Key at all, so it must stay on loopback. Pass --allow-remote only "
            "if you understand that anyone who can reach this port can read everything "
            "it serves. Never do this on a shared or production host.",
            file=sys.stderr,
        )
        return 2

    scenario = Scenario(args.scenario)
    data = Dataset(args.seed)
    Handler.router = Router(data, scenario)

    server = Server((args.host, args.port), Handler)
    display = "localhost" if args.host in ("127.0.0.1", "::1") else args.host
    print(
        f"stub-engine: DEV STUB, no auth, data sintetis. "
        f"http://{display}:{args.port}{API_PREFIX} (scenario={scenario.name}, seed={args.seed})",
        flush=True,
    )
    if not is_loopback(args.host):
        print("stub-engine: WARNING --allow-remote given; this port is reachable off-host.",
              file=sys.stderr)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nstub-engine: stopped.")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
