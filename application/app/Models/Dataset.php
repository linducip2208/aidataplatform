<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use Database\Factories\DatasetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Dataset extends Model
{
    /** @use HasFactory<DatasetFactory> */
    use HasFactory;

    /**
     * Every column the application manages. This is a wide list on purpose: the
     * ingestion service, the seeders and the factories all write through
     * mass assignment, and narrowing it makes those drop attributes *silently*
     * rather than fail. A security review confirmed no client-controlled key
     * reaches `Dataset::create()` or `->update()` — the upload path builds a
     * literal server-side and the controllers pass only `file`/`name`/
     * `dataset_type`/`mappings.*` — so this is a latent footgun, not a live hole.
     *
     * Tightening it is still worth doing, as its own change: it would need
     * `DatasetIngestionService` (already on `forceFill`), `DatabaseSeeder`
     * (currently saved under `Model::unguarded()`) and the factories converted
     * together, or server-owned columns are dropped silently.
     *
     * `import_job_id` is covered here on purpose even though it is
     * server-owned: the workflow tests build post-upload rows through plain
     * `Dataset::create()`, which runs guarded, and a missing key made every
     * workflow step throw "has no import job yet". No client-controlled key
     * reaches a mass-assignment write (see above), so covering it changes no
     * request surface.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'dataset_type',
        'source_filename',
        'disk',
        'path',
        'size_bytes',
        'mime',
        'checksum_sha256',
        'status',
        'import_job_id',
        'row_count',
        'column_count',
        'columns',
        'mappings',
        'metadata',
        'quality_score',
        'quality_verdict',
        'quality_checked_at',
        'committed_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DatasetStatus::class,
            'columns' => 'array',
            'mappings' => 'array',
            'metadata' => 'array',
            'quality_score' => 'float',
            'quality_checked_at' => 'datetime',
            'committed_at' => 'datetime',
            'size_bytes' => 'integer',
            'import_job_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $dataset): void {
            $dataset->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Datasets are addressed by uuid everywhere: route model binding, the
     * documented `/api/datasets/{uuid}` contract, and `tests/run.sh`.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): DatasetStatus
    {
        return $this->status instanceof DatasetStatus ? $this->status : DatasetStatus::tryFromName($this->status);
    }

    public function qualityVerdict(): ?QualityVerdict
    {
        return $this->quality_verdict !== null
            ? (QualityVerdict::tryFrom($this->quality_verdict) ?? null)
            : null;
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('dataset_type', $type) : $query;
    }

    /** @param  Builder<self>  $query */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' PB';
    }

    /** @return array<int, string> */
    public function columnNames(): array
    {
        $names = [];

        foreach ((array) $this->columns as $column) {
            if (is_array($column) && isset($column['name'])) {
                $names[] = (string) $column['name'];
            } elseif (is_string($column)) {
                $names[] = $column;
            }
        }

        return $names;
    }
}
