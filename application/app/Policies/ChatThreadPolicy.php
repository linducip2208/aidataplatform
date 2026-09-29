<?php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;

/**
 * Ownership policy for assistant chat threads.
 *
 * Mirrors the existing `abort_unless($thread->user_id === $request->user()->getKey(), 404)`
 * semantics in `AssistantController@show`/`@destroy` EXACTLY — including the
 * strict part: even an `admin` cannot open another account's thread (the
 * controller returns 404, not 403, so existence is not disclosed). Granting
 * admins a bypass here would LOOSEN the surface and is forbidden.
 *
 * Matrix (matches controllers today):
 *
 * | action | owner (any role, active) | non-owner incl. admin | inactive/guest |
 * |---|---|---|---|
 * | viewAny / create | yes | n/a (scoped to own) | no |
 * | view / update / delete | yes | no (404) | no |
 *
 * MASTER WIRING (master-owned files — A8 must not edit them):
 *
 *   // app/Providers/AppServiceProvider.php, inside boot():
 *   Gate::policy(\App\Models\ChatThread::class, \App\Policies\ChatThreadPolicy::class);
 */
class ChatThreadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, ChatThread $thread): bool
    {
        return $user->is_active
            && (int) $thread->user_id === (int) $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, ChatThread $thread): bool
    {
        return $this->view($user, $thread);
    }

    public function delete(User $user, ChatThread $thread): bool
    {
        return $this->view($user, $thread);
    }
}
