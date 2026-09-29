<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version history of a dataset. A new row is registered every time the
 * dataset's schema or content is snapshotted (upload, re-preview after a
 * source change, commit). Versions are numbered 1, 2, ... per dataset.
 */
class DatasetVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'dataset_id',
        'version',
        'schema_snapshot',
        'schema_hash',
        'row_count',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_snapshot' => 'array',
            'row_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextVersionNumber(Dataset $dataset): int
    {
        return (int) static::query()->where('dataset_id', $dataset->getKey())->max('version') + 1;
    }
}
