"""Intelligent column mapping: exact dict (ID+EN) + fuzzy + type matching."""
from __future__ import annotations

import difflib
import json
from pathlib import Path
from typing import Dict, List, Optional

from app.core.config import settings
from app.core.logging import get_logger

log = get_logger("ingestion.mapper", "mapping")

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
    "kode pelanggan": "customer_code", "kd pelanggan": "customer_code", "id pelanggan": "customer_code",
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


def _date_field(canonical: List[str]) -> Optional[str]:
    """The date column this dataset actually has, if any: purchase_date for
    purchases, snapshot_date for inventory, expense_date for expenses."""
    return next((c for c in canonical if c.endswith("_date")), None)


def suggest_mapping(columns: List[str], dataset_type: str = "sales") -> List[Dict]:
    """Suggest {source: target} column mappings for ``dataset_type``.

    Returns one {source_column, target_field, confidence, method} row per input
    column, with ``method`` in {exact, fuzzy, semantic, none} and a target of
    None when nothing confident was found. A target is only ever returned when
    it is one of CANONICAL_FIELDS[dataset_type] — an alias shared across
    datasets ("tanggal" means transaction_date for sales but purchase_date for
    purchases) must not resolve to a field that dataset's loader never reads.
    """
    canonical = CANONICAL_FIELDS.get(dataset_type, CANONICAL_FIELDS["sales"])
    canon_norm = {_norm(c): c for c in canonical}
    canon_set = set(canonical)
    choices = list(ALIAS_MAP.keys()) + list(canon_norm)
    suggestions = []
    for col in columns:
        n = _norm(col)
        target: Optional[str] = None
        method = "none"
        conf = 0.0
        # Shared alias first, but only when this dataset owns the target field.
        alias = ALIAS_MAP.get(n)
        if alias in canon_set:
            target, method, conf = alias, "exact", 1.0
        elif n in canon_norm:
            target, method, conf = canon_norm[n], "exact", 1.0
        else:
            best = difflib.get_close_matches(n, choices, n=1, cutoff=0.78)
            if best:
                b = best[0]
                fuzzy = ALIAS_MAP.get(b, canon_norm.get(b))
                if fuzzy in canon_set:
                    target, method = fuzzy, "fuzzy"
                    conf = round(difflib.SequenceMatcher(None, n, b).ratio(), 2)
            if target is None:
                # semantic hint: date-like names -> this dataset's own date column
                if any(k in n for k in ("tgl", "tanggal", "date")):
                    hinted = _date_field(canonical)
                    if hinted:
                        target, method, conf = hinted, "semantic", 0.55
                elif any(k in n for k in ("qty", "jml", "jumlah")):
                    for c in canonical:
                        if "quantity" in c or "stock" in c:
                            target, method, conf = c, "semantic", 0.55
                            break
        suggestions.append(
            {"source_column": col, "target_field": target, "confidence": conf, "method": method}
        )
    return suggestions


def apply_mapping(df, mappings: Dict[str, str]):
    """Rename columns per mappings {source: target}. Unmapped columns kept as-is.

    When two source columns resolve to the same target only the first wins and
    the loser is dropped: duplicated column names make every later
    ``df["target"]`` return a DataFrame instead of a Series, which breaks the
    ETL and the quality checks with an obscure downstream error.
    """
    taken: set = set()
    rename: Dict[str, str] = {}
    dropped: set = set()
    for source, target in (mappings or {}).items():
        if source not in df.columns or not target:
            continue
        if target in taken:
            dropped.add(source)
            continue
        taken.add(target)
        rename[source] = target
    if dropped:
        df = df.drop(columns=[c for c in dropped if c in df.columns])
    return df.rename(columns=rename) if rename else df


_TEMPLATE_DIR = Path(settings.storage_path) / "mapping_templates"


def save_template(name: str, dataset_type: str, mapping: Dict[str, str], db_session=None) -> Dict:
    """Persist a mapping template to disk and, when a session is supplied, to
    mapping_templates. Returns the stored payload {name, dataset_type, mapping}.
    A failed disk write is logged and does not prevent the database write."""
    _TEMPLATE_DIR.mkdir(parents=True, exist_ok=True)
    payload = {"name": name, "dataset_type": dataset_type, "mapping": mapping}
    try:
        with open(_TEMPLATE_DIR / f"{name}.json", "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
    except Exception as exc:
        log.error(f"mapping template {name!r}: disk write failed ({type(exc).__name__})")
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
        except Exception as exc:
            try:
                db_session.rollback()
            except Exception:
                pass
            log.error(f"mapping template {name!r}: database write failed ({type(exc).__name__})")
    return payload


def load_template(name: str, db_session=None) -> Optional[Dict]:
    """Return the mapping template {name, dataset_type, mapping} for ``name``,
    preferring the database row and falling back to the on-disk JSON. Returns
    None when neither source has it."""
    if db_session is not None:
        try:
            from app.database.models import MappingTemplate

            row = db_session.query(MappingTemplate).filter_by(name=name).first()
            if row:
                return {"name": row.name, "dataset_type": row.dataset_type, "mapping": row.mapping}
        except Exception as exc:
            log.error(f"mapping template {name!r}: database read failed ({type(exc).__name__})")
    fp = _TEMPLATE_DIR / f"{name}.json"
    if fp.exists():
        try:
            return json.loads(fp.read_text(encoding="utf-8"))
        except Exception as exc:
            log.error(f"mapping template {name!r}: file unreadable ({type(exc).__name__})")
            return None
    return None
