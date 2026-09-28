<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_try_from_name_falls_back_to_viewer_for_junk_values(): void
    {
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('superuser'));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName(''));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName(null));
    }

    public function test_try_from_name_resolves_every_known_role(): void
    {
        $this->assertSame(UserRole::Admin, UserRole::tryFromName('admin'));
        $this->assertSame(UserRole::Analyst, UserRole::tryFromName('analyst'));
        $this->assertSame(UserRole::Viewer, UserRole::tryFromName('viewer'));
    }

    public function test_values_returns_the_documented_role_list(): void
    {
        $this->assertSame(['admin', 'analyst', 'viewer'], UserRole::values());
    }

    public function test_from_matches_try_from_for_a_valid_role(): void
    {
        $this->assertSame(UserRole::Analyst, UserRole::from('analyst'));
    }

    public function test_every_case_exposes_a_non_empty_label(): void
    {
        foreach (UserRole::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->description());
        }

        $this->assertSame('Administrator', UserRole::Admin->label());
        $this->assertSame('Analyst', UserRole::Analyst->label());
        $this->assertSame('Viewer', UserRole::Viewer->label());
    }
}
