<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI locale: session choice persisted per user, Indonesian default.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_renders_indonesian_shell(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kumpulan data', $html);
        $this->assertStringContainsString('lang="id"', $html);
    }

    public function test_locale_switch_renders_english_shell(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Datasets', $html);
        $this->assertStringNotContainsString('Kumpulan data', $html);
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('dashboard'))
            ->post(route('locale.update'), ['locale' => 'fr'])
            ->assertRedirect()
            ->assertSessionHasErrors('locale');
    }

    public function test_guest_locale_uses_session_only(): void
    {
        $this->withSession([SetLocale::SESSION_KEY => 'en'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to the platform', false);
    }

    public function test_login_page_defaults_to_indonesian(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Masuk ke platform', false);
    }

    public function test_english_pages_show_no_raw_translation_keys(): void
    {
        $user = User::factory()->admin()->create();
        $user->forceFill(['locale' => 'en'])->save();

        foreach (['dashboard', 'datasets.index', 'datasets.create', 'imports.index', 'quality.index', 'analytics.index', 'ml.index', 'assistant.index', 'reports.index'] as $route) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

            foreach (['dashboard.', 'datasets.', 'imports.', 'quality.', 'analytics.', 'ml.', 'assistant.', 'reports.', 'nav.', 'auth.', 'common.'] as $prefix) {
                // A missing translation renders as the key itself.
                $this->assertDoesNotMatchRegularExpression(
                    '/[\'"\s>]'.preg_quote($prefix, '/').'[a-z0-9_.]+/i',
                    $html,
                    "Raw {$prefix}* key leaked on {$route}."
                );
            }
        }
    }
}
