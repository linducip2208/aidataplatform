<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Writes FIVE clearly marked sample rows into `audit_logs` so the administrator audit page is not
 * blank on a fresh install. These are demonstration records only: every entry has
 * `detail.sample = true`, an actor from the demo accounts documented in the root README
 * (`admin@example.com` / `Admin123!`, `analyst@example.com` / `Analyst123!`), and a `created_at`
 * in the past. Real audit rows written by the running application never carry the `sample` flag,
 * so they can be told apart.
 *
 * The seeder is idempotent: entries are keyed on their `action` + `resource` + `resource_id`
 * triple, so repeated `db:seed --force` runs update the same five rows instead of appending.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->demoUser('admin@example.com');
        $analyst = $this->demoUser('analyst@example.com');

        $entries = [
            [
                'action' => 'auth.login',
                'resource' => 'user',
                'resource_id' => $analyst->getKey(),
                'ip' => '172.19.0.14',
                'detail' => ['sample' => true, 'role' => 'analyst', 'user_agent' => 'Chrome/141'],
                'days_ago' => 6,
            ],
            [
                'action' => 'dataset.upload',
                'resource' => 'dataset',
                'resource_id' => null,
                'ip' => '172.19.0.14',
                'detail' => ['sample' => true, 'filename' => 'penjualan_retail_harian_2026.csv', 'size_bytes' => 1436760, 'dataset_type' => 'sales'],
                'days_ago' => 5,
            ],
            [
                'action' => 'dataset.committed',
                'resource' => 'dataset',
                'resource_id' => null,
                'ip' => '172.19.0.14',
                'detail' => ['sample' => true, 'filename' => 'penjualan_retail_harian_2026.csv', 'row_count' => 18420, 'quality_score' => 0.9412, 'verdict' => 'pass'],
                'days_ago' => 5,
            ],
            [
                'action' => 'dataset.quarantined',
                'resource' => 'dataset',
                'resource_id' => null,
                'ip' => '172.19.0.14',
                'detail' => ['sample' => true, 'filename' => 'biaya_operasional_toko.csv', 'quality_score' => 0.4216, 'threshold' => 0.75, 'issue_count' => 4],
                'days_ago' => 2,
            ],
            [
                'action' => 'user.role_changed',
                'resource' => 'user',
                'resource_id' => $analyst->getKey(),
                'ip' => '127.0.0.1',
                'detail' => ['sample' => true, 'from' => 'viewer', 'to' => 'analyst', 'by' => $admin->email],
                'days_ago' => 1,
            ],
        ];

        foreach ($entries as $index => $entry) {
            $createdAt = Carbon::now()->subDays($entry['days_ago'])->subMinutes(20 - $index * 3);

            $log = AuditLog::updateOrCreate(
                [
                    'action' => $entry['action'],
                    'resource' => $entry['resource'],
                    'resource_id' => $entry['resource_id'],
                ],
                [
                    'user_id' => $entry['action'] === 'user.role_changed' ? $admin->getKey() : $analyst->getKey(),
                    'actor' => $entry['action'] === 'user.role_changed' ? $admin->email : $analyst->email,
                    'ip' => $entry['ip'],
                    'detail' => $entry['detail'],
                ],
            );

            $log->forceFill(['created_at' => $createdAt])->save();
        }
    }

    /**
     * Resolve a demo user, seeding the demo accounts first when they are missing.
     */
    protected function demoUser(string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user instanceof User) {
            $this->call(UserSeeder::class);
            $user = User::where('email', $email)->firstOrFail();
        }

        return $user;
    }
}
