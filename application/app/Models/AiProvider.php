<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'opencode-go' => 'OpenCode Go',
    ];

    /**
     * Wire protocols spoken by each provider type. Anything not listed here
     * speaks OpenAI-style chat completions.
     */
    public const PROTOCOLS = [
        'opencode-go' => 'responses',
    ];

    protected $fillable = [
        'name',
        'provider_type',
        'protocol',
        'base_url',
        'model',
        'embedding_model',
        'capabilities',
        'input_price_per_million',
        'output_price_per_million',
        'priority',
        'timeout_seconds',
        'max_retries',
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
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    /** @return HasMany<AiProviderModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'ai_provider_id');
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
            'opencode-go' => 'OPENCODE_GO_API_KEY',
            default => 'LLM_API_KEY',
        };
    }

    /**
     * Wire protocol: explicit column wins, otherwise derived from the
     * provider type. OpenCode Go speaks Responses; everything else speaks
     * OpenAI-style chat completions.
     *
     * Named wireProtocol() — not protocol() — because Eloquent resolves a
     * zero-argument method call through the relationship loader, which
     * would collide with the `protocol` column.
     */
    public function wireProtocol(): string
    {
        $protocol = trim((string) $this->getAttribute('protocol'));

        if ($protocol !== '') {
            return $protocol;
        }

        return self::PROTOCOLS[$this->provider_type] ?? 'chat-completions';
    }

    public function usesResponsesApi(): bool
    {
        return $this->wireProtocol() === 'responses';
    }

    public function effectiveTimeout(): int
    {
        if (is_int($this->timeout_seconds) && $this->timeout_seconds > 0) {
            return min($this->timeout_seconds, 600);
        }

        return (int) config('ai_providers.defaults.timeout_seconds', 30);
    }

    public function effectiveRetries(): int
    {
        if (is_int($this->max_retries) && $this->max_retries >= 0) {
            return min($this->max_retries, 10);
        }

        return (int) config('ai_providers.defaults.max_retries', 2);
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
