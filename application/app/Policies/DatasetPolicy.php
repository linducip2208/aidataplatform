<?php

namespace App\Policies;

use App\Models\Dataset;
use App\Models\User;

/**
 * Intended ownership policy for datasets.
 *
 * STATUS: defined here, NOT YET ENFORCED. The controllers
 * (`DatasetController` web + `Api\DatasetController`, `DatasetWorkflowController`)
 * currently perform no per-row ownership check: any authenticated user can
 * list/view any dataset, and any `admin`/`analyst` can mutate or delete any
 * dataset. That is AUDIT FINDING A8-03 (see `docs/security.md` §8) — the fix
 * is for master to call these methods from the controllers, which TIGHTENS
 * the surface (never loosens it: everything the policy allows, the
 * controllers already allow today, except cross-owner write/delete which the
 * policy refuses).
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

        return $user->role()->value === 'analyst'
            && (int) $dataset->user_id === (int) $user->getKey();
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $this->update($user, $dataset);
    }
}
