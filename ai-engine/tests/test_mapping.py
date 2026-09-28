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


def test_save_load_template(tmp_path):
    from app.ingestion.mapper import load_template, save_template

    save_template("t1", "sales", {"a": "b"})
    assert load_template("t1") is not None
