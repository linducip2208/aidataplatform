"""Demo product catalog: lookup, ETL fill, ABC enrichment.

Every product in the catalog must resolve to the description written for it
and to an image under /images/demo-products/; anything unknown resolves to
None so the UI renders it imageless instead of mislabelled.
"""
from __future__ import annotations


def test_lookup_is_case_and_space_insensitive():
    from app.analytics.product_catalog import lookup

    a = lookup("Minyak Goreng 1L")
    b = lookup("  minyak   goreng 1l ")
    assert a is not None and b is not None
    assert a["description"] == b["description"]
    assert a["image_url"].endswith(".svg")
    assert a["image_url"].startswith("/images/demo-products/")


def test_lookup_never_guesses():
    from app.analytics.product_catalog import lookup

    assert lookup("Minyak Goreng 2L") is None
    assert lookup("") is None
    assert lookup(None) is None


def test_every_catalog_entry_has_a_matching_image():
    from app.analytics.product_catalog import _PRODUCTS, lookup

    assert len(_PRODUCTS) >= 10
    for slug, item in _PRODUCTS.items():
        meta = lookup(item["name"])
        assert meta is not None
        assert meta["image_url"] == f"/images/demo-products/{slug}.svg"
        assert meta["description"] == item["description"]


def test_enrich_rows_is_additive():
    from app.analytics.product_catalog import enrich_product_rows

    rows = enrich_product_rows([
        {"product": "Beras Premium 5kg", "revenue": 1.0},
        {"product": "Barang Asing", "revenue": 2.0},
    ])
    assert rows[0]["revenue"] == 1.0
    assert rows[0]["image_url"].endswith("beras-premium-5kg.svg")
    assert "beras" in rows[0]["description"].lower()
    assert rows[1]["image_url"] is None
    assert rows[1]["description"] is None


def test_etl_fill_never_overwrites():
    from app.database.models import DimProduct
    from app.ingestion.etl import _fill_product_catalog

    fresh = DimProduct(product_code="X", product_name="Gula Pasir 1kg")
    _fill_product_catalog(fresh)
    assert "gula" in fresh.description.lower()
    assert fresh.image_url.endswith("gula-pasir-1kg.svg")

    kept = DimProduct(product_code="Y", product_name="Gula Pasir 1kg",
                      description="Custom", image_url="/custom.png")
    _fill_product_catalog(kept)
    assert kept.description == "Custom"
    assert kept.image_url == "/custom.png"

    unknown = DimProduct(product_code="Z", product_name="Barang Asing")
    _fill_product_catalog(unknown)
    assert (unknown.description or "") == ""
    assert (unknown.image_url or "") == ""


def test_abc_enrichment_reads_dim_product(db_session):
    from app.api.v1.analytics import _attach_product_meta
    from app.database.models import DimProduct

    db_session.add(DimProduct(product_code="P1", product_name="Teh Celup 25 Kantong",
                              description="Deskripsi toko", image_url="/toko.png"))
    db_session.commit()

    rows = _attach_product_meta(db_session, [
        {"product": "Teh Celup 25 Kantong", "revenue": 5.0},
        {"product": "Tanpa Meta", "revenue": 1.0},
    ])
    assert rows[0]["description"] == "Deskripsi toko"
    assert rows[0]["image_url"] == "/toko.png"
    assert rows[0]["revenue"] == 5.0
    assert rows[1]["description"] is None
    assert rows[1]["image_url"] is None
