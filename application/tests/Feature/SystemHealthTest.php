<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin System Health dashboard: menu visibility, page render, and graceful
 * degradation when the engine is unreachable.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_menu_contains_system_health_with_working_link(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('System Health', $html);
        $this->assertStringContainsString(route('admin.system.health', [], false), $html);
    }

    public function test_non_admin_does_not_see_system_health_menu(): void
    {
        $html = $this->actingAs(User::factory()->analyst()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('System Health', $html);
        $this->assertStringNotContainsString(route('admin.system.health', [], false), $html);
    }

    public function test_admin_page_renders_report_with_active_highlight(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok', 'app' => 'aidata-engine', 'env' => 'test', 'version' => '1.0.0'], 200),
        ]);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.system.health'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('System Health', $html);
        // Active highlight on the current menu entry.
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString(route('admin.system.health', [], false), $html);
    }

    public function test_page_degrades_gracefully_when_engine_is_down(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('connection refused');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.system.health'))
            ->assertOk();
    }

    public function test_non_admin_is_forbidden_from_page(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->get(route('admin.system.health'))
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('admin.system.health'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.system.health'))->assertRedirect(route('login'));
    }
}
