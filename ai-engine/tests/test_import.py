def test_reader_chunks(sample_csv):
    from app.ingestion.reader import iter_chunks

    chunks = list(iter_chunks(sample_csv, chunksize=2))
    assert len(chunks) == 2
    assert len(chunks[0]) == 2


def test_import_upload_and_preview(client, sample_csv, service_headers):
    with open(sample_csv, "rb") as fh:
        r = client.post("/api/v1/imports/upload", files={"file": ("sales.csv", fh, "text/csv")},
                        data={"dataset_type": "sales"}, headers=service_headers)
    assert r.status_code == 200, r.text
    assert r.json()["success"] is True
    job_id = r.json()["data"]["import_job_id"]
    r2 = client.get(f"/api/v1/imports/preview/{job_id}", headers=service_headers)
    assert r2.status_code == 200, r2.text
    assert r2.json()["data"]["row_count"] >= 3


def test_import_upload_requires_service_key(client, sample_csv):
    with open(sample_csv, "rb") as fh:
        r = client.post("/api/v1/imports/upload", files={"file": ("sales.csv", fh, "text/csv")},
                        data={"dataset_type": "sales"})
    assert r.status_code == 401, r.text
