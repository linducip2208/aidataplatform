"""Column mapper: the in-canonical invariant, refusal rules, and deduplication.

The single invariant that matters here is stated in ``suggest_mapping``'s own
docstring: **a returned target is always a member of
``CANONICAL_FIELDS[dataset_type]``, or it is None.** An alias shared across
datasets ("tanggal" means transaction_date for sales but purchase_date for
purchases) must never resolve to a field that dataset's loader never reads, or
the ETL writes a column nobody selects and the import looks successful.

The companion invariant lives in ``apply_mapping``: two sources claiming one
target must not produce a duplicated column name, because ``df["target"]``
returning a DataFrame instead of a Series breaks every downstream consumer in a
way that is very hard to trace back to the mapper.
"""
import pytest

from app.ingestion.mapper import ALIAS_MAP, CANONICAL_FIELDS, apply_mapping, suggest_mapping


def _by_source(rows):
    return {row["source_column"]: row for row in rows}


# --------------------------------------------------------------------------
# the in-canonical invariant
# --------------------------------------------------------------------------
@pytest.mark.parametrize("dataset_type", sorted(CANONICAL_FIELDS))
def test_no_alias_ever_resolves_outside_the_canonical_list(dataset_type):
    """Every alias, against every dataset, for the dataset that owns the field.

    This is the whole safety property in one loop: a target is either None or a
    field this dataset's loader reads.
    """
    canonical = CANONICAL_FIELDS[dataset_type]
    for alias in list(ALIAS_MAP) + list(canonical):
        for row in suggest_mapping([alias], dataset_type):
            target = row["target_field"]
            assert target is None or target in canonical, (
                f"{dataset_type}: {alias!r} resolved to {target!r}, which is not canonical"
            )


@pytest.mark.parametrize("dataset_type", sorted(CANONICAL_FIELDS))
def test_applying_a_suggested_mapping_introduces_no_column_outside_the_canonical_list(dataset_type):
    """The end-to-end version: after apply_mapping, every *new* column name is canonical.

    Columns the mapper did not touch keep their original source name by design,
    so the assertion is on names the mapping introduced.
    """
    pd = pytest.importorskip("pandas")
    canonical = CANONICAL_FIELDS[dataset_type]
    sources = [a for a in ALIAS_MAP if suggest_mapping([a], dataset_type)[0]["target_field"]]
    frame = pd.DataFrame([{col: f"v{i}" for i, col in enumerate(sources)}])

    mapping = {r["source_column"]: r["target_field"]
               for r in suggest_mapping(sources, dataset_type) if r["target_field"]}
    out = apply_mapping(frame, mapping)

    introduced = set(out.columns) - set(frame.columns)
    assert introduced <= set(canonical), introduced - set(canonical)


def test_a_fully_mapped_frame_is_a_strict_subset_of_the_canonical_list():
    """With every source mapped, the result *is* a subset of the canonical fields."""
    pd = pytest.importorskip("pandas")
    canonical = CANONICAL_FIELDS["sales"]
    sources = [c for c in canonical if suggest_mapping([c], "sales")[0]["target_field"]]
    frame = pd.DataFrame([{col: i for i, col in enumerate(sources)}])

    mapping = {r["source_column"]: r["target_field"]
               for r in suggest_mapping(sources, "sales") if r["target_field"]}
    out = apply_mapping(frame, mapping)

    assert set(out.columns) <= set(canonical)
    assert set(out.columns), "expected at least one mapped column"


def test_an_unknown_dataset_type_falls_back_to_the_sales_canonical_list():
    """Deliberate: an unrecognised dataset_type must still map, not crash."""
    rows = suggest_mapping(["Nm Brg"], "not_a_real_dataset")
    assert rows[0]["target_field"] == "product_name"
    assert rows[0]["target_field"] in CANONICAL_FIELDS["sales"]


