<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One model discovered from a provider's models endpoint. Metadata only:
 * capabilities are detected from the API response, never invented, and the
 * provider row's own `model` column stays the pinned default.
 */
class AiProviderModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_provider_id',
        'external_id',
        'name',
        'capabilities',
        'metadata',
        'is_active',
        'last_seen_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** @return list<string> */
    public function capabilityList(): array
    {
        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), (array) ($this->capabilities ?? [])),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
