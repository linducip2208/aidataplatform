"""Nightly reconciliation task: read-only summary, stuck-job detection.

Covers the former ``sync placeholder`` — the task must report real database
state (totals, stuck non-terminal jobs untouched for 24h, last-24h summary)
and must never write.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone


def _job(status="queued", **kw):
    from app.database.models import ImportJob

    job = ImportJob(status=status, dataset_type="sales")
    for k, v in kw.items():
        setattr(job, k, v)
    return job


def _run():
    from app.workers.tasks import scheduled_data_sync

    run = getattr(scheduled_data_sync, "run", scheduled_data_sync)
    return run()


def test_reports_totals_and_never_writes(db_session):
    from app.database.models import ImportJob

    db_session.add_all([_job("queued"), _job("queued"), _job("done")])
    db_session.commit()
    before = db_session.query(ImportJob).count()

    res = _run()

    assert res["status"] == "ok"
    assert res["totals"].get("queued") == 2
    assert res["totals"].get("done") == 1
    assert "checked_at" in res
    # Read-only: no rows added, none removed.
    assert db_session.query(ImportJob).count() == before


def test_detects_stuck_non_terminal_jobs(db_session):
    old = datetime.now(timezone.utc) - timedelta(hours=30)
    stuck = _job("uploaded")
    fresh = _job("queued")
    db_session.add_all([stuck, fresh])
    db_session.commit()
    # Age only the uploaded row past the 24h cutoff.
    stuck.updated_at = old
    db_session.commit()

    res = _run()

    assert stuck.id in res["stuck"]["job_ids"]
    assert fresh.id not in res["stuck"]["job_ids"]
    assert res["stuck"]["count"] == len(res["stuck"]["job_ids"])


def test_terminal_jobs_are_never_stuck(db_session):
    old = datetime.now(timezone.utc) - timedelta(hours=48)
    job = _job("failed")
    db_session.add(job)
    db_session.commit()
    job.updated_at = old
    db_session.commit()

    res = _run()

    assert res["stuck"]["job_ids"] == []
