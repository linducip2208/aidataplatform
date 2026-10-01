<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Services\ModelRouter;
use App\Services\OpenCodeGoAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LIVE integration test against the real OpenCode Go API.
 *
 * Runs only when OPENCODE_GO_API_KEY is present in the environment.
 * Otherwise every test reports SKIPPED — a live result is never faked.
 *
 *   OPENCODE_GO_API_KEY=sk-... OPENCODE_GO_MODEL=muse-spark-1.3-contributor \
 *     php artisan test --filter=OpenCodeGoLiveTest
 */
class OpenCodeGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private function liveKey(): ?string
    {
        $key = trim((string) config('ai_providers.live.api_key'));

        return $key !== '' ? $key : null;
    }

    private function adapter(): OpenCodeGoAdapter
    {
        return new OpenCodeGoAdapter(
            rtrim((string) config('ai_providers.live.base_url'), '/'),
            (string) $this->liveKey(),
            60,
            1,
        );
    }

    private function model(): string
    {
        return (string) config('ai_providers.live.model', 'muse-spark-1.3-contributor');
    }

    public function test_live_connection(): void
    {
        if ($this->liveKey() === null) {
            $this->markTestSkipped('OPENCODE_GO_API_KEY is not set; live result will not be faked.');
        }

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result['ok'], 'Live connection failed: '.$result['note']);
        $this->assertGreaterThanOrEqual(200, $result['status']);
    }

    public function test_live_model_discovery_lists_muse_spark(): void
    {
        if ($this->liveKey() === null) {
            $this->markTestSkipped('OPENCODE_GO_API_KEY is not set; live result will not be faked.');
        }

        $discovered = $this->adapter()->discoverModels();
        $ids = collect($discovered['models'])->pluck('external_id')->all();

        $this->assertContains(
            $this->model(),
            $ids,
            'Model ['.$this->model().'] was not advertised. Advertised: '.implode(', ', $ids),
        );
    }

    public function test_live_model_inference_uses_the_responses_api(): void
    {
        if ($this->liveKey() === null) {
            $this->markTestSkipped('OPENCODE_GO_API_KEY is not set; live result will not be faked.');
        }

        $result = $this->adapter()->testModel($this->model(), 'Reply with exactly: ok');

        $this->assertTrue($result['ok'], 'Live inference failed: '.$result['note']);
        $this->assertNotEmpty($result['text']);
    }

    public function test_live_router_resolves_a_saved_provider(): void
    {
        if ($this->liveKey() === null) {
            $this->markTestSkipped('OPENCODE_GO_API_KEY is not set; live result will not be faked.');
        }

        AiProvider::query()->create([
            'name' => 'OpenCode Go live',
            'provider_type' => 'opencode-go',
            'base_url' => rtrim((string) config('ai_providers.live.base_url'), '/'),
            'model' => $this->model(),
        ]);

        $selected = (new ModelRouter)->select();

        $this->assertNotNull($selected);
        $this->assertSame($this->model(), $selected['model']);
    }
}
