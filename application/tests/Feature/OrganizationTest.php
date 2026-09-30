<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Single-company white-label profile (not multi-tenancy: all data stays
 * global; this only drives the shell brand).
 */
class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exactly_one_profile(): void
    {
        $this->seed(OrganizationSeeder::class);
        $this->seed(OrganizationSeeder::class);

        $this->assertDatabaseCount('organizations', 1);
        $this->assertSame(config('app.name'), Organization::current()?->displayName());
    }

    public function test_shell_falls_back_to_config_without_a_row(): void
    {
        $this->assertNull(Organization::current());

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(config('app.name'), $html);
    }

    public function test_shell_uses_organization_brand(): void
    {
        Organization::query()->create(['name' => 'PT Contoh', 'tagline' => 'Data kami']);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PT Contoh', $html);
        $this->assertStringContainsString('Data kami', $html);
    }

    public function test_admin_can_update_profile_with_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Organization::query()->create(['name' => 'Lama']);

        $logo = UploadedFile::fake()->image('logo.png', 64, 64);

        $this->actingAs($admin)
            ->from(route('admin.organization.edit'))
            ->put(route('admin.organization.update'), [
                'name' => 'PT Baru',
                'tagline' => 'Baru',
                'logo' => $logo,
            ])
            ->assertRedirect();

        $organization = Organization::current();
        $this->assertSame('PT Baru', $organization->name);
        $this->assertNotNull($organization->logo_path);
        Storage::disk('public')->assertExists($organization->logo_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.updated']);
    }

    public function test_logo_validation_rejects_non_images(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.organization.edit'))
            ->put(route('admin.organization.update'), [
                'name' => 'PT Baru',
                'logo' => UploadedFile::fake()->create('evil.php', 100, 'text/php'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('logo');
    }

    public function test_non_admin_cannot_manage_organization(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->get(route('admin.organization.edit'))
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->put(route('admin.organization.update'), ['name' => 'x'])
            ->assertForbidden();
    }
}
