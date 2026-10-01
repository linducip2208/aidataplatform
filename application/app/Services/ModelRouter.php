<?php

namespace App\Services;

use App\Models\AiProvider;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Selects which provider (and which discovered model) serves a task.
 *
 * Selection is priority-ordered and capability-filtered; nothing is
 * hardcoded to a vendor. Providers whose credentials live in the request
 * (BYOK) resolve to an adapter only when the caller supplies the key, so a
 * stored row can never leak another tenant's secret: there are no stored
 * secrets at all.
 */
class ModelRouter
{
    /**
     * Active providers, lowest priority number first.
     *
     * @param  list<string>|null  $capabilities  every capability the task needs
     * @return Collection<int, AiProvider>
     */
    public function providersFor(?array $capabilities = null): Collection
    {
        return AiProvider::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->when(
                $capabilities !== null && $capabilities !== [],
                static fn (Collection $providers): Collection => $providers->filter(
                    static fn (AiProvider $provider): bool => array_diff($capabilities, $provider->capabilities ?? []) === [],
                )->values(),
            );
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return array{provider: AiProvider, model: string|null}|null
     */
    public function select(?array $capabilities = null): ?array
    {
        $provider = $this->providersFor($capabilities)->first();

        if (! $provider instanceof AiProvider) {
            return null;
        }

        return ['provider' => $provider, 'model' => $this->defaultModel($provider)];
    }

    /**
     * The pinned `model` column wins; otherwise the first active discovered
     * model; otherwise null (caller must ask the operator to pick one).
     */
    public function defaultModel(AiProvider $provider): ?string
    {
        if (trim((string) $provider->model) !== '') {
            return trim((string) $provider->model);
        }

        return $provider->models()
            ->where('is_active', true)
            ->orderBy('external_id')
            ->first()
            ?->external_id;
    }

    /**
     * Resolve a live adapter for a Responses-protocol provider. Providers on
     * the chat-completions protocol keep using the existing probe path in
     * AiProviderService, so this router never changes their behavior.
     */
    public function adapterFor(AiProvider $provider, string $apiKey): OpenCodeGoAdapter
    {
        if (! $provider->usesResponsesApi()) {
            throw new LogicException("Provider [{$provider->name}] speaks {$provider->wireProtocol()}, not the Responses API.");
        }

        return OpenCodeGoAdapter::forProvider($provider, $apiKey);
    }
}
