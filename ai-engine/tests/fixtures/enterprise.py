"""Deterministic enterprise fixtures: retail sales / inventory / customers / expenses.

One fixed retail group, twelve branches, twelve months of 2025. Every frame is
built from closed-form formulas (seasonal factor x trend x hash-based
pseudo-noise) with ``numpy.RandomState(SEED)`` draws, so the output is
byte-identical on every platform and run order:

- The seed is fixed (``SEED``) and never an argument; changing it changes every
  number, so it lives here and not in a caller.
- No real personal data: synthetic shop names (``Toko Maju 007``) and ledger
  codes (``C-10007``). No emails, phones, persons or addresses.
- No database writes: every builder returns a plain ``pandas.DataFrame``.

Distributions mirror ``application/tests/Fixtures/EnterpriseDatasets.php``:
monthly branch revenue = base x seasonal(month) x trend x (1 +/- 3% noise),
December peak (1.35), February trough (0.88); expenses carry exactly three
documented anomalies (``ANOMALIES``) as the ground truth for detector tests;
the churn signal is ``recency_days > 180 and frequency_12m <= 3``.

Known KPIs are precomputed as ``EXPECTED_*`` constants; ``assert_enterprise_kpis``
recomputes them from the builders so a formula change fails loudly instead of
drifting every downstream assertion.
"""
from __future__ import annotations

import hashlib
from typing import Dict, List

import pandas as pd

SEED = 20260930
SALES_YEAR = 2025

BRANCHES: List[Dict[str, str]] = [
    {"code": f"BR-{i + 1:02d}", "city": city, "region": region,
     "tier": "flagship" if i < 3 else "regular"}
    for i, (city, region) in enumerate([
        ("Jakarta Pusat", "Jabodetabek"), ("Bandung", "Jawa Barat"),
        ("Semarang", "Jawa Tengah"), ("Surabaya", "Jawa Timur"),
        ("Medan", "Sumatera Utara"), ("Palembang", "Sumatera Selatan"),
        ("Denpasar", "Bali"), ("Makassar", "Sulawesi Selatan"),
        ("Balikpapan", "Kalimantan Timur"), ("Pontianak", "Kalimantan Barat"),
        ("Manado", "Sulawesi Utara"), ("Jayapura", "Papua"),
    ])
]

SEASONALITY: Dict[int, float] = {
    1: 0.92, 2: 0.88, 3: 1.02, 4: 1.10, 5: 1.05, 6: 0.98,
    7: 1.00, 8: 0.97, 9: 0.99, 10: 1.04, 11: 1.12, 12: 1.35,
}

CHART_OF_ACCOUNTS: List[Dict[str, object]] = [
    {"code": "5101", "category": "sewa", "typical_monthly_idr": 4500000},
    {"code": "5102", "category": "listrik", "typical_monthly_idr": 1875000},
    {"code": "5103", "category": "air", "typical_monthly_idr": 420000},
    {"code": "5104", "category": "gaji", "typical_monthly_idr": 18400000},
    {"code": "5105", "category": "transportasi", "typical_monthly_idr": 920000},
    {"code": "5106", "category": "atk", "typical_monthly_idr": 310000},
    {"code": "5107", "category": "pemeliharaan", "typical_monthly_idr": 760000},
    {"code": "5108", "category": "pemasaran", "typical_monthly_idr": 1500000},
    {"code": "5109", "category": "asuransi", "typical_monthly_idr": 640000},
    {"code": "5110", "category": "komunikasi", "typical_monthly_idr": 380000},
    {"code": "5111", "category": "renovasi", "typical_monthly_idr": 500000},
]

# (branch, month, category, multiplier, reason): exactly three, the ground
# truth for anomaly-detection tests. Anything else a detector finds is wrong.
ANOMALIES: List[tuple] = [
    ("BR-03", 6, "renovasi", 3.2, "renovasi gudang cabang"),
    ("BR-07", 11, "listrik", 2.4, "tagihan susulan PLN 3 bulan"),
    ("BR-11", 2, "transportasi", 2.9, "sewa armada darurat banjir"),
]

