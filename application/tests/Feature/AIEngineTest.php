<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\ModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider -> Model Router -> OpenCode Go adapter -> Muse Spark
 * (/responses) -> engine data endpoints: the wiring, with the engine side
 * faked at the transport.
 */
class AIEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_serves_muse_spark_through_the_responses_api(): void
    {
        $base = 'https://opencode.test/v1';

        Http::preventStrayRequests();
        Http::fake([
            $base.'/*' => Http::response([
                'id' => 'resp_9',
                'model' => 'muse-spark-1.3-contributor',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'analysis ready']]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
            ], 200),
        ]);

        AiProvider::query()->create([
            'name' => 'OpenCode Go',
            'provider_type' => 'opencode-go',
            'base_url' => $base,
            'model' => 'muse-spark-1.3-contributor',
            'capabilities' => ['streaming', 'tools'],
        ]);

        $router = new ModelRouter;
        $selected = $router->select(['tools']);

        $this->assertNotNull($selected);

        $result = $router->adapterFor($selected['provider'], 'sk-test')
            ->complete($selected['model'], 'Profile this dataset.');

        $this->assertSame('analysis ready', $result['text']);
        $this->assertSame(120, $result['usage']['total_tokens']);
        $this->assertSame('muse-spark-1.3-contributor', $result['model']);
        $this->assertSame('resp_9', $result['request_id']);
    }

    public function test_existing_chat_providers_keep_their_probe_path(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.example.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        $provider = AiProvider::query()->create([
            'name' => 'Legacy',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'gpt-x',
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.providers.test', $provider), ['api_key' => 'sk-test']);

        $response->assertRedirect();
        $this->assertSame('ok', $provider->fresh()->last_test_status);
    }
}
