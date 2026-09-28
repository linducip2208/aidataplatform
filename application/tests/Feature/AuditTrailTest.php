<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every mutating action in the platform must leave a row in `audit_logs` with
 * the right action string, the right resource, and the acting user.
 *
 * The action strings asserted here were enumerated from the code with
 * `grep -rn "AuditLog::record(" app/`, not from a spec, so a renamed action
 * fails the suite rather than drifting silently.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected const API_PASSWORD = 'ApiSecret123';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->admin()->create([
            'name' => 'Administrator Platform',
            'email' => 'admin@example.com',
            'password' => Hash::make(self::API_PASSWORD),
        ]);

        $this->fakeEngine();
    }

    /**
     * A single closure stub: `Http` stub callbacks are matched
     * first-registered-wins, so every engine route the suite touches has to be
     * answered from one place.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            $payload = match (true) {
                str_ends_with($url, '/imports/upload') => [
                    'import_job_id' => 42,
                    'validation' => [
                        'ok' => true,
                        'meta' => [
                            'size_bytes' => 2048,
                            'mime' => 'text/csv',
                            'checksum_sha256' => 'abc123',
                        ],
                    ],
                ],
                str_contains($url, '/imports/preview/') => [
                    'row_count' => 128,
                    'column_count' => 2,
                    'columns' => [['name' => 'tanggal'], ['name' => 'qty']],
                ],
                str_contains($url, '/imports/quality/') => [
                    'score' => 0.91,
                    'breakdown' => ['completeness' => 0.97, 'uniqueness' => 0.88],
                    'issues' => [],
                    'passed' => true,
                ],
                str_contains($url, '/imports/jobs/') => [
                    'status' => 'succeeded', 'total_rows' => 128, 'processed_rows' => 128,
                ],
                str_ends_with($url, '/imports/mapping/suggest') => ['qty' => 'quantity'],
                str_ends_with($url, '/imports/mapping') => ['mappings' => ['qty' => 'quantity']],
                str_ends_with($url, '/imports/commit') => ['import_job_id' => 42, 'status' => 'queued'],
                str_ends_with($url, '/training/train') => ['model_id' => 7, 'version' => 'v3'],
                str_ends_with($url, '/promote') => ['model_id' => 7, 'version_id' => 3],
                str_ends_with($url, '/ai/chat') => [
                    'answer' => 'Penjualan naik 12% QoQ.',
                    'conversation_id' => 77,
                    'evidence' => [['title' => 'laporan-penjualan.pdf']],
                    'steps' => 2,
                ],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    protected function dataset(array $attributes = []): Dataset
    {
        return Dataset::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan Retail 2026',
            'dataset_type' => 'sales',
            'source_filename' => 'penjualan.csv',
            'disk' => 'local',
            'path' => 'datasets/2026/09/penjualan.csv',
            'size_bytes' => 2048,
            'status' => 'uploaded',
            'import_job_id' => 42,
            'user_id' => $this->admin->getKey(),
            ...$attributes,
        ]);
    }

    /**
     * Assert the shared contract for a mutating action, then hand the row back
     * so the caller can pin its action-specific `detail`.
     */
    protected function assertAuditedBy(
        string $action,
        User $actor,
        ?string $resource = null,
        ?int $resourceId = null,
    ): AuditLog {
        $log = AuditLog::query()
            ->where('action', $action)
            ->orderByDesc('id')
            ->firstOrFail();

        if ($resource !== null) {
            $this->assertSame($resource, $log->resource);
        }

        if ($resourceId !== null) {
            $this->assertSame($resourceId, (int) $log->resource_id);
        }

        $this->assertSame($actor->getKey(), $log->user_id);
        $this->assertSame($actor->email, $log->actor);
        $this->assertIsArray($log->detail);
        $this->assertNotEmpty($log->ip, 'audit rows must record the requesting IP.');

        return $log;
    }

    // ------------------------------------------------------------------
    // auth.* actions
    // ------------------------------------------------------------------

    public function test_a_web_login_is_audited(): void
    {
        $user = User::factory()->analyst()->create(['password' => Hash::make(self::API_PASSWORD)]);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::API_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $log = $this->assertAuditedBy('auth.login', $user, 'user', $user->getKey());

        $this->assertFalse($log->detail['remember'] ?? null);
    }

    public function test_a_web_logout_is_audited(): void
    {
        $user = User::factory()->analyst()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertAuditedBy('auth.logout', $user, 'user', $user->getKey());
    }

    public function test_a_password_change_is_audited(): void
    {
        $user = User::factory()->analyst()->create(['password' => Hash::make(self::API_PASSWORD)]);

        $this->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => self::API_PASSWORD,
                'password' => 'SandiBaru123',
                'password_confirmation' => 'SandiBaru123',
            ])
            ->assertRedirect(route('password.edit'));

        $this->assertAuditedBy('auth.password_changed', $user, 'user', $user->getKey());
    }

    public function test_an_api_login_is_audited(): void
    {
        $user = User::factory()->analyst()->create(['password' => Hash::make(self::API_PASSWORD)]);

        $this->postJson(route('api.login'), [
            'email' => $user->email,
            'password' => self::API_PASSWORD,
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->assertAuditedBy('auth.api_login', $user, 'user', $user->getKey());
    }

    public function test_an_api_logout_is_audited(): void
    {
        $user = User::factory()->analyst()->create(['password' => Hash::make(self::API_PASSWORD)]);

        $token = $this->postJson(route('api.login'), [
            'email' => $user->email,
            'password' => self::API_PASSWORD,
            'device_name' => 'phpunit',
        ])->json('data.token');

        $this->withToken($token)->postJson(route('api.logout'))->assertOk();

        // The login above is also audited, so this reads the newest row.
        $this->assertAuditedBy('auth.api_logout', $user, 'user', $user->getKey());
    }

    // ------------------------------------------------------------------
    // dataset.* actions
    // ------------------------------------------------------------------

    public function test_an_upload_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal\n2026-01-05\n"),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect();

        $dataset = Dataset::firstOrFail();
        $log = $this->assertAuditedBy('dataset.uploaded', $this->admin, 'dataset', $dataset->getKey());

        $this->assertSame('penjualan.csv', $log->detail['filename'] ?? null);
        $this->assertSame(42, $log->detail['import_job_id'] ?? null);
    }

    public function test_applying_a_mapping_is_audited(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);

        $dataset = $this->dataset();

        $this->actingAs($this->admin)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), ['mappings' => ['qty' => 'quantity']])
            ->assertRedirect();

        $log = $this->assertAuditedBy(
            'dataset.mapping_applied',
            $this->admin,
            'dataset',
            $dataset->getKey(),
        );

        $this->assertSame(['qty' => 'quantity'], $log->detail['mappings'] ?? null);
    }

    public function test_a_quality_check_is_audited(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);

        $dataset = $this->dataset();

        $this->actingAs($this->admin)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect();

        $log = $this->assertAuditedBy(
            'dataset.quality_checked',
            $this->admin,
            'dataset',
            $dataset->getKey(),
        );

        $this->assertSame('pass', $log->detail['verdict'] ?? null);
        $this->assertEqualsWithDelta(0.91, $log->detail['score'] ?? null, 0.0001);
        $this->assertEqualsWithDelta(0.75, $log->detail['threshold'] ?? null, 0.0001);
    }

    public function test_a_commit_is_audited(): void
    {
        $dataset = $this->dataset(['status' => 'mapped', 'mappings' => ['qty' => 'quantity']]);

        $this->actingAs($this->admin)
            ->post(route('datasets.commit', $dataset), ['run_async' => true])
            ->assertRedirect();

        $log = $this->assertAuditedBy('dataset.committed', $this->admin, 'dataset', $dataset->getKey());

        $this->assertSame('queued', $log->detail['status'] ?? null);
        $this->assertTrue($log->detail['async'] ?? false);
    }

    // ------------------------------------------------------------------
    // user.* actions
    // ------------------------------------------------------------------

    public function test_creating_a_user_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Rangga Saputra',
                'email' => 'rangga@example.co.id',
                'password' => 'Rahasia123',
                'role' => 'viewer',
            ])
            ->assertRedirect();

        $created = User::where('email', 'rangga@example.co.id')->firstOrFail();
        $log = $this->assertAuditedBy('user.created', $this->admin, 'user', $created->getKey());

        $this->assertSame('viewer', $log->detail['role'] ?? null);
    }

    public function test_updating_a_user_is_audited(): void
    {
        $target = User::factory()->analyst()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $target), ['role' => 'viewer'])
            ->assertRedirect();

        $log = $this->assertAuditedBy('user.updated', $this->admin, 'user', $target->getKey());

        $this->assertSame(['role'], $log->detail['fields'] ?? null);
    }

    public function test_deleting_a_user_is_audited(): void
    {
        $target = User::factory()->viewer()->create(['name' => 'Hapus Me']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect();

        $log = $this->assertAuditedBy('user.deleted', $this->admin, 'user', $target->getKey());

        $this->assertSame('Hapus Me', $log->detail['name'] ?? null);
        $this->assertSame('viewer', $log->detail['role'] ?? null);
    }

    // ------------------------------------------------------------------
    // model.* and agent.* actions
    // ------------------------------------------------------------------

    public function test_promoting_a_model_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.promote', ['modelId' => 7]), [
                'version_id' => 3,
                'to_status' => 'PRODUCTION',
            ])
            ->assertRedirect();

        $log = $this->assertAuditedBy('model.promoted', $this->admin, 'model', 7);

        $this->assertSame(3, $log->detail['version_id'] ?? null);
        $this->assertSame('PRODUCTION', $log->detail['to_status'] ?? null);
    }

    public function test_training_a_model_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('ml.index'))
            ->post(route('ml.train'), [
                'model_type' => 'forecast',
                'name' => 'Forecast Q3',
            ])
            ->assertRedirect();

        $log = $this->assertAuditedBy('model.trained', $this->admin, 'model', 7);

        $this->assertSame('forecast', $log->detail['model_type'] ?? null);
        $this->assertSame('Forecast Q3', $log->detail['name'] ?? null);
        $this->assertSame('v3', $log->detail['version'] ?? null);
    }

    public function test_an_assistant_reply_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('assistant.index'))
            ->post(route('assistant.store'), ['message' => 'Bagaimana penjualan bulan ini?'])
            ->assertRedirect();

        $thread = $this->admin->chatThreads()->firstOrFail();
        $log = $this->assertAuditedBy('assistant.chat', $this->admin, 'chat_thread', $thread->getKey());

        $this->assertSame(77, $log->detail['engine_conversation_id'] ?? null);
        $this->assertSame(2, $log->detail['steps'] ?? null);
    }

    public function test_an_agent_chat_is_audited(): void
    {
        $user = User::factory()->analyst()->create();

        Sanctum::actingAs($user);

        $this->postJson(route('api.agent.chat'), ['message' => 'Penjualan naik?'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 77);

        $log = $this->assertAuditedBy('agent.chat', $user, 'agent', 77);

        $this->assertSame(15, $log->detail['message_length'] ?? null);
        $this->assertSame(2, $log->detail['steps'] ?? null);
    }

    // ------------------------------------------------------------------
    // the admin audit page
    // ------------------------------------------------------------------

    public function test_the_audit_page_paginates_the_log(): void
    {
        AuditLog::query()->delete();

        foreach (range(1, 25) as $index) {
            $this->auditRow('dataset.committed', $index);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertViewIs('audit.index');

        $logs = $response->viewData('logs');

        $this->assertInstanceOf(LengthAwarePaginator::class, $logs);
        $this->assertSame(25, $logs->total());
        $this->assertCount(20, $logs->items());
        $this->assertSame(2, $logs->lastPage());
    }

    public function test_the_audit_page_filters_by_action(): void
    {
        AuditLog::query()->delete();

        $this->auditRow('dataset.committed', 1);
        $this->auditRow('dataset.committed', 2);
        $this->auditRow('user.created', 3);

        $logs = $this->actingAs($this->admin)
            ->get(route('audit.index', ['action' => 'dataset.committed']))
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(2, $logs->total());

        foreach ($logs->items() as $log) {
            $this->assertSame('dataset.committed', $log->action);
        }
    }

    /**
     * Regression pin: `?actor=` is a case-insensitive `whereLike`, which
     * compiles to `ILIKE` on pgsql and `LIKE` on sqlite. The literal Postgres
     * operator would throw "no such function: ilike" on the test database.
     */
    public function test_the_audit_page_filters_by_actor_case_insensitively(): void
    {
        AuditLog::query()->delete();

        $this->auditRow('auth.login', 1, 'Dewi.Lestari@Example.co.id');
        $this->auditRow('auth.login', 2, 'bagus@example.co.id');

        $logs = $this->actingAs($this->admin)
            ->get(route('audit.index', ['actor' => 'dewi.lestari']))
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(1, $logs->total());
        $this->assertSame('Dewi.Lestari@Example.co.id', $logs->items()[0]->actor);
    }

    public function test_the_audit_page_exposes_the_distinct_action_catalogue(): void
    {
        AuditLog::query()->delete();

        $this->auditRow('user.created', 1);
        $this->auditRow('user.created', 2);
        $this->auditRow('auth.login', 3);

        $response = $this->actingAs($this->admin)->get(route('audit.index'))->assertOk();

        $this->assertSame(['auth.login', 'user.created'], $response->viewData('actions')->all());
    }

    public function test_the_audit_page_is_forbidden_for_an_analyst(): void
    {
        $this->actingAs(User::factory()->analyst()->create())
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_the_audit_page_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_an_unauthenticated_row_falls_back_to_the_system_actor(): void
    {
        $log = AuditLog::record('system.sweep', 'dataset', 5, ['scanned' => 12]);

        $this->assertNull($log->user_id);
        $this->assertSame('system', $log->actor);
        $this->assertSame(12, $log->detail['scanned'] ?? null);
    }

    protected function auditRow(string $action, int $resourceId, ?string $actor = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->admin->getKey(),
            'actor' => $actor ?? $this->admin->email,
            'action' => $action,
            'resource' => 'dataset',
            'resource_id' => $resourceId,
            'detail' => ['seeded' => true],
        ]);
    }
}
