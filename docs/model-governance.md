# Model Governance

Gate between training and production. Roles: analyst (may train), admin (may promote),
viewer (read only). The one irreversible action — moving a version into production — is
admin-only and writes an `audit_logs` row.

## 1. Lifecycle

```
DRAFT -> TRAINING -> VALIDATED -> PRODUCTION -> ARCHIVED
                         |                          ^
                         +--------------------------+
                            FAILED (terminal, from TRAINING)

A newly trained version is always VALIDATED.
```

This is `app/ml/registry.py::FLOW`, and it is a documentation convention rather than an
enforced state machine: `promote(model_id, version_id, to_status)` writes whatever string it
is handed onto `model_versions.status` and `ml_models.status`. Keep the vocabulary above and
the rows stay interpretable.

Two vocabularies meet here and they do not quite line up, which is worth knowing before you
script anything:

- The engine's states are `DRAFT`, `TRAINING`, `VALIDATED`, `PRODUCTION`, `ARCHIVED`,
  `FAILED`.
- `App\Http\Controllers\Api\MlController` allowlists exactly three for `to_status`:
  `PRODUCTION`, `STAGED`, `ARCHIVED`.

`STAGED` is not one of the engine's states, so promoting to it stores the value without any
effect. `VALIDATED` is not one of the values Laravel will accept, so holding a version back at
`VALIDATED` needs a direct engine call. The overlap — the part that actually works from the
platform — is `PRODUCTION` and `ARCHIVED`.

Only a `PRODUCTION` version is served by the churn prediction path. `load_production`
falls back to the newest `VALIDATED` version when `ml_models.production_version_id` is null,
so a model that was never promoted is still usable — just unversioned and unaudited. Prefer
an explicit promotion.

## 2. API + UI

- List: `GET /api/ml/models` (any authenticated role) and `GET /api/ml/models/{modelId}`,
  which returns the model plus its `versions[]` with each version's `status`, `metrics` and
  `artifact_path`. Laravel proxies the engine's `GET /api/v1/models` and
  `GET /api/v1/models/{model_id}`.
- Promote: `POST /api/ml/models/{modelId}/promote` with `{"version_id":…, "to_status":…}` —
  **admin only**; analyst and viewer get `403` with `code: forbidden`.
- Train: `POST /api/ml/train` with `{"model_type","name","params"}` — admin or analyst. It
  runs synchronously and answers `202`; there is no queue behind it and no job id in the
  response. See `machine-learning.md` §1 for the `params`-is-a-string gotcha.
- Engine direct: `POST /api/v1/models/{model_id}/promote` accepts the same body and the whole
  `FLOW` vocabulary.
- UI: the **Models** page lists the registry and the version history.

There is no `?stage=` filter, no approve/reject/archive endpoint, no `ml.approvals` table and
no `ml.experiments` or `ml.features` table in this build. The audit trail for a decision is
the `model.trained` and `model.promoted` row in `audit_logs`, not an approvals record.

## 3. Promotion checklist (admin)

1. The version exists and is `VALIDATED`. `POST /api/v1/models/{id}` lists the versions and
   their statuses; a version still in `TRAINING` means the training call has not returned.
2. Metrics are sane against the version you are replacing. `metrics` on `model_versions` is a
   free-form JSONB blob filled from the trainer, so what is comparable depends entirely on
   `model_type`; there is no cross-model metric registry to check against.
3. Source-data quality. There is no link from a model to the dataset it was trained on —
   `train_model` receives inline rows or nothing at all, and `prediction_runs` is never
   written. Establish the provenance yourself and record it in the promotion note.
4. No PII in the request payload. The trainers read the columns you send, with no schema
   allowlist; `data-dictionary.md` is the reference for what each warehouse table holds.
5. The artifact is present. `artifact_path` is
   `Path(settings.model_path) / f"model_{model_id}_{version}.joblib"`. Under Compose that is
   the `models-cache` volume, so it survives a rebuild; a missing file raises `OSError` out of
   `joblib.load` rather than degrading quietly.

## 4. What is deliberately missing

| Gap | Consequence | Where to fix it |
|---|---|---|
| No approval workflow, only promotion | An analyst can train; only an admin can promote. There is no "propose for review" state. | `app/ml/registry.py::promote` |
| No model↔dataset link | Provenance is manual. | `train_model` would need the warehouse to carry it |
| `prediction_runs` never written | No inference history, no drift signal, no per-model usage number. | `app/api/v1/training.py::predict` |
| No rollback command | Recovery is promoting a previous `version_id` to `PRODUCTION` by hand. | `app/ml/registry.py` |
| `MLModel` and `ModelVersion` statuses are not constrained by the DB | A hand-edited row can hold any string; the UI would render an unknown badge. | Alembic 0001, and a check constraint in a new revision |
| `staging_data_sync` / `scheduled_data_sync` is a placeholder | There is no automatic retraining trigger, so nothing promotes on its own. | `app/workers/tasks.py` |

Until the approval table exists, the honest governance story is: an admin decides, the
decision is recorded in `audit_logs` under `model.promoted` with the `version_id` and the
target status, and nothing else is captured. Write the rationale somewhere durable; the
platform will not.
