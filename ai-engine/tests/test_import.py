def test_reader_chunks(sample_csv):
    from app.ingestion.reader import iter_chunks

    chunks = list(iter_chunks(sample_csv, chunksize=2))
    assert len(chunks) == 2
    assert len(chunks[0]) == 2


def test_import_upload_and_preview(client, sample_csv):
    with open(sample_csv, "rb") as fh:
        r = client.post("/api/v1/imports/upload", files={"file": ("sales.csv", fh, "text/csv")},
                        data={"dataset_type": "sales"})
    assert r.status_code == 200, r.text
    job_id = r.json()["data"]["import_job_id"]
    r2 = client.get(f"/api/v1/imports/preview/{job_id}")
    assert r2.status_code == 200
    assert r2.json()["data"]["row_count"] >= 3
