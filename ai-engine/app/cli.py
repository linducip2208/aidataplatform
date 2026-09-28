"""CLI: python -m app.cli <import|train|evaluate|predict> ... (run from ai-engine/)."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


def cmd_import(args: argparse.Namespace) -> int:
    from app.database.connection import SessionLocal
    from app.database.models import ImportJob, RawUpload
    from app.ingestion.etl import run_etl
    from app.ingestion.validator import validate_file

    p = Path(args.file)
    v = validate_file(p)
    print(json.dumps({"validation": v}, indent=2, default=str))
    if not v["ok"] and not args.force:
        print("validation failed, use --force to continue")
        return 2
    mappings = json.loads(args.mappings) if args.mappings else {}
    db = SessionLocal()
    try:
        raw = RawUpload(filename=p.name, stored_path=str(p),
                        size_bytes=v["meta"].get("size_bytes", 0),
                        mime=v["meta"].get("mime", ""),
                        checksum_sha256=v["meta"].get("checksum_sha256", ""))
        db.add(raw)
        db.commit()
        db.refresh(raw)
        job = ImportJob(upload_id=raw.id, dataset_type=args.dataset, status="running")
        db.add(job)
        db.commit()
        db.refresh(job)
        res = run_etl(p, args.dataset, mappings, job.id, db)
        print(json.dumps(res, indent=2, default=str))
        return 0
    finally:
        db.close()


def cmd_train(args: argparse.Namespace) -> int:
    import pandas as pd

    from app.ml.training import train_model

    dataset = []
    if args.data:
        df = pd.read_csv(args.data)
        dataset = df.to_dict("records")
    params = json.loads(args.params) if args.params else {}
    res = train_model(args.model_type, args.name, params, dataset)
    print(json.dumps(res, indent=2, default=str))
    return 0


def cmd_evaluate(args: argparse.Namespace) -> int:
    print(json.dumps({"status": "evaluate via /api/v1/training/predict or registry metrics"}, indent=2))
    return 0


def cmd_predict(args: argparse.Namespace) -> int:
    from app.ml.forecasting import forecast

    hist = json.loads(args.payload) if args.payload else []
    horizon = int(args.horizon or 30)
    print(json.dumps(forecast(hist, horizon), indent=2, default=str))
    return 0


def build_parser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser(prog="ai-engine")
    sub = ap.add_subparsers(dest="cmd", required=True)
    pi = sub.add_parser("import", help="import a file into warehouse")
    pi.add_argument("file")
    pi.add_argument("--dataset", default="sales")
    pi.add_argument("--mappings", default="")
    pi.add_argument("--force", action="store_true")
    pi.set_defaults(func=cmd_import)
    pt = sub.add_parser("train", help="train a model")
    pt.add_argument("--model-type", default="forecast")
    pt.add_argument("--name", default="model")
    pt.add_argument("--params", default="")
    pt.add_argument("--data", default="")
    pt.set_defaults(func=cmd_train)
    pe = sub.add_parser("evaluate", help="evaluate")
    pe.set_defaults(func=cmd_evaluate)
    pp = sub.add_parser("predict", help="forecast predict")
    pp.add_argument("--payload", default="[]")
    pp.add_argument("--horizon", default="30")
    pp.set_defaults(func=cmd_predict)
    return ap


def main(argv=None) -> int:
    ap = build_parser()
    args = ap.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
