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

## 5. Rollback

Recovery is no longer "promote the old version by hand":
`POST /api/v1/models/{model_id}/rollback` (`app/ml/registry.py::rollback`)
re-activates the most recent `ARCHIVED` predecessor of the serving version in
one audited step. `ARCHIVED` stays terminal for `promote` by design, so this is
the only path back — a broken version cannot be silently re-promoted, and a
rollback with no serving version (or no archived predecessor) fails explicitly
(`422 NO_ROLLBACK_TARGET`), never by re-pointing at the same version.

The step writes three trail entries: a `lifecycle` transition for the retired
version (`PRODUCTION → ARCHIVED`), a `lifecycle` transition for the restored
one (`ARCHIVED → PRODUCTION`), and a `rollback` entry naming both version ids —
plus the matching deployment transitions (old `SERVING → RETIRED`, new →
`SERVING`). Laravel exposes it as `POST /api/ml/models/{modelId}/rollback`
(admin only, 200) with a `model.rolled_back` audit row, and the Models page
carries a rollback button with a note field next to the version history.

## 6. Audit trail and deployment status

Every promotion, retirement and rollback is recorded in `ml_model_events`
(DDL owned by master; SQLAlchemy spec in `app/ml/registry.py::ModelEvent`):
`{model_id, version_id, event_type, from_status, to_status, actor, note,
created_at}` with `event_type` in `lifecycle | deployment | rollback`. The
auto-retirement of a superseded production version writes its own `lifecycle`
entry naming the replacing version — a predecessor never flips to `ARCHIVED`
silently. Read the trail with `GET /api/v1/models/{model_id}/events` (Laravel:
`GET /api/ml/models/{modelId}/events`, any authenticated role), which also
reports the current deployment status of every version.

Lifecycle (`DRAFT → TRAINING → VALIDATED → PRODUCTION → ARCHIVED`, plus
`FAILED` and the `STAGED` label) says what reviewers decided; deployment says
what is actually serving: `PENDING → STAGING → SERVING`, with `FAILED` and
`RETIRED` as exits and one legal return (`FAILED → STAGING` for an explicit
redeploy). New versions start `PENDING`; promoting to `PRODUCTION` moves the
version to `SERVING`. The transition rules live in
`app/ml/registry.py::DEPLOYMENT_TRANSITIONS` and are enforced by
`set_deployment_status`, which refuses skips (e.g. `PENDING → SERVING`) with
the allowed targets named. Complete per-version metadata — version string,
training timestamp, dataset version, features, metrics, params, artifact path,
lifecycle status, deployment status — is served by
`GET /api/v1/models/{model_id}/detail` (Laravel:
`GET /api/ml/models/{modelId}/detail`). Fields that predate provenance are
`null`, which means "not recorded", never a back-fill. The legacy
`GET /models/{model_id}` shape is unchanged.

## 7. Promotion/rollback policy (admin)

1. Compare before promoting: rank the candidate experiments on the validation
   split (`POST …/experiments/{id}/compare`) and promote the measured winner,
   not the newest version.
2. Roll back on signal, not on suspicion: a rollback retires the serving
   version, so it needs the same evidence bar as a promotion — degraded
   metrics, missing/corrupt artifact, or a bad deploy note recorded on the
   rollback entry.
3. One serving version always: promotion auto-archives the predecessor and
   rollback auto-archives the broken one; the trail shows exactly one `SERVING`
   version per model at any time.
4. The `note` is the rationale: `rollback` accepts an actor note and every
   entry lands in `ml_model_events` plus Laravel's `audit_logs` — the two
   together are the decision record §4 used to ask you to keep elsewhere.
