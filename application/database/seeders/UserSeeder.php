<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the three demo accounts documented in the root README and `docs/administrator.md`.
 *
 * Credentials seeded (passwords are only applied when the account is created for the first time):
 *   - admin@example.com   / AdministratorPlatform  / Admin123!   (role: admin)
 *   - analyst@example.com / Analis Data            / Analyst123! (role: analyst)
 *   - viewer@example.com  / Pengunjung             / Viewer123!  (role: viewer)
 *
 * The seeder is idempotent: it is keyed on the e-mail address and only fills columns that are
 * still null, so an existing account keeps its current password and any deactivated state.
 */
class UserSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    protected const ACCOUNTS = [
        [
            'name' => 'AdministratorPlatform',
            'email' => 'admin@example.com',
            'password' => 'Admin123!',
            'role' => UserRole::Admin,
        ],
        [
            'name' => 'Analis Data',
            'email' => 'analyst@example.com',
            'password' => 'Analyst123!',
            'role' => UserRole::Analyst,
        ],
        [
            'name' => 'Pengunjung',
            'email' => 'viewer@example.com',
            'password' => 'Viewer123!',
            'role' => UserRole::Viewer,
        ],
    ];

    public function run(): void
    {
        foreach (static::ACCOUNTS as $account) {
            static::seedAccount($account);
        }
    }

    /**
     * @param  array{name: string, email: string, password: string, role: UserRole}  $account
     */
    protected static function seedAccount(array $account): User
    {
        $user = User::firstOrNew(['email' => $account['email']]);

        $defaults = [
            'name' => $account['name'],
            'role' => $account['role']->value,
            'is_active' => true,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ];

        foreach ($defaults as $key => $value) {
            if ($user->getAttribute($key) === null) {
                $user->setAttribute($key, $value);
            }
        }

        if (! $user->exists) {
            $user->setAttribute('password', Hash::make($account['password']));
        }

        $user->save();

        return $user;
    }
}
