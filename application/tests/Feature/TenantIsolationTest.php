<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * This deployment is single-organization by design (see the organizations
 * migration): providers form one global registry and API keys are never
 * persisted. Isolation therefore means: a key supplied for provider A can
 * never be stored, reused for provider B, or observed by another admin.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_for_provider_a_never_touches_provider_b(): void
    {
        Http::preventStrayRequests();
        Http::fake(['opencode.test/*' => Http::response(['data' => [['id' => 'm']]], 200)]);

        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();

        $providerA = AiProvider::query()->create([
            'name' => 'Provider A', 'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1', 'model' => 'm',
        ]);
        $providerB = AiProvider::query()->create([
            'name' => 'Provider B', 'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1', 'model' => 'm',
        ]);

        $secretA = 'sk-tenant-a-'.uniqid();

        $this->actingAs($adminA)->post(route('admin.providers.test', $providerA), ['api_key' => $secretA]);
        $this->actingAs($adminB)->postJson(route('admin.providers.discover'), [
            'provider_id' => $providerB->getKey(),
            'api_key' => 'sk-tenant-b',
        ]);

        $this->assertSame('ok', $providerA->fresh()->last_test_status);
        $this->assertNull($providerB->fresh()->last_tested_at);

        foreach (['ai_providers', 'ai_provider_models', 'audit_logs'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString($secretA, json_encode($row), "Tenant A secret leaked into [{$table}].");
            }
        }
    }

    public function test_registry_is_global_with_audited_ownership(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.providers.store'), [
            'name' => 'Shared',
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1',
            'model' => 'muse-spark-1.3-contributor',
        ])->assertRedirect();

        $provider = AiProvider::query()->where('name', 'Shared')->firstOrFail();

        $this->assertSame($admin->getKey(), $provider->created_by);
        $this->assertArrayNotHasKey('api_key', $provider->getAttributes());
    }
}
