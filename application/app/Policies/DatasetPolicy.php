<?php

namespace App\Policies;

use App\Models\Dataset;
use App\Models\User;

/**
 * Intended ownership policy for datasets.
 *
 * STATUS: STRICTLY ENFORCED since iteration 5 (fail-closed, no legacy exception).
 * `Api\DatasetController` (quality/mapping/commit/destroy),
 * `Api\CatalogController` (versions/annotate/contracts), the web
 * `DatasetWorkflowController` (preview/mapping/quality/commit) and the web
 * `DatasetController@destroy` call `Gate::authorize('update'|'delete')`.
 * Reads stay global (shared catalog). That closed AUDIT FINDING A8-03
 * (see `docs/security.md` §8).
 *
 * STRICT, fail-closed: rows with `user_id = null` are writable by admins
 * only. The `backfill_dataset_owners` migration attributes pre-ownership
 * rows to the earliest admin so no historical row locks overnight; all new
 * uploads carry `user_id` (`DatasetIngestionService::createFromUpload`).
 *
 * Intended matrix (enforced once wired):
 *
 * | action | admin | analyst (owner) | analyst (other) | viewer | guest/inactive |
 * |---|---|---|---|---|---|
 * | viewAny / view | yes | yes | yes (read is global today) | yes | no |
 * | create / update | yes | yes (own) | no | no | no |
 * | delete | yes | yes (own) | no | no | no |
 *
 * Read stays global on purpose: the index/show endpoints are shared-catalog
 * reads today and several suites pin that. Ownership applies to WRITE and
 * DELETE only. Inactive users get nothing (mirrors `EnsureAccountActive`).
 *
 * MASTER WIRING (master-owned files — A8 must not edit them):
 *
 *   // app/Providers/AppServiceProvider.php, inside boot():
 *   Gate::policy(\App\Models\Dataset::class, \App\Policies\DatasetPolicy::class);
 *
 *   // then in each mutating controller method, e.g.:
 *   $this->authorize('update', $dataset);   // preview/mapping/quality/commit
 *   $this->authorize('delete', $dataset);   // destroy
 */
class DatasetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Dataset $dataset): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canWrite();
    }

    public function update(User $user, Dataset $dataset): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role()->value !== 'analyst') {
            return false;
        }

        return (int) $dataset->user_id === (int) $user->getKey();
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $this->update($user, $dataset);
    }
}
