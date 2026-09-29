<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor',
        'action',
        'resource',
        'resource_id',
        'ip',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function record(string $action, string $resource = '', ?int $resourceId = null, array $detail = [], ?Authenticatable $actor = null): self
    {
        // `request()->user()` rather than `auth()->user()`: the API is
        // authenticated by the `sanctum` guard while `auth()` resolves the `web`
        // guard, which has no user on a bearer-token request. Using `auth()`
        // silently wrote a null actor for every API action.
        //
        // Callers that act on a user the request is not yet authenticated as —
        // a successful API login, for instance — pass the actor explicitly.
        $actor ??= request()->user();

        return static::create([
            'user_id' => $actor?->getKey(),
            'actor' => $actor instanceof User ? $actor->email : ($actor?->getAuthIdentifier() ?? 'system'),
            'action' => $action,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'ip' => request()->ip(),
            'detail' => $detail,
        ]);
    }
}
