def test_validate_ok(sample_csv):
    from app.ingestion.validator import validate_file

    v = validate_file(sample_csv)
    assert v["ok"]


def test_validate_empty(empty_csv):
    from app.ingestion.validator import validate_file

    v = validate_file(empty_csv)
    assert not v["ok"]


def test_validate_malformed(malformed_csv):
    from app.ingestion.validator import validate_file

    v = validate_file(malformed_csv)
    assert "meta" in v


def test_validate_missing(missing_csv):
    from app.ingestion.validator import validate_file

    v = validate_file(missing_csv)
    assert v["ok"] or v["warnings"] or v["errors"]
