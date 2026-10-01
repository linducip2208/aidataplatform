<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Services\ModelRouter;
use App\Services\OpenCodeGoAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    use RefreshDatabase;

    private function makeProvider(string $name, string $type, int $priority, ?array $capabilities = null): AiProvider
    {
        return AiProvider::query()->create([
            'name' => $name,
            'provider_type' => $type,
            'base_url' => 'https://example.com/v1',
            'model' => $type === 'opencode-go' ? 'muse-spark-1.3-contributor' : 'other-model',
            'capabilities' => $capabilities,
            'priority' => $priority,
        ]);
    }

    public function test_select_prefers_lowest_priority(): void
    {
        $this->makeProvider('Zed', 'openai-compatible', 200);
        $winner = $this->makeProvider('OpenCode Go', 'opencode-go', 10, ['streaming', 'tools']);

        $selected = (new ModelRouter)->select();

        $this->assertNotNull($selected);
        $this->assertSame($winner->getKey(), $selected['provider']->getKey());
        $this->assertSame('muse-spark-1.3-contributor', $selected['model']);
    }

    public function test_select_filters_by_capabilities(): void
    {
        $this->makeProvider('Plain', 'openai-compatible', 5, ['chat']);
        $spark = $this->makeProvider('OpenCode Go', 'opencode-go', 50, ['streaming', 'tools']);

        $selected = (new ModelRouter)->select(['streaming', 'tools']);

        $this->assertNotNull($selected);
        $this->assertSame($spark->getKey(), $selected['provider']->getKey());
    }

    public function test_select_returns_null_when_nothing_matches(): void
    {
        $this->makeProvider('Plain', 'openai-compatible', 5, ['chat']);

        $this->assertNull((new ModelRouter)->select(['vision']));
    }

    public function test_default_model_falls_back_to_discovered_catalog(): void
    {
        $provider = $this->makeProvider('OpenCode Go', 'opencode-go', 10);
        $provider->forceFill(['model' => ''])->save();
        $provider->models()->create(['external_id' => 'muse-spark-1.3-contributor', 'is_active' => true]);

        $this->assertSame('muse-spark-1.3-contributor', (new ModelRouter)->defaultModel($provider->fresh()));
    }

    public function test_adapter_for_resolves_opencode_go(): void
    {
        $provider = $this->makeProvider('OpenCode Go', 'opencode-go', 10);

        $adapter = (new ModelRouter)->adapterFor($provider, 'sk-test');

        $this->assertInstanceOf(OpenCodeGoAdapter::class, $adapter);
    }

    public function test_adapter_for_refuses_chat_completions_types(): void
    {
        $provider = $this->makeProvider('Legacy', 'openai-compatible', 10);

        $this->expectException(\LogicException::class);

        (new ModelRouter)->adapterFor($provider, 'sk-test');
    }

    public function test_inactive_providers_are_ignored(): void
    {
        $this->makeProvider('Off', 'opencode-go', 1)->forceFill(['is_active' => false])->save();

        $this->assertNull((new ModelRouter)->select());
    }
}