PRODUCTS: List[Dict[str, object]] = [
    {"kode_produk": f"P-{101 + i:06d}", "nama_barang": name,
     "kategori": cat, "cost_price": cost}
    for i, (name, cat, cost) in enumerate([
        ("Minyak Goreng 1L", "Sembako", 24300), ("Beras Premium 5kg", "Sembako", 68200),
        ("Gula Pasir 1kg", "Sembako", 14900), ("Teh Celup 25s", "Minuman", 5900),
        ("Kopi Bubuk 200g", "Minuman", 21400), ("Susu UHT 1L", "Minuman", 17800),
        ("Sabun Mandi 90g", "Perawatan", 4200), ("Sampo 340ml", "Perawatan", 26700),
        ("Deterjen 800g", "Perawatan", 18900), ("Pasta Gigi 190g", "Perawatan", 15200),
        ("Biskuit Kaleng 600g", "Snack", 32500), ("Mi Instan 5s", "Snack", 14800),
        ("Cokelat Batang 150g", "Snack", 22900), ("Keripik Singkong 250g", "Snack", 11500),
        ("Baterai AA 4s", "Elektronik", 27600), ("Lampu LED 9W", "Elektronik", 31800),
        ("Obat Nyamuk Elektrik", "Rumah Tangga", 45300),
        ("Pembersih Lantai 800ml", "Rumah Tangga", 16700),
        ("Tisu Wajah 250s", "Rumah Tangga", 19400),
        ("Minyak Kayu Putih 60ml", "Kesehatan", 23100),
        ("Vitamin C 30 tablet", "Kesehatan", 38900), ("Plester Luka 20s", "Kesehatan", 9800),
        ("Buku Tulis 58 lembar", "ATK", 6400), ("Pulpen Gel 0.5", "ATK", 5100),
    ])
]

# Golden KPIs, recomputed from the builders by assert_enterprise_kpis().
EXPECTED_SALES_ROWS = 144
EXPECTED_SALES_REVENUE = 24891605715
EXPECTED_SALES_TRANSACTIONS = 250355
EXPECTED_INVENTORY_ROWS = 288
EXPECTED_DEAD_STOCK_ROWS = 31
EXPECTED_CUSTOMER_ROWS = 150
EXPECTED_CHURNED_CUSTOMERS = 3
EXPECTED_EXPENSE_ROWS = 1584
EXPECTED_ANOMALY_ROWS = 3


def _noise(key: str, amplitude: float) -> float:
    """Deterministic pseudo-noise in [-amplitude, +amplitude] for ``key``.

    md5 is a stable hash here, not security: the same key always maps to the
    same float on every platform.
    """
    unit = int(hashlib.md5(f"{SEED}:{key}".encode()).hexdigest()[:8], 16) / 0xFFFFFFFF
    return (unit * 2 - 1) * amplitude


def make_sales_frame() -> pd.DataFrame:
    """144 rows: one per branch per month of 2025 (see module docstring)."""
    rows = []
    for b, branch in enumerate(BRANCHES):
        base = 118_000_000 + b * 7_500_000
        avg_ticket = 85_000 + b * 2_500
        for m in range(1, 13):
            trend = 1 + 0.008 * (m - 1)
            revenue = int(round(base * SEASONALITY[m] * trend
                                * (1 + _noise(f"sales:{b}:{m}", 0.03))))
            transactions = int(round(revenue / avg_ticket))
            qty = transactions * (2 + (b + m) % 3)
            discount = int(round(revenue * (0.02 + ((b * m) % 5) * 0.005)))
            cogs = int(round((revenue - discount) * 0.68))
            rows.append({"year": SALES_YEAR, "month": m,
                         "kode_cabang": branch["code"],
                         "transactions": transactions, "qty": qty,
                         "revenue_idr": revenue, "discount_idr": discount,
                         "cogs_idr": cogs})
    return pd.DataFrame(rows)


def make_inventory_frame() -> pd.DataFrame:
    """288 rows: 24 products x 12 branches, one snapshot each."""
    rows = []
    for p, product in enumerate(PRODUCTS):
        for b, branch in enumerate(BRANCHES):
            key = f"inv:{p}:{b}"
            avg_daily = 2 + int(abs(_noise(f"{key}:v", 1)) * 22)
            min_stock = 20 + int(abs(_noise(f"{key}:m", 1)) * 60)
            cover = 150 if (p + b) % 9 == 0 else 28
            stock = max(0, int(round(avg_daily * cover * (1 + _noise(f"{key}:s", 0.20)))))
            days = round(stock / avg_daily, 1) if avg_daily else 0.0
            rows.append({"kode_produk": product["kode_produk"],
                         "kode_cabang": branch["code"], "stock_qty": stock,
                         "min_stock": min_stock, "avg_daily_sales": avg_daily,
                         "days_of_stock": days, "dead_stock": days > 120})
    return pd.DataFrame(rows)


