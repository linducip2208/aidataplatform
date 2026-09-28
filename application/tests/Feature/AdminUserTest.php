<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin-only user console: `routes/web.php` lines 68-71.
 *
 * Every route here sits inside `Route::middleware('role:admin')`, so the
 * authorization assertions are part of the contract, not an extra.
 */
class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Administrator Platform',
            'email' => 'admin@example.com',
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminUserRoutes(): array
    {
        return [
            'index' => ['admin.users.index', 'GET'],
            'store' => ['admin.users.store', 'POST'],
            'update' => ['admin.users.update', 'PATCH'],
            'destroy' => ['admin.users.destroy', 'DELETE'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminUserWriteRoutes(): array
    {
        return [
            'store' => ['admin.users.store', 'POST'],
            'update' => ['admin.users.update', 'PATCH'],
            'destroy' => ['admin.users.destroy', 'DELETE'],
        ];
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_the_index_paginates_the_user_list(): void
    {
        User::factory()->count(25)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertViewIs('admin.users.index');

        $users = $response->viewData('users');

        $this->assertInstanceOf(LengthAwarePaginator::class, $users);
        // 25 factory users plus the signed-in admin.
        $this->assertSame(26, $users->total());
        $this->assertCount(20, $users->items());
        $this->assertSame(2, $users->lastPage());
    }

    public function test_the_index_honours_the_q_search_term(): void
    {
        $match = User::factory()->analyst()->create(['name' => 'Sinta Wibowo']);
        User::factory()->create(['name' => 'Bagus Prasetyo']);

        $users = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['q' => 'Sinta']))
            ->assertOk()
            ->viewData('users');

        $this->assertSame(1, $users->total());
        $this->assertSame($match->getKey(), $users->items()[0]->getKey());
    }

    /**
     * Regression pin. The search once compiled to a bare Postgres `ilike`,
     * which SQLite does not have, so any non-empty `?q=` blew up with
     * "no such function: ilike" against the sqlite test database.
     * `whereLike(..., caseSensitive: false)` compiles to `ILIKE` on pgsql and
     * `LIKE` everywhere else; this asserts the case-insensitive match still
     * works for the lowercase term a browser actually sends.
     */
    public function test_the_index_matches_the_email_case_insensitively(): void
    {
        $match = User::factory()->analyst()->create([
            'name' => 'Dewi Lestari',
            'email' => 'Dewi.Lestari@Example.co.id',
        ]);
        User::factory()->count(3)->create();

        $users = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['q' => 'dewi.lestari']))
            ->assertOk()
            ->viewData('users');

        $this->assertSame(1, $users->total());
        $this->assertSame($match->getKey(), $users->items()[0]->getKey());
    }

    public function test_the_index_honours_the_role_filter(): void
    {
        $viewers = User::factory()->count(2)->viewer()->create();
        User::factory()->count(3)->analyst()->create();

        $users = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'viewer']))
            ->assertOk()
            ->viewData('users');

        $this->assertSame(2, $users->total());

        foreach ($users->items() as $user) {
            $this->assertContains($user->getKey(), $viewers->pluck('id')->all());
        }
    }

    public function test_the_index_hands_the_view_the_role_catalogue_and_the_active_filters(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['q' => 'sinta', 'role' => 'analyst']))
            ->assertOk();

        $this->assertSame(UserRole::cases(), $response->viewData('roles'));
        $this->assertSame(['q' => 'sinta', 'role' => 'analyst'], $response->viewData('filters'));
    }

    // ------------------------------------------------------------------
    // create
    // ------------------------------------------------------------------

    public function test_creating_a_user_persists_the_account(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Rangga Saputra',
                'email' => 'rangga@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'analyst',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $created = User::where('email', 'rangga@example.co.id')->firstOrFail();

        $this->assertSame('Rangga Saputra', $created->name);
        $this->assertSame(UserRole::Analyst, $created->role());
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('Rahasia123', $created->password));
    }

    public function test_creating_a_user_writes_a_user_created_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Rangga Saputra',
                'email' => 'rangga@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'viewer',
            ]);

        $created = User::where('email', 'rangga@example.co.id')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'resource' => 'user',
            'resource_id' => $created->getKey(),
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_creating_a_user_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'kembar@example.co.id']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Duplikat',
                'email' => 'kembar@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'analyst',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'kembar@example.co.id')->count());
    }

    public function test_creating_a_user_rejects_a_weak_password(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Kurang Kuat',
                'email' => 'lemah@example.co.id',
                'password' => 'password',
                'role' => 'analyst',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'lemah@example.co.id']);
    }

    public function test_creating_a_user_rejects_an_unknown_role(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Peran Aneh',
                'email' => 'aneh@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'superuser',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'aneh@example.co.id']);
    }

    public function test_creating_a_user_requires_a_name_an_email_a_password_and_a_role(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['name', 'email', 'password', 'role']);

        $this->assertDatabaseCount('users', 1);
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_updating_a_user_changes_the_name_email_role_and_active_flag(): void
    {
        $target = User::factory()->viewer()->create([
            'name' => 'Nama Lama',
            'email' => 'lama@example.co.id',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $target), [
                'name' => 'Nama Baru',
                'email' => 'baru@example.co.id',
                'role' => 'analyst',
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.users.index'));

        $fresh = $target->fresh();

        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame('baru@example.co.id', $fresh->email);
        $this->assertSame(UserRole::Analyst, $fresh->role());
        $this->assertFalse($fresh->is_active);
    }

    /**
     * The point of the audit row: an admin who re-submits an unchanged field
     * must not make the trail claim that field moved. A row that lists every
     * submitted field is noise, so this asserts the diff, not the payload.
     */
    public function test_the_update_audit_row_lists_only_the_fields_that_actually_changed(): void
    {
        $target = User::factory()->viewer()->create([
            'name' => 'Sinta Wibowo',
            'email' => 'sinta@example.co.id',
        ]);

        // `name` and `email` are re-submitted byte-identical; only the role moves.
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $target), [
                'name' => 'Sinta Wibowo',
                'email' => 'sinta@example.co.id',
                'role' => 'analyst',
            ])
            ->assertRedirect(route('admin.users.index'));

        $log = AuditLog::query()
            ->where('action', 'user.updated')
            ->where('resource', 'user')
            ->where('resource_id', $target->getKey())
            ->firstOrFail();

        $this->assertSame(['role'], $log->detail['fields'] ?? null);
    }

    public function test_updating_a_user_rejects_an_email_owned_by_another_account(): void
    {
        User::factory()->create(['email' => 'dipakai@example.co.id']);
        $target = User::factory()->analyst()->create(['email' => 'saya@example.co.id']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $target), ['email' => 'dipakai@example.co.id'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('email');

        $this->assertSame('saya@example.co.id', $target->fresh()->email);
    }

    public function test_a_user_may_keep_their_own_email_on_update(): void
    {
        $target = User::factory()->analyst()->create(['email' => 'tetap@example.co.id']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $target), ['email' => 'tetap@example.co.id'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionDoesntHaveErrors();
    }

    // ------------------------------------------------------------------
    // delete
    // ------------------------------------------------------------------

    public function test_deleting_a_user_removes_the_row(): void
    {
        $target = User::factory()->analyst()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('users', ['id' => $target->getKey()]);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        // A second admin exists so the self-delete guard is what is under test,
        // not the last-administrator guard.
        User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $this->admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('users', ['id' => $this->admin->getKey()]);
    }

    public function test_the_last_administrator_cannot_be_deleted(): void
    {
        // Reachable only through the self-delete guard: with two admins, A
        // deleting B leaves one and must be allowed, so the "keep at least one"
        // branch is only ever reached for the actor's own account.
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $other))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status')
            ->assertSessionMissing('error');

        $this->assertDatabaseMissing('users', ['id' => $other->getKey()]);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $this->admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->getKey()]);
    }

    public function test_a_deleted_user_is_audited_with_their_name_and_role(): void
    {
        $target = User::factory()->analyst()->create(['name' => 'Hapus Me']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $target));

        $log = AuditLog::query()->where('action', 'user.deleted')->firstOrFail();

        $this->assertSame('user', $log->resource);
        $this->assertSame($target->getKey(), (int) $log->resource_id);
        $this->assertSame('Hapus Me', $log->detail['name'] ?? null);
        $this->assertSame('analyst', $log->detail['role'] ?? null);
    }

    // ------------------------------------------------------------------
    // authorization
    // ------------------------------------------------------------------

    public function test_an_admin_reaches_the_user_list(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    #[DataProvider('adminUserWriteRoutes')]
    public function test_an_admin_may_use_every_admin_user_write_route(string $routeName, string $method): void
    {
        $target = User::factory()->analyst()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.users.index'))
            ->call($method, $this->uriFor($routeName, $target), $this->payloadFor($routeName))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    #[DataProvider('adminUserRoutes')]
    public function test_an_analyst_is_forbidden_on_every_admin_user_route(string $routeName, string $method): void
    {
        $this->assertRoleBlocked($routeName, $method, User::factory()->analyst()->create());
    }

    #[DataProvider('adminUserRoutes')]
    public function test_a_viewer_is_forbidden_on_every_admin_user_route(string $routeName, string $method): void
    {
        $this->assertRoleBlocked($routeName, $method, User::factory()->viewer()->create());
    }

    protected function assertRoleBlocked(string $routeName, string $method, User $actor): void
    {
        $target = User::factory()->analyst()->create();

        $this->actingAs($actor)
            ->from(route('admin.users.index'))
            ->call($method, $this->uriFor($routeName, $target), $this->payloadFor($routeName))
            ->assertForbidden();

        // The signed-in admin from setUp(), the blocked actor, and the target.
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', ['id' => $target->getKey()]);
    }

    protected function uriFor(string $routeName, User $target): string
    {
        return match ($routeName) {
            'admin.users.index', 'admin.users.store' => route($routeName),
            default => route($routeName, $target),
        };
    }

    /** @return array<string, mixed> */
    protected function payloadFor(string $routeName): array
    {
        return match ($routeName) {
            'admin.users.store' => [
                'name' => 'Dari Formulir',
                'email' => 'formulir@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'analyst',
            ],
            'admin.users.update' => ['name' => 'Diperbarui'],
            default => [],
        };
    }
}
