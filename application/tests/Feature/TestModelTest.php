<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real inference test via POST /responses against Muse Spark (transport
 * faked): text excerpt, usage passthrough, column updates, no key leaks.
 */
class TestModelTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'https://opencode.test/v1';

    private function provider(): AiProvider
    {
        return AiProvider::query()->create([
            'name' => 'OpenCode Go utama',
            'provider_type' => 'opencode-go',
            'base_url' => $this->base,
            'model' => 'muse-spark-1.3-contributor',
        ]);
    }

    private function responsesFake(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response([
            'id' => 'resp_1',
            'model' => 'muse-spark-1.3-contributor',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
            'usage' => ['input_tokens' => 8, 'output_tokens' => 2, 'total_tokens' => 10],
        ], 200)]);
    }

    public function test_model_inference_returns_text_and_usage(): void
    {
        $this->responsesFake();
        $provider = $this->provider();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.providers.test-model', $provider), [
                'api_key' => 'sk-test',
                'model' => 'muse-spark-1.3-contributor',
            ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('ok', $response->json('text'));
        $this->assertSame(8, $response->json('usage.input_tokens'));

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/responses')
                && ($request->data()['model'] ?? null) === 'muse-spark-1.3-contributor';
        });

        $this->assertSame('ok', $provider->fresh()->last_test_status);
        $this->assertNotNull($provider->fresh()->last_tested_at);
    }

    public function test_rejected_key_reports_401(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response(['error' => 'nope'], 401)]);
        $provider = $this->provider();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.providers.test-model', $provider), ['api_key' => 'sk-bad']);

        $response->assertOk()->assertJsonPath('ok', false);
        $this->assertSame('failed', $provider->fresh()->last_test_status);
    }

    public function test_empty_model_reports_failure(): void
    {
        $provider = $this->provider();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.providers.test-model', $provider), ['api_key' => 'sk-test', 'model' => '  ']);

        $response->assertOk()->assertJsonPath('ok', false);
    }

    public function test_chat_completions_providers_cannot_use_test_model(): void
    {
        $provider = AiProvider::query()->create([
            'name' => 'Legacy',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'gpt-x',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.providers.test-model', $provider), ['api_key' => 'sk-test'])
            ->assertStatus(422);
    }
}
