def test_etl_sales_sqlite(sample_csv):
    from app.database.connection import Base, engine
    from sqlalchemy.orm import sessionmaker
    from app.database.models import FactSales  # noqa: F401
    from app.ingestion.etl import run_etl
    from app.ingestion.mapper import suggest_mapping
    from app.ingestion.reader import read_full

    Base.metadata.create_all(engine)
    Session = sessionmaker(bind=engine)
    db = Session()
    try:
        df = read_full(sample_csv)
        sugg = suggest_mapping(list(df.columns), "sales")
        mappings = {s["source_column"]: s["target_field"] for s in sugg if s["target_field"]}
        res = run_etl(sample_csv, "sales", mappings, None, db)
        assert res["processed_rows"] >= 3
    finally:
        db.close()


def test_quality_duplicates(duplicates_csv):
    from app.ingestion.quality import run_quality_checks
    from app.ingestion.reader import read_full

    df = read_full(duplicates_csv)
    res = run_quality_checks(df)
    assert res["score"] < 1.0
    assert any(i["rule"] == "duplicate" for i in res["issues"])
