# Model Governance

Gate between training and production. Roles: analyst (propose), admin (approve),
viewer (read). All decisions audited.

## 1. Lifecycle

```
draft (auto on train) -> staged (analyst requests review) -> production (admin approves)
                                                             -> archived (superseded/recalled)
                                                     \-> rejected (with reason)
```

Only `production` models serve live traffic. Quarantined-data models need explicit
admin override recorded in the approval note.

## 2. API + UI

- List: `GET /api/v1/ml/models?stage=draft` (service key; Laravel proxies with role check).
- Approve: `POST /api/v1/ml/models/{id}/approve {"note":"...","override_quality":false}` (admin only).
- Reject/archive: `POST /api/v1/ml/models/{id}/reject|archive` with reason.
- UI: **Models** page shows stage badges, metrics, quality verdict of source dataset,
 Approve/Reject buttons (admin), full history per model.

## 3. Approval checklist (admin)

1. Source dataset quality `pass` (or documented override + re-profile plan).
2. Metrics sane vs baseline (accuracy/F1 or RMSE logged in `ml.experiments`).
3. No PII leakage in features (check `ml.features` snapshot cols vs `data-dictionary.md`).
4. Artifact present in registry volume; version immutable.
5. Rollback named: previous `production` version kept as `archived`, one-click restore.

## 4. Audit

`ml.approvals(model_id, actor, from_stage, to_stage, note, created_at)` append-only;
Laravel audit log mirrors actor IP + user agent. Export for compliance from UI or
`SELECT * FROM ml.approvals WHERE model_id=...`.

## 5. Retention

Keep all `production` versions 1 year minimum; `draft` pruned after 90 days by Beat.
Backup covers `ml.*` via pg_dump (`backup-restore.md`); artifacts covered by
`models-cache` volume snapshots (documented in runbook `administrator.md`).
