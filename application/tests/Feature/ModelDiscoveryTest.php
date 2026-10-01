<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /models discovery: normalize, detect capabilities (Muse Spark needs
 * no allowlist), preview without saving, persist on saved providers.
 */
class ModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'https://opencode.test/v1';

    private function modelsPayload(): array
    {
        return ['data' => [
            ['id' => 'muse-spark-1.3-contributor', 'name' => 'Muse Spark', 'supports_streaming' => true, 'supports_tools' => true, 'context_length' => 200000],
            ['id' => 'other-model', 'capabilities' => ['chat']],
            'plain-string-model',
            ['name' => '  '],
        ]];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_muse_spark_is_detected_without_an_allowlist(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response($this->modelsPayload(), 200)]);

        $response = $this->actingAs($this->admin())->postJson(route('admin.providers.discover'), [
            'provider_type' => 'opencode-go',
            'base_url' => $this->base,
            'api_key' => 'sk-test',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $ids = collect($response->json('models'))->pluck('external_id')->all();

        $this->assertContains('muse-spark-1.3-contributor', $ids);

        $spark = collect($response->json('models'))->firstWhere('external_id', 'muse-spark-1.3-contributor');
        $this->assertContains('streaming', $spark['capabilities']);
        $this->assertContains('tools', $spark['capabilities']);
        $this->assertSame(200000, $spark['metadata']['context_length']);
        $this->assertArrayNotHasKey('api_key', $spark['metadata']);
    }

    public function test_preview_saves_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response($this->modelsPayload(), 200)]);

        $this->actingAs($this->admin())->postJson(route('admin.providers.discover'), [
            'provider_type' => 'opencode-go',
            'base_url' => $this->base,
            'api_key' => 'sk-test',
        ])->assertOk()->assertJsonPath('persisted', 0);

        $this->assertDatabaseCount('ai_provider_models', 0);
    }

    public function test_discovery_persists_models_for_a_saved_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response($this->modelsPayload(), 200)]);

        $providerId = $this->actingAs($this->admin())->post(route('admin.providers.store'), [
            'name' => 'OpenCode Go utama',
            'provider_type' => 'opencode-go',
            'base_url' => $this->base,
            'model' => 'muse-spark-1.3-contributor',
        ])->assertRedirect();

        $provider = AiProvider::query()->where('name', 'OpenCode Go utama')->firstOrFail();

        $response = $this->actingAs($this->admin())->postJson(route('admin.providers.discover'), [
            'provider_id' => $provider->getKey(),
            'api_key' => 'sk-test',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ai_provider_models', ['ai_provider_id' => $provider->getKey(), 'external_id' => 'muse-spark-1.3-contributor']);
        $this->assertDatabaseHas('ai_provider_models', ['ai_provider_id' => $provider->getKey(), 'external_id' => 'plain-string-model']);

        // Idempotent re-run: no duplicates.
        $this->actingAs($this->admin())->postJson(route('admin.providers.discover'), [
            'provider_id' => $provider->getKey(),
            'api_key' => 'sk-test',
        ])->assertOk();

        $this->assertSame(3, $provider->models()->count());
    }

    public function test_discovery_rejects_non_responses_types(): void
    {
        $response = $this->actingAs($this->admin())->postJson(route('admin.providers.discover'), [
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
        ]);

        $response->assertStatus(422);
    }
}
