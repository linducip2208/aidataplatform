"""Demo product catalog: description + image for known retail products.

The warehouse learns product names from uploaded files, but a name alone is
all any listing ever had to show. This module is the one place that knows
what the ten demo products *are*: a one-line Indonesian description and the
Laravel-public illustration that depicts it
(``/images/demo-products/<slug>.svg``).

Two honest rules:

* Lookup is by normalised name and returns ``None`` for anything unknown --
  an unknown product renders imageless, never with another product's picture.
* The ETL only *fills* empty fields from here, it never overwrites a
  description or image that is already stored.
"""
from __future__ import annotations

import re
from typing import Any, Dict, List, Optional

IMAGE_BASE = "/images/demo-products"

# slug -> (display name match, category, unit, description)
_PRODUCTS: Dict[str, Dict[str, str]] = {
    "minyak-goreng-1l": {
        "name": "Minyak Goreng 1L",
        "category": "Sembako",
        "unit": "botol",
        "description": "Minyak goreng sawit kemasan botol 1 liter untuk menumis dan menggoreng.",
    },
    "beras-premium-5kg": {
        "name": "Beras Premium 5kg",
        "category": "Sembako",
        "unit": "karung",
        "description": "Beras putih premium karung 5 kg, pulen untuk nasi harian.",
    },
    "gula-pasir-1kg": {
        "name": "Gula Pasir 1kg",
        "category": "Sembako",
        "unit": "pak",
        "description": "Gula pasir kristal putih kemasan 1 kg untuk masak dan minuman.",
    },
    "kopi-bubuk-200g": {
        "name": "Kopi Bubuk 200g",
        "category": "Minuman",
        "unit": "pak",
        "description": "Kopi robusta bubuk kemasan 200 gram, sangrai sedang.",
    },
    "teh-celup-25": {
        "name": "Teh Celup 25 Kantong",
        "category": "Minuman",
        "unit": "kotak",
        "description": "Teh hitam celup isi 25 kantong, seduh cepat dan wangi.",
    },
    "mie-instan-goreng-85g": {
        "name": "Mie Instan Goreng 85g",
        "category": "Sembako",
        "unit": "pak",
        "description": "Mie instan goreng kemasan 85 gram dengan bumbu lengkap.",
    },
    "susu-uht-1l": {
        "name": "Susu UHT Full Cream 1L",
        "category": "Minuman",
        "unit": "karton",
        "description": "Susu sapi UHT full cream karton 1 liter, siap minum.",
    },
    "telur-ayam-1kg": {
        "name": "Telur Ayam Negeri 1kg",
        "category": "Sembako",
        "unit": "kg",
        "description": "Telur ayam negeri segar kemasan 1 kg, sekitar 16 butir.",
    },
    "sabun-mandi-80g": {
        "name": "Sabun Mandi Batang 80g",
        "category": "Perawatan",
        "unit": "batang",
        "description": "Sabun mandi batang 80 gram, busa lembut dan wangi segar.",
    },
    "shampo-170ml": {
        "name": "Shampo Anti Ketombe 170ml",
        "category": "Perawatan",
        "unit": "botol",
        "description": "Shampo anti ketombe botol 170 ml dengan sensasi mentol.",
    },
}


def _norm(name: Any) -> str:
    text = re.sub(r"\s+", " ", str(name or "")).strip().casefold()
    return text


def lookup(product_name: Any) -> Optional[Dict[str, str]]:
    """Return ``{description, image_url, category, unit}`` for a known demo
    product, else ``None``. Never guesses."""
    want = _norm(product_name)
    if not want:
        return None
    for slug, item in _PRODUCTS.items():
        if _norm(item["name"]) == want:
            return {
                "description": item["description"],
                "image_url": f"{IMAGE_BASE}/{slug}.svg",
                "category": item["category"],
                "unit": item["unit"],
            }
    return None


def enrich_product_rows(rows: List[Dict[str, Any]], name_key: str = "product") -> List[Dict[str, Any]]:
    """Attach ``description``/``image_url`` to analytics rows by product name.

    Additive keys only (``None`` when the product is unknown); the caller's
    numbers are never touched.
    """
    out = []
    for row in rows:
        item = dict(row)
        meta = lookup(row.get(name_key))
        item["description"] = meta["description"] if meta else None
        item["image_url"] = meta["image_url"] if meta else None
        out.append(item)
    return out
