<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityProfile extends Model
{
    protected $fillable = [
        'name',
        'dataset_type',
        'rules',
        'severity',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'active' => 'boolean',
        ];
    }

    public function scopeOfType($query, ?string $type)
    {
        return $type ? $query->where('dataset_type', $type) : $query;
    }

    public function scopeActive($query, ?bool $active = true)
    {
        return $active === null ? $query : $query->where('active', $active);
    }
}
