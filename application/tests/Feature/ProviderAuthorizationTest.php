<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The new admin endpoints inherit the existing gate: active admins only.
 */
class ProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): AiProvider
    {
        return AiProvider::query()->create([
            'name' => 'OpenCode Go',
            'provider_type' => 'opencode-go',
            'base_url' => 'https://opencode.test/v1',
            'model' => 'muse-spark-1.3-contributor',
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $provider = $this->provider();

        $this->postJson(route('admin.providers.probe'), [])->assertUnauthorized();
        $this->postJson(route('admin.providers.discover'), [])->assertUnauthorized();
        $this->postJson(route('admin.providers.test-model', $provider), [])->assertUnauthorized();
    }

    public function test_non_admins_are_forbidden(): void
    {
        $provider = $this->provider();

        foreach ([User::factory()->analyst()->create(), User::factory()->viewer()->create()] as $user) {
            $this->actingAs($user)->postJson(route('admin.providers.probe'), [])->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.providers.discover'), [])->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.providers.test-model', $provider), [])->assertForbidden();
        }
    }

    public function test_deactivated_admins_are_forbidden(): void
    {
        $user = User::factory()->admin()->inactive()->create();

        $this->actingAs($user)->postJson(route('admin.providers.probe'), [])->assertForbidden();
    }
}
