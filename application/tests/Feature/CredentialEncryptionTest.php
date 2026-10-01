<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Secrets discipline: the API key is transient by architecture. It must
 * never land in the database, the audit trail, the session, or an error.
 */
class CredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_never_reaches_the_database(): void
    {
        Http::preventStrayRequests();
        Http::fake(['opencode.test/*' => Http::response(['data' => [['id' => 'muse-spark-1.3-contributor']]], 200)]);

        $admin = User::factory()->admin()->create();
        $secret = 'sk-live-super-secret-'.uniqid();

        $provider = AiProvider::query()->create([
            'name' => 'OpenCode Go',
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1',
            'model' => 'muse-spark-1.3-contributor',
        ]);

        $this->actingAs($admin)->post(route('admin.providers.test', $provider), ['api_key' => $secret]);
        $this->actingAs($admin)->postJson(route('admin.providers.discover'), [
            'provider_id' => $provider->getKey(),
            'api_key' => $secret,
        ]);

        foreach (['ai_providers', 'ai_provider_models', 'audit_logs'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString(
                    $secret,
                    json_encode($row),
                    "Secret leaked into [{$table}].",
                );
            }
        }
    }

    public function test_key_is_absent_from_store_payloads(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.providers.store'), [
            'name' => 'Sneaky',
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1',
            'model' => 'muse-spark-1.3-contributor',
            'api_key' => 'sk-should-never-persist',
        ])->assertRedirect();

        $attributes = AiProvider::query()->where('name', 'Sneaky')->firstOrFail()->getAttributes();

        $this->assertArrayNotHasKey('api_key', $attributes);
        $this->assertStringNotContainsString('sk-should-never-persist', json_encode($attributes));
    }

    public function test_error_notes_are_scrubbed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['opencode.test/*' => Http::response(['error' => 'invalid sk-crown-jewels rejected'], 401)]);

        $admin = User::factory()->admin()->create();
        $provider = AiProvider::query()->create([
            'name' => 'OpenCode Go',
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1',
            'model' => 'muse-spark-1.3-contributor',
        ]);

        $this->actingAs($admin)->post(route('admin.providers.test', $provider), ['api_key' => 'sk-crown-jewels']);

        $this->assertStringNotContainsString('sk-crown-jewels', (string) $provider->fresh()->last_test_note);

        $logged = AuditLog::query()->latest('id')->first();
        $this->assertStringNotContainsString('sk-crown-jewels', json_encode($logged));
    }
}
