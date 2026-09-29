<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored AI report generation (scheduled or on-demand).
 */
class GeneratedReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'period',
        'status',
        'payload',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function narrative(): string
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return (string) ($payload['narrative'] ?? '');
    }
}
