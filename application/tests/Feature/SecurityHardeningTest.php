<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the hardening pass: deactivation, conversation ownership, token expiry,
 * the write-behind-a-GET quality route, the file-deleting API delete, and the
 * refusal of server-owned columns on the dataset endpoints.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected User $otherAnalyst;

    /**
     * Read at request time, not at fake-registration time: a later `Http::fake()`
     * call cannot override an earlier stub (stub callbacks are matched
     * first-registered-wins), so per-test variation has to go through state.
     */
    protected float $qualityScore = 0.91;

    protected int $engineConversationId = 88;

    protected int $importJobId = 42;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->analyst()->create();
        $this->otherAnalyst = User::factory()->analyst()->create();

        Storage::fake('local');
        $this->fakeEngine();
    }

    /**
     * A single closure stub rather than a URL map: `Http::response()` returns a
     * promise, and stub callbacks are resolved first-registered-wins, so a
     * second `Http::fake()` in a test could never override a map registered in
     * `setUp()`. Reading `$this->qualityScore` at request time is what makes
     * per-test variation possible.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            $payload = match (true) {
                str_contains($url, '/imports/quality/') => [
                    'score' => $this->qualityScore,
                    'breakdown' => [
                        'completeness' => 0.97, 'uniqueness' => 0.88, 'validity' => 0.92, 'consistency' => 0.87,
                    ],
                    'issues' => [],
                    'passed' => $this->qualityScore >= 0.75,
                ],
                str_ends_with($url, '/imports/upload') => [
                    'upload_id' => 1,
                    'import_job_id' => $this->importJobId,
                    'validation' => [
                        'ok' => true,
                        'meta' => [
                            'size_bytes' => 2048,
                            'mime' => 'text/csv',
                            'checksum_sha256' => 'abc123',
                        ],
                    ],
                    'stored_path' => '/data/storage/sales.csv',
                ],
                str_ends_with($url, '/ai/chat') => [
                    'answer' => 'Pendapatan naik 4%.',
                    'conversation_id' => $this->engineConversationId,
                    'evidence' => [],
                    'steps' => 2,
                ],
                str_ends_with($url, '/imports/commit') => ['status' => 'queued'],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /**
     * `forceFill`, not `create`: `import_job_id` is a server-owned column, and
     * `Dataset::$fillable` deliberately no longer carries it. `create()` would
     * drop the key silently and every test below would then hit
     * "Dataset has no import job yet" instead of the behaviour it is pinning.
     * This is the same call path `DatasetIngestionService` uses.
     */
    protected function dataset(array $attributes = []): Dataset
    {
        $dataset = new Dataset;
        $dataset->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => 'Penjualan Retail 2026',
            'dataset_type' => 'sales',
            'source_filename' => 'penjualan.csv',
            'disk' => 'local',
            'path' => 'datasets/2026/09/penjualan.csv',
            'size_bytes' => 2048,
            'status' => 'uploaded',
            'import_job_id' => $this->importJobId,
            'user_id' => $this->analyst->getKey(),
            ...$attributes,
        ])->save();

        return $dataset;
    }

    /** A known password, so a login attempt is refused on the account and not on the secret. */
    protected function userWithPassword(string $role, bool $isActive = true, string $email = 'target@example.com'): User
    {
        return User::factory()->create([
            'name' => 'Target User',
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'is_active' => $isActive,
        ]);
    }

    // ------------------------------------------------------------------
    // EnsureAccountActive
    // ------------------------------------------------------------------

    public function test_a_deactivated_viewer_is_refused_on_the_read_only_pages(): void
    {
        // The regression the new middleware exists for: `EnsureRole` only
        // guards the write and admin routes, so before it these three were
        // still fully readable by a deactivated read-only account.
        $viewer = User::factory()->viewer()->inactive()->create();

        foreach ([route('datasets.index'), route('dashboard'), route('assistant.index')] as $url) {
            $this->actingAs($viewer)
                ->get($url)
                ->assertForbidden()
                ->assertViewIs('errors.inactive');
        }
    }

    public function test_a_deactivated_viewer_is_refused_on_the_audit_log(): void
    {
        $viewer = User::factory()->viewer()->inactive()->create();

        $this->actingAs($viewer)
            ->get(route('audit.index'))
            ->assertForbidden()
            ->assertViewIs('errors.inactive');
    }

    public function test_a_deactivated_admin_is_refused_on_the_admin_pages(): void
    {
        // The admin role is the one that passes `role:admin`, so only the new
        // middleware can refuse this request: `EnsureRole` has nothing left to
        // reject and the audit rows would otherwise stay readable.
        $admin = User::factory()->admin()->inactive()->create();

        $this->actingAs($admin)
            ->get(route('audit.index'))
            ->assertForbidden()
            ->assertViewIs('errors.inactive');
    }

    public function test_a_deactivated_account_gets_403_json_on_the_api_and_not_a_redirect(): void
    {
        // A real bearer token, not `Sanctum::actingAs()`: the token is the
        // credential that survives deactivation, so it is the one that has to
        // stop working. Deactivating before the first authenticated call also
        // avoids the test harness reusing a guard that already cached the
        // user as active.
        $user = $this->userWithPassword('viewer', email: 'deactivated@example.com');
        $token = $this->postJson(route('api.login'), [
            'email' => 'deactivated@example.com',
            'password' => 'Secret123!',
        ])->assertOk()->json('data.token');

        $user->forceFill(['is_active' => false])->save();

        // Plain `get()`, not `getJson()`: `ForceJsonResponse` has to force the
        // `Accept` header for a client that never sets one, and a 302 to the
        // login page is the failure this replaces.
        $response = $this->withToken((string) $token)->get(route('api.datasets.index'));

        $response->assertForbidden()
            ->assertJsonPath('code', 'account_inactive')
            ->assertJsonPath('message', 'This account is deactivated.')
            ->assertHeader('Content-Type', 'application/json');

        $this->assertStringNotContainsString('<html', (string) $response->getContent());
    }

    public function test_an_active_account_with_a_bearer_token_still_reaches_the_api(): void
    {
        $this->userWithPassword('viewer', email: 'active@example.com');
        $token = $this->postJson(route('api.login'), [
            'email' => 'active@example.com',
            'password' => 'Secret123!',
        ])->assertOk()->json('data.token');

        $this->withToken((string) $token)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('data.email', 'active@example.com');
    }

    public function test_a_deactivated_admin_cannot_log_in_through_the_web_form(): void
    {
        $this->userWithPassword('admin', isActive: false, email: 'offadmin@example.com');

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'offadmin@example.com',
                'password' => 'Secret123!',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_admin_cannot_exchange_credentials_for_a_token(): void
    {
        $this->userWithPassword('admin', isActive: false, email: 'offtoken@example.com');

        // The right password, so a 422 can only be the account state.
        $this->postJson(route('api.login'), [
            'email' => 'offtoken@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ------------------------------------------------------------------
    // AgentController conversation ownership
    // ------------------------------------------------------------------

    public function test_the_agent_refuses_another_users_conversation_id_without_calling_the_engine(): void
    {
        $foreign = ChatThread::factory()->forUser($this->otherAnalyst)->create([
            'ai_conversation_id' => 77,
        ]);

        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.agent.chat'), [
            'message' => 'Lanjutkan percakapan itu',
            'conversation_id' => $foreign->ai_conversation_id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id')
            ->assertJsonPath('errors.conversation_id.0', 'Unknown conversation for this account.');

        // The id is sequential and guessable, so the refusal has to happen
        // before the request leaves the box: reaching the engine would hand
        // back the other account's conversation history.
        Http::assertNothingSent();
    }

    public function test_the_agent_refuses_an_unknown_conversation_id_without_calling_the_engine(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.agent.chat'), [
            'message' => 'Lanjutkan percakapan itu',
            'conversation_id' => 999_999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');

        Http::assertNothingSent();
    }

    public function test_the_agent_refuses_a_conversation_id_the_caller_does_not_own_even_with_the_right_role(): void
    {
        // A viewer owns no threads at all, so the same id is refused for a
        // second, independent reason: no role grant implies no conversation.
        ChatThread::factory()->forUser($this->analyst)->create(['ai_conversation_id' => 77]);

        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->postJson(route('api.agent.chat'), [
            'message' => 'Lanjutkan percakapan itu',
            'conversation_id' => 77,
        ])->assertStatus(422)->assertJsonValidationErrors('conversation_id');

        Http::assertNothingSent();
    }

    public function test_the_agent_claims_the_new_engine_conversation_for_the_callers_own_thread(): void
    {
        $mine = ChatThread::factory()->forUser($this->analyst)->create(['ai_conversation_id' => null]);
        $theirs = ChatThread::factory()->forUser($this->otherAnalyst)->create(['ai_conversation_id' => null]);

        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.agent.chat'), ['message' => 'Bagaimana penjualan?'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', $this->engineConversationId);

        $this->assertSame($this->engineConversationId, $mine->fresh()->ai_conversation_id);
        $this->assertNull($theirs->fresh()->ai_conversation_id, 'Another account\'s thread was claimed.');
    }

    public function test_the_agent_forwards_an_owned_conversation_id_to_the_engine(): void
    {
        $mine = ChatThread::factory()->forUser($this->analyst)->create(['ai_conversation_id' => 77]);

        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.agent.chat'), [
            'message' => 'Lanjutkan',
            'conversation_id' => $mine->ai_conversation_id,
        ])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/ai/chat')
            && $request['conversation_id'] === 77);
    }

    // ------------------------------------------------------------------
    // API dataset delete
    // ------------------------------------------------------------------

    public function test_the_api_delete_removes_the_stored_file_from_disk(): void
    {
        $dataset = $this->dataset();
        Storage::disk('local')->put($dataset->path, "a,b\n1,2\n");
        Storage::disk('local')->assertExists($dataset->path);

        Sanctum::actingAs($this->analyst);

        $this->deleteJson(route('api.datasets.destroy', $dataset))->assertOk();

        // Without the delete the row went away and the uploaded file stayed on
        // disk forever, with nothing left pointing at it and no way to reclaim
        // it.
        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
        Storage::disk('local')->assertMissing($dataset->path);
    }

    public function test_the_api_delete_writes_dataset_deleted_with_the_name_and_the_size(): void
    {
        $dataset = $this->dataset(['name' => 'Penjualan Q1', 'size_bytes' => 2048]);

        Sanctum::actingAs($this->analyst);

        $this->deleteJson(route('api.datasets.destroy', $dataset))->assertOk();

        $log = AuditLog::query()
            ->where('action', 'dataset.deleted')
            ->where('resource', 'dataset')
            ->firstOrFail();

        $this->assertSame($dataset->getKey(), (int) $log->resource_id);
        $this->assertSame($this->analyst->getKey(), (int) $log->user_id);
        $this->assertSame([
            'name' => 'Penjualan Q1',
            'source_filename' => 'penjualan.csv',
            'size_bytes' => 2048,
        ], $log->detail);
    }

    public function test_a_viewer_cannot_delete_a_dataset_through_the_api(): void
    {
        $dataset = $this->dataset();
        Storage::disk('local')->put($dataset->path, "a,b\n1,2\n");

        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->deleteJson(route('api.datasets.destroy', $dataset))->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $dataset->getKey()]);
        Storage::disk('local')->assertExists($dataset->path);
    }

    // ------------------------------------------------------------------
    // GET .../quality is a write behind a safe verb
    // ------------------------------------------------------------------

    public function test_a_viewer_is_refused_on_the_api_quality_endpoint(): void
    {
        $dataset = $this->dataset();

        Sanctum::actingAs(User::factory()->viewer()->create());

        $this->getJson(route('api.datasets.quality', $dataset))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        // A prefetch or a link preview must not be able to run the profiler
        // and write `quality_score` / `status` / `metadata` back to the row.
        Http::assertNothingSent();
    }

    public function test_an_analyst_is_allowed_on_the_api_quality_endpoint(): void
    {
        $dataset = $this->dataset();

        Sanctum::actingAs($this->analyst);

        $this->getJson(route('api.datasets.quality', $dataset))
            ->assertOk()
            ->assertJsonPath('data.dataset_id', $dataset->uuid)
            ->assertJsonPath('data.score', 0.91);

        $this->assertSame(0.91, (float) $dataset->fresh()->quality_score);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with(
            $request->url(),
            '/api/v1/imports/quality/'.$this->importJobId
        ));
    }

    // ------------------------------------------------------------------
    // API token expiry
    // ------------------------------------------------------------------

    public function test_a_token_issued_while_the_ttl_was_zero_is_expired_and_a_fresh_one_is_not(): void
    {
        $this->userWithPassword('analyst', email: 'ttl@example.com');

        config(['ai_engine.token_ttl_days' => 0]);

        $legacy = $this->postJson(route('api.login'), [
            'email' => 'ttl@example.com',
            'password' => 'Secret123!',
        ])->assertOk()->json('data.token');

        // The TTL is read at issue time and written onto the row, so widening
        // the config afterwards must not resurrect a token that was already
        // minted with no lifetime.
        config(['ai_engine.token_ttl_days' => 30]);

        $current = $this->postJson(route('api.login'), [
            'email' => 'ttl@example.com',
            'password' => 'Secret123!',
        ])->assertOk()->json('data.token');

        $this->travel(2)->minutes();

        $this->withToken((string) $legacy)
            ->getJson(route('api.me'))
            ->assertUnauthorized();

        $this->withToken((string) $current)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('data.email', 'ttl@example.com');
    }

    public function test_a_token_carries_the_configured_expiry(): void
    {
        $this->userWithPassword('analyst', email: 'expiry@example.com');

        $this->travelTo(now()->startOfDay()->addHours(9));

        config(['ai_engine.token_ttl_days' => 7]);

        $this->postJson(route('api.login'), [
            'email' => 'expiry@example.com',
            'password' => 'Secret123!',
        ])->assertOk();

        $this->assertSame(
            now()->addDays(7)->toDateTimeString(),
            $this->newTokenExpiry(),
            'A non-expiring bearer token is a permanent credential once leaked.'
        );
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_the_api_upload_ignores_server_owned_columns_sent_by_the_client(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
            'dataset_type' => 'sales',
            'status' => 'committed',
            'quality_score' => 0.99,
            'import_job_id' => 9999,
            'user_id' => $this->otherAnalyst->getKey(),
            'disk' => 's3',
            'path' => 'evil/payload.csv',
        ])->assertCreated();

        $dataset = Dataset::firstOrFail();

        $this->assertSame('uploaded', $dataset->status->value);
        $this->assertNull($dataset->quality_score);
        $this->assertSame($this->importJobId, (int) $dataset->import_job_id);
        $this->assertSame($this->analyst->getKey(), (int) $dataset->user_id);
        $this->assertSame('local', $dataset->disk);
        $this->assertStringStartsWith('datasets/', (string) $dataset->path);
        $this->assertSame('sales.csv', $dataset->source_filename);

        Storage::disk('local')->assertExists($dataset->path);
        Storage::disk('local')->assertMissing('evil/payload.csv');
    }

    public function test_the_api_commit_ignores_server_owned_columns_sent_by_the_client(): void
    {
        $dataset = $this->dataset();

        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.datasets.commit', $dataset), [
            'run_async' => true,
            'status' => 'committed',
            'quality_score' => 0.99,
            'import_job_id' => 9999,
            'user_id' => $this->otherAnalyst->getKey(),
            'disk' => 's3',
            'path' => 'evil/payload.csv',
        ])->assertStatus(202);

        $fresh = $dataset->fresh();

        // The engine answered `queued`, so the mirror has to say `importing`:
        // a client-supplied `committed` would claim a dataset is in the
        // warehouse when no import ran.
        $this->assertSame('importing', $fresh->status->value);
        $this->assertNull($fresh->quality_score);
        $this->assertSame($this->importJobId, (int) $fresh->import_job_id);
        $this->assertSame($this->analyst->getKey(), (int) $fresh->user_id);
        $this->assertSame('local', $fresh->disk);
        $this->assertSame('datasets/2026/09/penjualan.csv', $fresh->path);
    }

    public function test_the_web_upload_ignores_server_owned_columns_sent_by_the_client(): void
    {
        $this->actingAs($this->analyst)
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('sales.csv', "a,b\n1,2\n"),
                'dataset_type' => 'sales',
                'status' => 'committed',
                'quality_score' => 0.99,
                'import_job_id' => 9999,
                'user_id' => $this->otherAnalyst->getKey(),
                'disk' => 's3',
                'path' => 'evil/payload.csv',
            ])
            ->assertSessionHasNoErrors();

        $dataset = Dataset::firstOrFail();

        $this->assertSame('uploaded', $dataset->status->value);
        $this->assertNull($dataset->quality_score);
        $this->assertSame($this->importJobId, (int) $dataset->import_job_id);
        $this->assertSame($this->analyst->getKey(), (int) $dataset->user_id);
        $this->assertSame('local', $dataset->disk);
        $this->assertStringStartsWith('datasets/', (string) $dataset->path);
    }

    private function newTokenExpiry(): ?string
    {
        $token = PersonalAccessToken::query()->latest('id')->first();

        return $token?->expires_at?->toDateTimeString();
    }
}
