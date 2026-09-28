<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake(['*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200)]);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_the_dashboard_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_the_dataset_pages_render(): void
    {
        $admin = $this->admin();
        $dataset = Dataset::factory()->committed()->create();

        $this->actingAs($admin)->get(route('datasets.index'))->assertOk()->assertViewIs('datasets.index');
        $this->actingAs($admin)->get(route('datasets.create'))->assertOk()->assertViewIs('datasets.create');
        $this->actingAs($admin)->get(route('datasets.show', $dataset))->assertOk()->assertViewIs('datasets.show');
    }

    public function test_the_import_pages_render(): void
    {
        $admin = $this->admin();
        $dataset = Dataset::factory()->importing()->create();

        $this->actingAs($admin)->get(route('imports.index'))->assertOk()->assertViewIs('imports.index');
        $this->actingAs($admin)->get(route('imports.show', $dataset))->assertOk()->assertViewIs('imports.show');
    }

    public function test_the_quality_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('quality.index'))
            ->assertOk()
            ->assertViewIs('quality.index');
    }

    public function test_the_password_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('password.edit'))
            ->assertOk()
            ->assertViewIs('profile.password');
    }

    public function test_the_analytics_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertViewIs('analytics.index');
    }

    public function test_the_ml_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('ml.index'))
            ->assertOk()
            ->assertViewIs('ml.index');
    }

    public function test_the_assistant_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('assistant.index'))
            ->assertOk()
            ->assertViewIs('assistant.index');
    }

    public function test_the_reports_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index');
    }

    public function test_the_admin_user_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertViewIs('admin.users.index');
    }

    public function test_the_audit_log_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('audit.index'))
            ->assertOk()
            ->assertViewIs('audit.index');
    }

    public function test_the_login_page_renders_for_a_guest(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
    }
}