# --------------------------------------------------------------------------
# refusal: an unknown column and an out-of-canonical target
# --------------------------------------------------------------------------
def test_suggest_mapping_id_aliases():
    cols = ["Tanggal Transaksi", "Kd Brg", "Nm Brg", "Jml", "Harga Jual", "Nm Cabang"]
    s = _by_source(suggest_mapping(cols, "sales"))
    assert s["Tanggal Transaksi"]["target_field"] == "transaction_date"
    assert s["Kd Brg"]["target_field"] == "product_code"
    assert s["Nm Brg"]["target_field"] == "product_name"
    assert s["Jml"]["target_field"] == "quantity"
    assert s["Harga Jual"]["target_field"] == "selling_price"
    assert s["Nm Cabang"]["target_field"] == "branch_name"


def test_fuzzy_match_falls_below_the_exact_cutoff():
    """"revenue_id" is not an alias, so only a close match can resolve it."""
    s = _by_source(suggest_mapping(["revenue_id"], "sales"))
    assert s["revenue_id"]["method"] == "fuzzy"
    assert s["revenue_id"]["target_field"] == "revenue"
    assert s["revenue_id"]["confidence"] < 1.0


def test_a_shared_date_alias_resolves_to_the_datasets_own_date_column():
    """"tanggal" is transaction_date for sales, but purchases has no such field.

    The alias is refused (the target is not canonical for purchases) and the
    semantic date hint supplies the field that dataset actually has.
    """
    assert _by_source(suggest_mapping(["tanggal"], "sales"))["tanggal"]["target_field"] == \
        "transaction_date"

    for dataset, expected in (("purchases", "purchase_date"),
                              ("inventory", "snapshot_date"),
                              ("expenses", "expense_date")):
        row = _by_source(suggest_mapping(["tanggal"], dataset))["tanggal"]
        assert row["target_field"] == expected, dataset
        assert row["method"] == "semantic"
        assert row["confidence"] < 1.0


def test_a_target_outside_the_canonical_list_is_refused_entirely():
    """"revenue" and "quantity" are real fields -- just not on an expense row.

    There is no date/quantity hint for expenses, so the correct answer is no
    target at all rather than a plausible-looking wrong one.
    """
    for column in ("revenue", "revenue_id", "Total", "Jml", "Qty"):
        row = _by_source(suggest_mapping([column], "expenses"))[column]
        assert row["target_field"] is None, column
        assert row["method"] == "none" and row["confidence"] == 0.0, column


def test_unknown_column_is_left_untargeted():
    """An unrecognised header must come back unmapped, not guessed at."""
    s = _by_source(suggest_mapping(["Kolom Misterius", "Catatan"], "sales"))
    for col in ("Kolom Misterius", "Catatan"):
        assert s[col]["target_field"] is None, col
        assert s[col]["method"] == "none", col
        assert s[col]["confidence"] == 0.0, col


def test_every_suggestion_carries_the_documented_keys():
    for row in suggest_mapping(["Nm Brg", "Kolom Misterius", "revenue_id"], "sales"):
        assert set(row) == {"source_column", "target_field", "confidence", "method"}
        assert 0.0 <= row["confidence"] <= 1.0
        assert row["method"] in {"exact", "fuzzy", "semantic", "none"}


def test_exact_always_outranks_fuzzy():
    s = _by_source(suggest_mapping(["revenue_id", "Total"], "sales"))
    assert s["revenue_id"]["method"] == "fuzzy"
    assert s["revenue_id"]["target_field"] == "revenue"
    assert 0.78 <= s["revenue_id"]["confidence"] < s["Total"]["confidence"]
    assert s["Total"]["method"] == "exact" and s["Total"]["confidence"] == 1.0


def test_matching_ignores_case_underscores_and_surrounding_whitespace():
    """Deliberate: operators hand-edit CSV headers and type them inconsistently."""
    for column in ("NM BRG", "nm_brg", "  Nm Brg  ", "Nm  Brg"):
        row = _by_source(suggest_mapping([column], "sales"))[column]
        assert row["target_field"] == "product_name", column
        assert row["method"] == "exact" and row["confidence"] == 1.0, column


