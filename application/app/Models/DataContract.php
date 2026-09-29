<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data contract for a dataset: who owns it, which schema hash it promises,
 * how fresh the data must stay, and the minimum quality score.
 */
class DataContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'dataset_id',
        'owner',
        'schema_hash',
        'freshness_sla_hours',
        'quality_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'freshness_sla_hours' => 'integer',
            'quality_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
