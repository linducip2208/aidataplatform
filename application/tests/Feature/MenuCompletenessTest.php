<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar menu completeness: every entry renders for the roles allowed to
 * see it, with a working route behind each link.
 */
class MenuCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> route name => label */
    private function primaryMenu(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'datasets.index' => 'Kumpulan data',
            'imports.index' => 'Impor',
            'quality.index' => 'Kualitas',
            'alerts.index' => 'Peringatan',
            'analytics.index' => 'Analitik',
            'ml.index' => 'Pembelajaran mesin',
            'assistant.index' => 'Asisten',
            'knowledge.index' => 'Basis pengetahuan',
            'glossary.index' => 'Glosarium',
            'reports.index' => 'Laporan',
            'decisions.index' => 'Keputusan',
            'ai.usage' => 'Biaya AI',
        ];
    }

    public function test_admin_sees_the_full_menu_with_working_links(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        foreach ([...$this->primaryMenu(), 'admin.users.index' => 'Pengguna', 'audit.index' => 'Log audit'] as $route => $label) {
            $this->assertStringContainsString($label, $html, "menu label [{$label}] missing");
            $this->assertStringContainsString(route($route, [], false), $html, "menu link [{$route}] missing");
        }

        $this->assertStringContainsString('id="sidebar-menu"', $html);
    }

    public function test_analyst_does_not_see_admin_menu(): void
    {
        $html = $this->actingAs(User::factory()->analyst()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        foreach ($this->primaryMenu() as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertStringNotContainsString('Pengguna', $html);
        $this->assertStringNotContainsString('Log audit', $html);
    }

    public function test_viewer_sees_the_menu(): void
    {
        $html = $this->actingAs(User::factory()->viewer()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kumpulan data', $html);
        $this->assertStringContainsString('Keputusan', $html);
    }
}
