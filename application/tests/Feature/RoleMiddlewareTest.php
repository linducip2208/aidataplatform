<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role, bool $isActive = true): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.($isActive ? 'a' : 'i').'-'.Str::random(8).'@example.co.id',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'is_active' => $isActive,
        ]);
    }

    public function test_an_analyst_is_forbidden_on_the_admin_user_list(): void
    {
        $this->actingAs($this->user('analyst'))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_an_analyst_is_forbidden_on_the_audit_log(): void
    {
        $this->actingAs($this->user('analyst'))
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_a_viewer_is_forbidden_from_creating_a_dataset(): void
    {
        $this->actingAs($this->user('viewer'))
            ->post(route('datasets.store'), [])
            ->assertForbidden();
    }

    public function test_a_viewer_is_forbidden_from_training_a_model(): void
    {
        $this->actingAs($this->user('viewer'))
            ->post(route('ml.train'), ['model_type' => 'forecast'])
            ->assertForbidden();
    }

    public function test_an_admin_reaches_the_admin_only_pages(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('audit.index'))->assertOk();
    }

    public function test_an_admin_passes_the_write_role_gate(): void
    {
        // Validation runs before the engine call, so a missing payload proves the
        // role gate let the request through instead of rejecting it with a 403.
        $this->actingAs($this->user('admin'))
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [])
            ->assertRedirect(route('datasets.create'))
            ->assertSessionHasErrors('file');
    }

    public function test_an_admin_only_api_route_returns_a_403_json_body_for_an_analyst(): void
    {
        $this->actingAs($this->user('analyst'))
            ->postJson(route('api.ml.models.promote', ['modelId' => 1]), ['version_id' => 2])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_a_deactivated_admin_is_forbidden(): void
    {
        $this->actingAs($this->user('admin', isActive: false))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_a_deactivated_analyst_is_forbidden_on_a_write_route(): void
    {
        $this->actingAs($this->user('analyst', isActive: false))
            ->post(route('datasets.store'), [])
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }
}
