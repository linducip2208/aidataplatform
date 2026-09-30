<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The single company this deployment serves (white-label profile).
 */
class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tagline',
        'logo_path',
    ];

    /**
     * The active profile: earliest row, cached for an hour. Null when the
     * table was never seeded — callers must fall back to config values.
     */
    public static function current(): ?self
    {
        return Cache::remember(
            'organization.current',
            now()->addHour(),
            static fn (): ?self => static::query()->orderBy('id')->first(),
        );
    }

    public static function forgetCurrent(): void
    {
        Cache::forget('organization.current');
    }

    public function displayName(): string
    {
        return trim((string) $this->name) !== ''
            ? (string) $this->name
            : (string) config('app.name', 'AIDataPlatform');
    }
}
