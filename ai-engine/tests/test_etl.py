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


class _StubResult:
    def __init__(self, value):
        self._value = value

    def scalar(self):
        return self._value


class _StubSession:
    """A session with a scripted dialect name and recorded statements."""

    def __init__(self, dialect, scalar=1):
        self._dialect = dialect
        self._scalar = scalar
        self.statements = []

    def get_bind(self):
        session = self

        class _Bind:
            class dialect:
                name = session._dialect

        return _Bind()

    def execute(self, stmt, params=None):
        self.statements.append(str(stmt))
        return _StubResult(self._scalar)

    def commit(self):
        self.statements.append("COMMIT")

    def close(self):
        self.statements.append("CLOSE")


def test_serialise_job_mysql_acquires_and_releases_get_lock():
    from app.ingestion.etl import _serialise_job

    db = _StubSession("mysql")
    with _serialise_job(db, 7) as locked:
        assert locked is True
    assert any("GET_LOCK" in s for s in db.statements)
    assert any("RELEASE_LOCK" in s for s in db.statements)


def test_serialise_job_mysql_timeout_yields_false_without_releasing():
    from app.ingestion.etl import _serialise_job

    db = _StubSession("mysql", scalar=0)
    with _serialise_job(db, 7) as locked:
        assert locked is False
    assert any("GET_LOCK" in s for s in db.statements)
    assert not any("RELEASE_LOCK" in s for s in db.statements)


def test_serialise_job_unknown_dialect_yields_false_without_touching_the_db():
    from app.ingestion.etl import _serialise_job

    db = _StubSession("sqlite")
    with _serialise_job(db, 7) as locked:
        assert locked is False
    assert db.statements == []
