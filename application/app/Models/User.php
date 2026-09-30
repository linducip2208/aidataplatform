<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<Dataset, $this> */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    /** @return HasMany<ChatThread, $this> */
    public function chatThreads(): HasMany
    {
        return $this->hasMany(ChatThread::class);
    }

    public function role(): UserRole
    {
        return $this->role instanceof UserRole ? $this->role : UserRole::tryFromName($this->role);
    }

    public function isAdmin(): bool
    {
        return $this->role() === UserRole::Admin;
    }

    public function isAnalyst(): bool
    {
        return in_array($this->role(), [UserRole::Admin, UserRole::Analyst], true);
    }

    public function canWrite(): bool
    {
        return $this->isAnalyst() && $this->is_active;
    }

    public function canApproveModels(): bool
    {
        return $this->isAdmin() && $this->is_active;
    }

    /**
     * Pure permission-matrix helper for the enterprise audit surface.
     *
     * Delegates ONLY to the existing role predicates above (`isAdmin()`,
     * `isAnalyst()`, `canWrite()`, `canApproveModels()`) plus `is_active` and,
     * for row-level actions, a caller-supplied owner id. It introduces NO new
     * behaviour: no query, no state change, no new grant — every arm is a
     * restatement of a check the middleware/routes already perform. Controllers
     * keep their current gates; policies (`app/Policies/*`) are the
     * enforcement point once master wires them.
     *
     * Actions: `datasets.view`, `datasets.write`, `datasets.delete`,
     * `threads.view`, `threads.write`, `threads.delete`, `users.manage`,
     * `audit.view`, `models.approve`, `agent.chat`.
     *
     * Named `canDo()`, not `can()`: the Authenticatable base already defines
     * `can($abilities, $arguments = [])` (Gate-backed), and redeclaring it
     * with a narrower signature is a fatal error.
     */
    public function canDo(string $action, mixed $resource = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $ownerId = $this->ownerIdOf($resource);
        $isOwner = $ownerId !== null && (int) $ownerId === (int) $this->getKey();

        return match ($action) {
            // Shared-catalog reads: any active account (mirrors index/show).
            'datasets.view' => true,
            // Writes tighten to owner-or-admin once policies are wired.
            'datasets.write', 'datasets.delete' => $this->isAdmin()
                || ($this->role()->value === 'analyst' && $isOwner),
            // Threads are strictly owner-only (mirrors the abort_unless 404).
            'threads.view', 'threads.write', 'threads.delete' => $isOwner,
            'users.manage', 'audit.view' => $this->isAdmin(),
            'models.approve' => $this->canApproveModels(),
            // Any active account may chat (mirrors api/agent/chat: auth only).
            'agent.chat' => true,
            default => false,
        };
    }

    /**
     * Extract an owner user id from a model, array or raw id. Null means
     * "ownership unknown" (e.g. legacy rows with `user_id = null`), which
     * grants nothing on owner-gated actions.
     */
    private function ownerIdOf(mixed $resource): ?int
    {
        if ($resource === null) {
            return null;
        }

        if (is_int($resource)) {
            return $resource;
        }

        if (is_array($resource) && isset($resource['user_id'])) {
            return is_numeric($resource['user_id']) ? (int) $resource['user_id'] : null;
        }

        if (is_object($resource) && isset($resource->user_id)) {
            return is_numeric($resource->user_id) ? (int) $resource->user_id : null;
        }

        return null;
    }
}
