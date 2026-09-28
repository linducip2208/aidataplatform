<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Runs the demo seeders in dependency order: the demo accounts must exist before the
     * datasets, chat threads and audit rows can be attributed to them. Every seeder is
     * idempotent, so `php artisan db:seed --force` can be run repeatedly without duplicating
     * the demo users, datasets, conversations or sample audit entries.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DatasetSeeder::class,
            ChatSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
