def test_suggest_mapping_id_aliases():
    from app.ingestion.mapper import suggest_mapping

    cols = ["Tanggal Transaksi", "Kd Brg", "Nm Brg", "Jml", "Harga Jual", "Nm Cabang"]
    s = {x["source_column"]: x for x in suggest_mapping(cols, "sales")}
    assert s["Tanggal Transaksi"]["target_field"] == "transaction_date"
    assert s["Kd Brg"]["target_field"] == "product_code"
    assert s["Nm Brg"]["target_field"] == "product_name"
    assert s["Jml"]["target_field"] == "quantity"
    assert s["Harga Jual"]["target_field"] == "selling_price"
    assert s["Nm Cabang"]["target_field"] == "branch_name"


def test_fuzzy_mapping():
    from app.ingestion.mapper import suggest_mapping

    s = suggest_mapping(["tanggal transksi", "qty"], "sales")
    assert s[0]["target_field"] == "transaction_date"


def test_unknown_column_is_left_untargeted():
    """An unrecognised header must come back unmapped, not guessed at."""
    from app.ingestion.mapper import suggest_mapping

    s = {x["source_column"]: x for x in suggest_mapping(["Kolom Misterius", "Catatan"], "sales")}
    for col in ("Kolom Misterius", "Catatan"):
        assert s[col]["target_field"] is None, col
        assert s[col]["method"] == "none", col
        assert s[col]["confidence"] == 0.0, col


def test_every_suggestion_carries_the_documented_keys():
    from app.ingestion.mapper import suggest_mapping

    for row in suggest_mapping(["Nm Brg", "Kolom Misterius", "revenue_id"], "sales"):
        assert set(row) == {"source_column", "target_field", "confidence", "method"}
        assert 0.0 <= row["confidence"] <= 1.0
        assert row["method"] in {"exact", "fuzzy", "semantic", "none"}


def test_fuzzy_confidence_is_below_exact():
    from app.ingestion.mapper import suggest_mapping

    s = {x["source_column"]: x for x in suggest_mapping(["revenue_id", "Total"], "sales")}
    assert s["revenue_id"]["method"] == "fuzzy"
    assert s["revenue_id"]["target_field"] == "revenue"
    assert 0.78 <= s["revenue_id"]["confidence"] < s["Total"]["confidence"]
    assert s["Total"]["method"] == "exact" and s["Total"]["confidence"] == 1.0


def test_apply_mapping_renames_only_mapped_present_columns():
    import pandas as pd

    from app.ingestion.mapper import apply_mapping

    df = pd.DataFrame([{"Nm Brg": "Laptop", "Kolom Misterius": "x"}])
    out = apply_mapping(df, {"Nm Brg": "product_name", "Tidak Ada": "product_code",
                             "Kolom Misterius": None})
    assert list(out.columns) == ["product_name", "Kolom Misterius"]
    assert out.iloc[0]["product_name"] == "Laptop"


def test_apply_mapping_with_empty_mapping_is_a_no_op():
    import pandas as pd

    from app.ingestion.mapper import apply_mapping

    df = pd.DataFrame([{"Nm Brg": "Laptop"}])
    assert list(apply_mapping(df, {}).columns) == ["Nm Brg"]


def test_save_load_template(tmp_path):
    from app.ingestion.mapper import load_template, save_template

    save_template("t1", "sales", {"a": "b"})
    assert load_template("t1") is not None
