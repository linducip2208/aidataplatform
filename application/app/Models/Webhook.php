<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outbound webhook subscription.
 */
class Webhook extends Model
{
    use HasFactory;

    public const EVENTS = [
        'dataset.committed',
        'report.generated',
        'alert.acknowledged',
        'decision.audited',
    ];

    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribes(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }
}
