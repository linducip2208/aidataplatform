<?php

namespace Tests\Unit;

use App\Models\AiProvider;
use Tests\TestCase;

/**
 * OpenCode Go registration contract: type key, key slot, protocol
 * derivation, timeout/retry knobs, and the engine env mapping.
 */
class OpenCodeGoProviderTest extends TestCase
{
    public function test_opencode_go_is_a_registered_type(): void
    {
        $this->assertSame('OpenCode Go', AiProvider::TYPES['opencode-go']);
    }

    public function test_key_slot_is_dedicated(): void
    {
        $provider = new AiProvider(['provider_type' => 'opencode-go']);

        $this->assertSame('OPENCODE_GO_API_KEY', $provider->keySlot());
        $this->assertSame('OPENROUTER_API_KEY', (new AiProvider(['provider_type' => 'openrouter']))->keySlot());
        $this->assertSame('LLM_API_KEY', (new AiProvider(['provider_type' => 'openai-compatible']))->keySlot());
    }

    public function test_protocol_defaults_to_responses_for_opencode_go(): void
    {
        $provider = new AiProvider(['provider_type' => 'opencode-go']);

        $this->assertSame('responses', $provider->wireProtocol());
        $this->assertTrue($provider->usesResponsesApi());
        $this->assertFalse((new AiProvider(['provider_type' => 'openai-compatible']))->usesResponsesApi());
    }

    public function test_explicit_protocol_column_wins(): void
    {
        $provider = new AiProvider(['provider_type' => 'opencode-go', 'protocol' => 'chat-completions']);

        $this->assertSame('chat-completions', $provider->wireProtocol());
        $this->assertFalse($provider->usesResponsesApi());
    }

    public function test_timeout_and_retries_fall_back_to_config(): void
    {
        $provider = new AiProvider(['provider_type' => 'opencode-go']);

        $this->assertSame((int) config('ai_providers.defaults.timeout_seconds'), $provider->effectiveTimeout());
        $this->assertSame((int) config('ai_providers.defaults.max_retries'), $provider->effectiveRetries());
    }

    public function test_timeout_and_retries_are_clamped(): void
    {
        $provider = new AiProvider(['provider_type' => 'opencode-go', 'timeout_seconds' => 9999, 'max_retries' => 99]);

        $this->assertSame(600, $provider->effectiveTimeout());
        $this->assertSame(10, $provider->effectiveRetries());
    }

    public function test_managed_env_maps_the_opencode_go_slots(): void
    {
        $provider = new AiProvider([
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.ai/zen/go/v1',
            'model' => 'muse-spark-1.3-contributor',
        ]);

        $env = $provider->managedEnv('sk-live');

        $this->assertSame('opencode-go', $env['LLM_PROVIDER']);
        $this->assertSame('https://opencode.ai/zen/go/v1', $env['LLM_BASE_URL']);
        $this->assertSame('muse-spark-1.3-contributor', $env['LLM_MODEL']);
        $this->assertSame('sk-live', $env['OPENCODE_GO_API_KEY']);
        $this->assertArrayNotHasKey('LLM_API_KEY', $env);
    }
}