def make_customers_frame() -> pd.DataFrame:
    """150 synthetic B2B customers with a churn signal column."""
    words = ["Maju", "Berkah", "Jaya", "Abadi", "Makmur",
             "Sejahtera", "Lancar", "Sentosa", "Tentram", "Subur"]
    segments = ["champions", "loyal", "potential", "at_risk"]
    rows = []
    for i in range(1, 151):
        key = f"cust:{i}"
        recency = 5 + int(abs(_noise(f"{key}:r", 1)) * 340)
        frequency = 1 + int(abs(_noise(f"{key}:f", 1)) * 48)
        ticket = 50_000 + int(abs(_noise(f"{key}:t", 1)) * 450_000)
        churned = bool(recency > 180 and frequency <= 3)
        rows.append({"kode_pelanggan": f"C-{10000 + i:05d}",
                     "nama_toko": f"Toko {words[(i - 1) % len(words)]} {i:03d}",
                     "kota": BRANCHES[(i - 1) % len(BRANCHES)]["city"],
                     "segment": "hibernating" if churned else segments[(i - 1) % len(segments)],
                     "recency_days": recency, "frequency_12m": frequency,
                     "monetary_12m": frequency * ticket, "churned": churned})
    return pd.DataFrame(rows)


def make_expenses_frame() -> pd.DataFrame:
    """1584 rows: 12 branches x 11 categories x 12 months, 3 anomalies."""
    mult = {(b, m, c): k for b, m, c, k, _ in ANOMALIES}
    rows = []
    for b, branch in enumerate(BRANCHES):
        for account in CHART_OF_ACCOUNTS:
            for m in range(1, 13):
                key = (branch["code"], m, account["category"])
                nominal = int(round(account["typical_monthly_idr"]
                                    * (1 + _noise(f"exp:{b}:{account['category']}:{m}", 0.05))
                                    * mult.get(key, 1.0)))
                rows.append({"year": SALES_YEAR, "month": m,
                             "kode_cabang": branch["code"],
                             "kategori": account["category"],
                             "nominal_idr": nominal,
                             "is_anomaly": key in mult})
    return pd.DataFrame(rows)


def assert_enterprise_kpis() -> Dict[str, int]:
    """Recompute every golden KPI from the builders and compare to EXPECTED_*.

    Returns the recomputed KPIs so tests can also assert derived relations
    (e.g. December is the peak month) on the same numbers.
    """
    sales = make_sales_frame()
    inv = make_inventory_frame()
    cust = make_customers_frame()
    exp = make_expenses_frame()
    got = {
        "sales_rows": len(sales),
        "sales_revenue": int(sales["revenue_idr"].sum()),
        "sales_transactions": int(sales["transactions"].sum()),
        "inventory_rows": len(inv),
        "dead_stock_rows": int(inv["dead_stock"].sum()),
        "customer_rows": len(cust),
        "churned_customers": int(cust["churned"].sum()),
        "expense_rows": len(exp),
        "anomaly_rows": int(exp["is_anomaly"].sum()),
    }
    expected = {
        "sales_rows": EXPECTED_SALES_ROWS,
        "sales_revenue": EXPECTED_SALES_REVENUE,
        "sales_transactions": EXPECTED_SALES_TRANSACTIONS,
        "inventory_rows": EXPECTED_INVENTORY_ROWS,
        "dead_stock_rows": EXPECTED_DEAD_STOCK_ROWS,
        "customer_rows": EXPECTED_CUSTOMER_ROWS,
        "churned_customers": EXPECTED_CHURNED_CUSTOMERS,
        "expense_rows": EXPECTED_EXPENSE_ROWS,
        "anomaly_rows": EXPECTED_ANOMALY_ROWS,
    }
    assert got == expected, f"enterprise KPI drift: got={got} expected={expected}"
    # The math behind the constants, pinned independently of the totals:
    # December (month 12) is the peak revenue month; every anomaly key in
    # ANOMALIES is flagged exactly once; churned rows all satisfy the signal.
    monthly = sales.groupby("month")["revenue_idr"].sum()
    assert int(monthly.idxmax()) == 12
    assert set(zip(exp[exp["is_anomaly"]]["kode_cabang"],
                   exp[exp["is_anomaly"]]["month"],
                   exp[exp["is_anomaly"]]["kategori"])) == {
               (b, m, c) for b, m, c, _, _ in ANOMALIES}
    bad = cust[cust["churned"]]
    assert bool(((bad["recency_days"] > 180) & (bad["frequency_12m"] <= 3)).all())
    return got


def service_headers() -> Dict[str, str]:
    """Auth headers for guarded endpoints, reusable outside conftest.

    Reads the same resolved header name and configured key the engine
    enforces, so a deployment that renames the header does not silently
    invalidate these.
    """
    from app.core.config import settings
    from app.core.security import SERVICE_KEY_HEADER

    return {SERVICE_KEY_HEADER: settings.service_api_key}


def make_client():
    """A TestClient on a fresh app instance (for suites without conftest)."""
    from fastapi.testclient import TestClient

    from app.main import create_app

    return TestClient(create_app())
