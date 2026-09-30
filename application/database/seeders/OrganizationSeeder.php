<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    /**
     * Idempotent single-company profile seeded from the app name.
     */
    public function run(): void
    {
        if (Organization::query()->exists()) {
            return;
        }

        Organization::query()->create([
            'name' => (string) config('app.name', 'AIDataPlatform'),
            'tagline' => null,
            'logo_path' => null,
        ]);

        Organization::forgetCurrent();
    }
}
