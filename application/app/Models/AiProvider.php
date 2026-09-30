<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One BYOK AI provider registration (metadata only, never the API key).
 */
class AiProvider extends Model
{
    use HasFactory;

    public const TYPES = [
        'openai-compatible' => 'OpenAI-compatible',
        'openrouter' => 'OpenRouter',
        'ollama' => 'Ollama (local)',
        'custom' => 'Custom OpenAI-compatible endpoint',
    ];

    protected $fillable = [
        'name',
        'provider_type',
        'base_url',
        'model',
        'embedding_model',
        'capabilities',
        'input_price_per_million',
        'output_price_per_million',
        'priority',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_note',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->provider_type] ?? $this->provider_type;
    }

    /**
     * The engine key slot this provider type authenticates with. The key
     * itself is never stored; this only names the env var it is written to.
     */
    public function keySlot(): string
    {
        return match ($this->provider_type) {
            'openrouter' => 'OPENROUTER_API_KEY',
            default => 'LLM_API_KEY',
        };
    }

    /**
     * @return array<string, string> env name => value (values never logged)
     */
    public function managedEnv(string $apiKey): array
    {
        $env = [
            'LLM_PROVIDER' => $this->provider_type,
            'LLM_BASE_URL' => $this->base_url,
            'LLM_MODEL' => $this->model,
            $this->keySlot() => $apiKey,
        ];

        if (is_string($this->embedding_model) && trim($this->embedding_model) !== '') {
            $env['LLM_EMBEDDING_MODEL'] = trim($this->embedding_model);
        }

        return $env;
    }
}
