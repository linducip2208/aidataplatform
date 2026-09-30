<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BYOK provider registry: metadata in DB, keys never stored.
 */
class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'name' => 'OpenRouter utama',
            'provider_type' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'anthropic/claude-3.5-sonnet',
            'priority' => 10,
        ];
    }

    public function test_admin_crud_round_trip(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.providers.store'), $this->attributes())
            ->assertRedirect(route('admin.providers.index'));

        $provider = AiProvider::query()->where('name', 'OpenRouter utama')->firstOrFail();
        $this->assertSame('openrouter', $provider->provider_type);

        $this->actingAs($admin)
            ->get(route('admin.providers.index'))
            ->assertOk()
            ->assertSee('OpenRouter utama')
            ->assertSee('Provider AI');

        // No key column exists: nothing secret could have been persisted.
        $this->assertArrayNotHasKey('api_key', $provider->getAttributes());

        $this->actingAs($admin)
            ->put(route('admin.providers.update', $provider), [...$this->attributes(), 'priority' => 5])
            ->assertRedirect();
        $this->assertSame(5, $provider->fresh()->priority);

        $this->actingAs($admin)
            ->delete(route('admin.providers.destroy', $provider))
            ->assertRedirect();
        $this->assertDatabaseMissing('ai_providers', ['id' => $provider->getKey()]);
    }

    public function test_non_admin_cannot_manage_providers(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->get(route('admin.providers.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('admin.providers.store'), $this->attributes())
            ->assertForbidden();
    }

    public function test_validation_rejects_bad_urls_and_types(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.providers.store'), [
                ...$this->attributes(), 'base_url' => 'ftp://x', 'provider_type' => 'nope',
            ])
            ->assertSessionHasErrors(['base_url', 'provider_type']);

        $this->assertDatabaseCount('ai_providers', 0);
    }

    public function test_connection_probe_never_stores_the_key(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
        ]);

        $provider = AiProvider::query()->create([...$this->attributes(), 'created_by' => null]);
        $service = app(AiProviderService::class);

        $result = $service->testConnection($provider->toArray(), 'sk-test-123');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('api_key', $provider->fresh()->getAttributes());
        Http::assertSent(function (ClientRequest $request): bool {
            $payload = $request->data();

            return ($payload['model'] ?? null) === 'anthropic/claude-3.5-sonnet';
        });
    }

    public function test_rejected_key_reports_without_leaking(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'bad key sk-test-123']], 401),
        ]);

        $service = app(AiProviderService::class);
        $result = $service->testConnection($this->attributes(), 'sk-test-123');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', $result['note']);
    }

    public function test_publish_writes_managed_block_with_backup(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aienv').'.env';
        file_put_contents($path, "SERVICE_API_KEY=abc\nLLM_MODEL=old\n");

        $provider = AiProvider::query()->create($this->attributes());
        $service = new AiProviderService($path);

        $result = $service->publish($provider, 'sk-live-xyz');

        // Native path in tests (no /.dockerenv): written with backup.
        $this->assertTrue($result['written']);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('LLM_PROVIDER=openrouter', $content);
        $this->assertStringContainsString('OPENROUTER_API_KEY=sk-live-xyz', $content);
        $this->assertStringContainsString('SERVICE_API_KEY=abc', $content);
        $this->assertSame(1, substr_count($content, AiProviderService::MANAGED_BEGIN));

        // Re-publish replaces, never duplicates.
        $service->publish($provider, 'sk-live-xyz');
        $this->assertSame(1, substr_count((string) file_get_contents($path), AiProviderService::MANAGED_BEGIN));

        $backups = glob($path.'.bak.*') ?: [];
        $this->assertNotEmpty($backups);

        foreach ([...$backups, $path] as $file) {
            unlink($file);
        }
    }

    public function test_publish_requires_key_except_ollama(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = AiProvider::query()->create($this->attributes());

        $this->actingAs($admin)
            ->post(route('admin.providers.publish', $provider), [])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_key_slot_mapping(): void
    {
        $openrouter = new AiProvider(['provider_type' => 'openrouter']);
        $ollama = new AiProvider(['provider_type' => 'ollama']);

        $this->assertSame('OPENROUTER_API_KEY', $openrouter->keySlot());
        $this->assertSame('LLM_API_KEY', $ollama->keySlot());
    }
}