def test_one_row_per_input_column_in_input_order():
    cols = ["Nm Brg", "Kolom Misterius", "Jml", "Total"]
    rows = suggest_mapping(cols, "sales")
    assert [r["source_column"] for r in rows] == cols


# --------------------------------------------------------------------------
# apply_mapping
# --------------------------------------------------------------------------
def test_apply_mapping_renames_only_mapped_present_columns():
    pd = pytest.importorskip("pandas")

    df = pd.DataFrame([{"Nm Brg": "Laptop", "Kolom Misterios": "x"}])
    out = apply_mapping(df, {"Nm Brg": "product_name", "Tidak Ada": "product_code",
                             "Kolom Misterios": None})
    assert list(out.columns) == ["product_name", "Kolom Misterios"]
    assert out.iloc[0]["product_name"] == "Laptop"


def test_apply_mapping_with_empty_mapping_is_a_no_op():
    pd = pytest.importorskip("pandas")

    df = pd.DataFrame([{"Nm Brg": "Laptop"}])
    assert list(apply_mapping(df, {}).columns) == ["Nm Brg"]
    assert list(apply_mapping(df, None).columns) == ["Nm Brg"]


def test_two_sources_claiming_one_target_never_produce_a_duplicate_name():
    """``df["product_name"]`` must stay a Series, not become a DataFrame."""
    pd = pytest.importorskip("pandas")

    df = pd.DataFrame([{"Nm Brg": "Laptop", "product name": "Laptop", "Jml": 2}])
    out = apply_mapping(df, {"Nm Brg": "product_name", "product name": "product_name"})

    assert list(out.columns).count("product_name") == 1
    assert out.columns.is_unique
    # The first mapping wins and the loser is dropped entirely rather than left
    # under its original name: keeping it would put a non-canonical column in a
    # frame the ETL is about to treat as canonical.
    assert "product name" not in out.columns
    assert isinstance(out["product_name"], pd.Series)


def test_an_empty_target_is_never_applied():
    pd = pytest.importorskip("pandas")

    df = pd.DataFrame([{"Kolom Misterius": "x"}])
    for mapping in ({"Kolom Misterius": None}, {"Kolom Misterios": ""}):
        out = apply_mapping(df, mapping)
        assert list(out.columns) == ["Kolom Misterius"]


# --------------------------------------------------------------------------
# templates (on-disk cache; the database row is the other source)
# --------------------------------------------------------------------------
@pytest.fixture()
def template_dir(monkeypatch, tmp_path):
    """Redirect the template directory into tmp_path.

    ``_TEMPLATE_DIR`` is computed at import time from ``settings.storage_path``,
    so without this the test writes into the real ``datasets/`` tree.
    """
    from app.ingestion import mapper

    target = tmp_path / "mapping_templates"
    monkeypatch.setattr(mapper, "_TEMPLATE_DIR", target)
    return target


def test_save_and_load_round_trip(template_dir):
    from app.ingestion.mapper import load_template, save_template

    payload = save_template("t1", "sales", {"a": "b"})
    assert payload == {"name": "t1", "dataset_type": "sales", "mapping": {"a": "b"}}
    assert (template_dir / "t1.json").exists()

    loaded = load_template("t1")
    assert loaded is not None
    assert loaded["dataset_type"] == "sales" and loaded["mapping"] == {"a": "b"}


def test_saving_the_same_name_twice_overwrites_rather_than_appending(template_dir):
    from app.ingestion.mapper import load_template, save_template

    save_template("t1", "sales", {"a": "b"})
    save_template("t1", "inventory", {"c": "d"})
    loaded = load_template("t1")
    assert loaded["dataset_type"] == "inventory" and loaded["mapping"] == {"c": "d"}


def test_loading_an_unknown_template_is_none_not_an_error(template_dir):
    from app.ingestion.mapper import load_template

    assert load_template("never-saved") is None
