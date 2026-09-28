"""Intelligent column mapping: exact dict (ID+EN) + fuzzy + type matching."""
from __future__ import annotations

import difflib
import json
from pathlib import Path
from typing import Dict, List, Optional

# Canonical target fields per dataset
CANONICAL_FIELDS: Dict[str, List[str]] = {
    "sales": ["transaction_date", "customer_code", "customer_name", "product_code",
              "product_name", "branch_name", "quantity", "selling_price", "discount", "revenue"],
    "inventory": ["snapshot_date", "product_code", "product_name", "warehouse_name", "stock_qty"],
    "purchases": ["purchase_date", "supplier_code", "supplier_name", "product_code", "quantity", "cost"],
    "expenses": ["expense_date", "department_name", "category", "amount"],
    "customers": ["customer_code", "customer_name", "segment", "city"],
    "products": ["product_code", "product_name", "category", "unit", "cost_price", "selling_price"],
}

# Indonesian + English aliases -> canonical
ALIAS_MAP: Dict[str, str] = {
    # dates
    "tanggal transaksi": "transaction_date", "tgl transaksi": "transaction_date",
    "tanggal": "transaction_date", "transaction date": "transaction_date", "date": "transaction_date",
    "order date": "transaction_date", "tgl": "transaction_date",
    "tanggal pembelian": "purchase_date", "tanggal beban": "expense_date",
    "tanggal stok": "snapshot_date", "snapshot date": "snapshot_date",
    # customer
    "kd customer": "customer_code", "kode customer": "customer_code", "id customer": "customer_code",
    "customer code": "customer_code", "customer id": "customer_code", "kd cust": "customer_code",
    "nm customer": "customer_name", "nama customer": "customer_name", "customer name": "customer_name",
    "nama pelanggan": "customer_name", "pelanggan": "customer_name", "customer": "customer_name",
    # product
    "kd brg": "product_code", "kode barang": "product_code", "kode produk": "product_code",
    "kd produk": "product_code", "product code": "product_code", "sku": "product_code", "kode brg": "product_code",
    "nm brg": "product_name", "nama barang": "product_name", "nama produk": "product_name",
    "product name": "product_name", "nm produk": "product_name", "barang": "product_name",
    "kategori": "category", "category": "category", "jenis": "category",
    "satuan": "unit", "unit": "unit",
    "harga pokok": "cost_price", "cost": "cost", "harga beli": "cost", "cost price": "cost_price",
    # branch / warehouse / supplier / dept
    "nm cabang": "branch_name", "nama cabang": "branch_name", "branch": "branch_name",
    "branch name": "branch_name", "cabang": "branch_name", "kode cabang": "branch_name",
    "gudang": "warehouse_name", "nama gudang": "warehouse_name", "warehouse": "warehouse_name",
    "nm supplier": "supplier_name", "nama supplier": "supplier_name", "supplier": "supplier_name",
    "kode supplier": "supplier_code", "supplier code": "supplier_code",
    "departemen": "department_name", "department": "department_name", "dept": "department_name",
    "bagian": "department_name",
    # measures
    "jml": "quantity", "jumlah": "quantity", "qty": "quantity", "quantity": "quantity", "volume": "quantity",
    "harga jual": "selling_price", "harga": "selling_price", "price": "selling_price", "selling price": "selling_price",
    "diskon": "discount", "discount": "discount", "disc": "discount",
    "total": "revenue", "omzet": "revenue", "revenue": "revenue", "nilai": "revenue", "subtotal": "revenue",
    "stok": "stock_qty", "stock": "stock_qty", "sisa stok": "stock_qty", "qty on hand": "stock_qty",
    "nominal": "amount", "amount": "amount", "biaya": "amount", "pengeluaran": "amount",
    "segmen": "segment", "segment": "segment", "kota": "city", "city": "city",
}


def _norm(s: str) -> str:
    return " ".join(str(s or "").strip().lower().replace("_", " ").split())


def suggest_mapping(columns: List[str], dataset_type: str = "sales") -> List[Dict]:
    canonical = CANONICAL_FIELDS.get(dataset_type, CANONICAL_FIELDS["sales"])
    canon_norm = {_norm(c): c for c in canonical}
    suggestions = []
    for col in columns:
        n = _norm(col)
        target: Optional[str] = None
        method = "none"
        conf = 0.0
        if n in ALIAS_MAP:
            target = ALIAS_MAP[n]
            method, conf = "exact", 1.0
        elif n in canon_norm:
            target = canon_norm[n]
            method, conf = "exact", 1.0
        else:
            # fuzzy over alias keys + canonical
            choices = list(ALIAS_MAP.keys()) + [_norm(c) for c in canonical]
            best = difflib.get_close_matches(n, choices, n=1, cutoff=0.78)
            if best:
                b = best[0]
                target = ALIAS_MAP.get(b, canon_norm.get(b))
                method, conf = "fuzzy", round(difflib.SequenceMatcher(None, n, b).ratio(), 2)
            else:
                # type/semantic hint: date-like names
                if any(k in n for k in ("tgl", "tanggal", "date")):
                    target = canonical[0]
                    method, conf = "semantic", 0.55
                elif any(k in n for k in ("qty", "jml", "jumlah")):
                    for c in canonical:
                        if "quantity" in c or "stock" in c:
                            target = c
                            method, conf = "semantic", 0.55
                            break
        suggestions.append(
            {"source_column": col, "target_field": target, "confidence": conf, "method": method}
        )
    return suggestions


def apply_mapping(df, mappings: Dict[str, str]):
    """Rename columns per mappings {source: target}. Unmapped columns kept as-is."""
    import pandas as pd  # noqa: F401

    rename = {s: t for s, t in mappings.items() if s in df.columns and t}
    return df.rename(columns=rename)


_TEMPLATE_DIR = Path("./datasets/mapping_templates")


def save_template(name: str, dataset_type: str, mapping: Dict[str, str], db_session=None) -> Dict:
    _TEMPLATE_DIR.mkdir(parents=True, exist_ok=True)
    payload = {"name": name, "dataset_type": dataset_type, "mapping": mapping}
    try:
        with open(_TEMPLATE_DIR / f"{name}.json", "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
    except Exception:
        pass
    if db_session is not None:
        try:
            from app.database.models import MappingTemplate

            row = db_session.query(MappingTemplate).filter_by(name=name).first()
            if row:
                row.mapping = mapping
                row.dataset_type = dataset_type
            else:
                db_session.add(MappingTemplate(name=name, dataset_type=dataset_type, mapping=mapping))
            db_session.commit()
        except Exception:
            try:
                db_session.rollback()
            except Exception:
                pass
    return payload


def load_template(name: str, db_session=None) -> Optional[Dict]:
    if db_session is not None:
        try:
            from app.database.models import MappingTemplate

            row = db_session.query(MappingTemplate).filter_by(name=name).first()
            if row:
                return {"name": row.name, "dataset_type": row.dataset_type, "mapping": row.mapping}
        except Exception:
            pass
    fp = _TEMPLATE_DIR / f"{name}.json"
    if fp.exists():
        try:
            return json.loads(fp.read_text(encoding="utf-8"))
        except Exception:
            return None
    return None
