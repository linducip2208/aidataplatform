<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-column registry for a dataset: the schema registry entry plus the
 * steward annotations (business description, sensitivity, PII flag) and the
 * latest observed statistics.
 */
class ColumnMetadata extends Model
{
    use HasFactory;

    protected $table = 'column_metadata';

    public const SENSITIVITY_LOW = 'low';

    public const SENSITIVITY_INTERNAL = 'internal';

    public const SENSITIVITY_CONFIDENTIAL = 'confidential';

    public const SENSITIVITY_RESTRICTED = 'restricted';

    /** @return list<string> */
    public static function sensitivities(): array
    {
        return [
            self::SENSITIVITY_LOW,
            self::SENSITIVITY_INTERNAL,
            self::SENSITIVITY_CONFIDENTIAL,
            self::SENSITIVITY_RESTRICTED,
        ];
    }

    protected $fillable = [
        'dataset_id',
        'name',
        'dtype',
        'nullable',
        'is_pii',
        'sensitivity',
        'business_description',
        'distinct_count',
        'null_pct',
        'min_value',
        'max_value',
    ];

    protected function casts(): array
    {
        return [
            'nullable' => 'boolean',
            'is_pii' => 'boolean',
            'distinct_count' => 'integer',
            'null_pct' => 'float',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
