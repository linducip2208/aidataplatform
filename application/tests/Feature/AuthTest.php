<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'Secret123!';

    protected function makeUser(array $attributes = []): User
    {
        return User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.co.id',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'analyst',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_the_login_screen_renders_for_a_guest(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
    }

    public function test_valid_credentials_authenticate_and_redirect_to_the_dashboard(): void
    {
        $user = $this->makeUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_writes_the_last_login_at_column(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->last_login_at);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_successful_login_is_audited(): void
    {
        $user = $this->makeUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_a_wrong_password_fails_validation_on_the_email_field(): void
    {
        $user = $this->makeUser();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'WrongPassword123!',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_is_rejected(): void
    {
        $user = $this->makeUser(['is_active' => false]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_api_login_returns_a_token_and_the_user_role(): void
    {
        $user = $this->makeUser(['role' => 'viewer']);

        $this->postJson(route('api.login'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'phpunit',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'viewer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
    }

    public function test_the_issued_token_authenticates_the_me_endpoint(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $token = $this->apiTokenFor($user);

        $this->withToken($token)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_the_api_logout_revokes_the_token(): void
    {
        $user = $this->makeUser();
        $token = $this->apiTokenFor($user);

        $this->withToken($token)->postJson(route('api.logout'))->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The testing harness reuses one AuthManager across requests, so the
        // resolved user is memoised and a second call would pass even with the
        // token gone. Dropping the guards is what a real request does.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(route('api.me'))->assertUnauthorized();
    }

    public function test_api_login_with_invalid_credentials_returns_422(): void
    {
        $this->makeUser();

        $this->postJson(route('api.login'), [
            'email' => 'budi@example.co.id',
            'password' => 'WrongPassword123!',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    protected function apiTokenFor(User $user): string
    {
        return $this->postJson(route('api.login'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'phpunit',
        ])->json('data.token');
    }
}
