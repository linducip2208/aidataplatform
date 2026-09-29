<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The browser-facing upload wizard, driven through real form posts against the
 * `web` middleware group (session, validation, `back()` redirects, flashes).
 */
class WebWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    /**
     * Read at request time, not at fake-registration time: `Http` stub
     * callbacks are matched first-registered-wins, so per-test variation has
     * to travel through state rather than a second `Http::fake()`.
     */
    protected float $qualityScore = 0.91;

    protected string $jobStatus = 'succeeded';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->analyst = User::factory()->analyst()->create();

        $this->fakeEngine();
    }

    /**
     * One closure rather than a URL map: `Http::response()` returns a promise
     * and stub callbacks resolve first-registered-wins, so a second
     * `Http::fake()` inside a test could never override a map registered in
     * `setUp()`. Reading `$this->qualityScore` / `$this->jobStatus` at request
     * time is what makes per-test variation possible.
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
                    'columns' => [
                        ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0],
                        ['name' => 'qty', 'dtype' => 'int64', 'missing' => 3],
                    ],
                    'sample_rows' => [['tanggal' => '2026-01-05', 'qty' => 4]],
                    'warnings' => [],
                    'errors' => [],
                ],
                str_contains($url, '/imports/quality/') => $this->qualityReport($this->qualityScore),
                str_contains($url, '/imports/jobs/') => [
                    'status' => $this->jobStatus,
                    'total_rows' => 128,
                    'processed_rows' => 128,
                    'error_rows' => 0,
                    'progress' => 100.0,
                ],
                str_ends_with($url, '/imports/mapping/suggest') => [
                    'tanggal' => 'transaction_date',
                    'qty' => 'quantity',
                ],
                str_ends_with($url, '/imports/mapping') => ['mappings' => ['qty' => 'quantity']],
                str_ends_with($url, '/imports/commit') => ['import_job_id' => 42, 'status' => 'queued'],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** @return array<string, mixed> */
    protected function qualityReport(float $score): array
    {
        return [
            'score' => $score,
            'breakdown' => [
                'completeness' => 0.97, 'uniqueness' => 0.88, 'validity' => 0.92, 'consistency' => 0.87,
            ],
            'issues' => [],
            'passed' => $score >= 0.75,
        ];
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
            'user_id' => $this->analyst->getKey(),
            ...$attributes,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function writeRoutes(): array
    {
        return [
            'upload' => ['datasets.store', 'POST'],
            'preview' => ['datasets.preview', 'POST'],
            'mapping' => ['datasets.mapping', 'POST'],
            'quality' => ['datasets.quality', 'POST'],
            'commit' => ['datasets.commit', 'POST'],
            'destroy' => ['datasets.destroy', 'DELETE'],
        ];
    }

    // ------------------------------------------------------------------
    // login / logout
    // ------------------------------------------------------------------

    public function test_the_login_page_renders_for_a_guest(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
    }

    public function test_a_valid_login_starts_a_session_and_redirects_to_the_dashboard(): void
    {
        $this->get(route('login'))->assertOk();

        $this->post(route('login.store'), [
            'email' => $this->analyst->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->analyst);
    }

    public function test_a_valid_login_writes_the_last_login_at_timestamp(): void
    {
        $this->analyst->forceFill(['last_login_at' => null])->save();

        $this->post(route('login.store'), [
            'email' => $this->analyst->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull($this->analyst->fresh()->last_login_at);
    }

    public function test_a_wrong_password_fails_validation_on_the_email_field(): void
    {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => $this->analyst->email,
            'password' => 'SalahSekali123!',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $inactive = User::factory()->inactive()->create();

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $inactive->email,
            'password' => 'password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_ends_the_session_and_protects_the_pages_again(): void
    {
        $this->actingAs($this->analyst)->get(route('password.edit'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('datasets.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // password
    // ------------------------------------------------------------------

    public function test_the_password_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->analyst)
            ->get(route('password.edit'))
            ->assertOk()
            ->assertViewIs('profile.password');
    }

    public function test_a_correct_password_update_replaces_the_hash(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'BaruBanget123',
                'password_confirmation' => 'BaruBanget123',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('BaruBanget123', $this->analyst->fresh()->password));
    }

    public function test_the_password_update_requires_the_correct_current_password(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'BukanPasswordSaya1!',
                'password' => 'BaruBanget123',
                'password_confirmation' => 'BaruBanget123',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->analyst->fresh()->password));
    }

    public function test_the_password_update_enforces_the_confirmation_field(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'BaruBanget123',
                'password_confirmation' => 'TidakCocok123',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->analyst->fresh()->password));
    }

    public function test_the_password_update_rejects_a_password_that_is_too_weak(): void
    {
        $this->actingAs($this->analyst)
            ->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->analyst->fresh()->password));
    }

    // ------------------------------------------------------------------
    // upload
    // ------------------------------------------------------------------

    public function test_uploading_a_csv_stores_the_file_and_opens_the_dataset_page(): void
    {
        $response = $this->actingAs($this->analyst)
            ->from(route('datasets.create'))
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent(
                    'penjualan.csv',
                    "tanggal,qty\n2026-01-05,4\n"
                ),
                'dataset_type' => 'sales',
            ]);

        $dataset = Dataset::firstOrFail();

        $response->assertRedirect(route('datasets.show', $dataset))->assertSessionHas('status');

        Storage::disk('local')->assertExists($dataset->path);
        $this->assertSame('local', $dataset->disk);
        $this->assertSame('penjualan.csv', $dataset->source_filename);
        $this->assertSame('uploaded', $dataset->status->value);
        $this->assertSame(42, $dataset->import_job_id);

        Http::assertSent(function (ClientRequest $r): bool {
            // The upload is multipart, and `ClientRequest::data()` only decodes
            // url-encoded and JSON bodies, so the `dataset_type` field is
            // asserted against the raw multipart body instead of `$r[...]`.
            return str_ends_with($r->url(), '/api/v1/imports/upload')
                && str_contains($r->body(), 'name="dataset_type"')
                && str_contains($r->body(), 'sales')
                && str_contains($r->body(), 'penjualan.csv');
        });
    }

    // ------------------------------------------------------------------
    // the four workflow actions
    // ------------------------------------------------------------------

    public function test_preview_stores_the_profile_and_flashes_the_row_and_column_counts(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status', function ($message): bool {
                return is_string($message)
                    && str_contains($message, '128')
                    && str_contains($message, '2');
            });

        $fresh = $dataset->fresh();

        $this->assertSame(128, (int) $fresh->row_count);
        $this->assertSame(2, (int) $fresh->column_count);
        $this->assertSame(['tanggal', 'qty'], $fresh->columnNames());
        $this->assertSame('uploaded', $fresh->status->value);

        Http::assertSent(
            fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/preview/42')
        );
    }

    public function test_mapping_persists_the_mappings_and_flips_the_status_to_mapped(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
            ])
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $fresh = $dataset->fresh();

        $this->assertSame('mapped', $fresh->status->value);
        $this->assertSame([
            'tanggal' => 'transaction_date',
            'qty' => 'quantity',
        ], $fresh->mappings);
    }

    public function test_mapping_rejects_a_payload_where_every_column_is_blank(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => ['tanggal' => '', 'qty' => '   '],
            ])
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $fresh = $dataset->fresh();

        $this->assertSame('uploaded', $fresh->status->value);
        $this->assertNull($fresh->mappings);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'dataset.mapping_applied']);

        Http::assertNothingSent();
    }

    public function test_mapping_only_persists_columns_that_exist_on_the_dataset(): void
    {
        $dataset = $this->dataset([
            'columns' => [
                ['name' => 'tanggal', 'dtype' => 'date'],
                ['name' => 'qty', 'dtype' => 'int64'],
            ],
        ]);

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => [
                    'tanggal' => 'transaction_date',
                    'qty' => 'quantity',
                    'kolom_hantu' => 'phantom_field',
                ],
            ])
            ->assertRedirect(route('datasets.show', $dataset));

        $this->assertSame([
            'tanggal' => 'transaction_date',
            'qty' => 'quantity',
        ], $dataset->fresh()->mappings);
    }

    public function test_quality_quarantines_the_dataset_and_flashes_an_error_below_the_threshold(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);
        $this->qualityScore = 0.42;

        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $fresh = $dataset->fresh();

        $this->assertSame('quarantined', $fresh->status->value);
        $this->assertSame('quarantine', $fresh->quality_verdict);
        $this->assertSame(0.42, (float) $fresh->quality_score);
        $this->assertNotNull($fresh->quality_checked_at);
    }

    public function test_quality_passes_above_the_threshold_and_returns_the_dataset_to_uploaded(): void
    {
        config(['ai_engine.quality_threshold' => 0.75]);

        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status')
            ->assertSessionMissing('error');

        $fresh = $dataset->fresh();

        $this->assertSame('uploaded', $fresh->status->value);
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertSame(0.91, (float) $fresh->quality_score);
    }

    public function test_commit_flips_the_status_to_importing_and_redirects_to_the_import_page(): void
    {
        $dataset = $this->dataset([
            'status' => 'mapped',
            'mappings' => ['qty' => 'quantity'],
        ]);

        $this->actingAs($this->analyst)
            ->post(route('datasets.commit', $dataset), ['run_async' => true])
            ->assertRedirect(route('imports.show', $dataset))
            ->assertSessionHas('status');

        $this->assertSame('importing', $dataset->fresh()->status->value);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/commit')
            && $r['import_job_id'] === 42
            && $r['run_async'] === true
            && $r['mappings'] === ['qty' => 'quantity']);
    }

    // ------------------------------------------------------------------
    // import status refresh
    // ------------------------------------------------------------------

    public function test_refreshing_the_import_page_mirrors_a_succeeded_job_as_committed(): void
    {
        $this->jobStatus = 'succeeded';
        $dataset = $this->dataset(['status' => 'importing']);

        $this->actingAs($this->analyst)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk()
            ->assertViewIs('imports.show');

        $this->assertSame('committed', $dataset->fresh()->status->value);
        $this->assertSame(128, (int) $dataset->fresh()->row_count);

        Http::assertSent(
            fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/jobs/42')
        );
    }

    public function test_refreshing_the_import_page_mirrors_a_failed_job_as_failed(): void
    {
        $this->jobStatus = 'failed';
        $dataset = $this->dataset(['status' => 'importing']);

        $this->actingAs($this->analyst)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk();

        $this->assertSame('failed', $dataset->fresh()->status->value);
    }

    /**
     * Regression pin for `DatasetIngestionService::syncStatus()`. A job the
     * engine has merely queued says nothing about how far the upload wizard
     * has got, so a still-queued job must leave the pre-commit state alone.
     * Dragging `mapped`/`previewing` back to `importing` makes the wizard
     * replay the mapping step on every poll.
     */
    public function test_a_queued_job_does_not_drag_a_mapped_dataset_back_to_importing(): void
    {
        $this->jobStatus = 'queued';
        $dataset = $this->dataset(['status' => 'mapped', 'mappings' => ['qty' => 'quantity']]);

        $this->actingAs($this->analyst)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk();

        $this->assertSame('mapped', $dataset->fresh()->status->value);
    }

    public function test_a_queued_job_does_not_drag_a_previewing_dataset_back_to_importing(): void
    {
        $this->jobStatus = 'queued';
        $dataset = $this->dataset(['status' => 'previewing']);

        $this->actingAs($this->analyst)
            ->get(route('imports.show', ['dataset' => $dataset, 'refresh' => 1]))
            ->assertOk();

        $this->assertSame('previewing', $dataset->fresh()->status->value);
    }

    public function test_the_import_page_does_not_poll_the_engine_without_the_refresh_flag(): void
    {
        $dataset = $this->dataset(['status' => 'importing']);

        $this->actingAs($this->analyst)
            ->get(route('imports.show', $dataset))
            ->assertOk();

        $this->assertSame('importing', $dataset->fresh()->status->value);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // delete
    // ------------------------------------------------------------------

    public function test_destroy_removes_the_dataset_row_and_the_file_from_disk(): void
    {
        $path = 'datasets/2026/09/penjualan.csv';
        Storage::disk('local')->put($path, "tanggal,qty\n2026-01-05,4\n");

        $dataset = $this->dataset(['disk' => 'local', 'path' => $path]);

        $this->actingAs($this->analyst)
            ->from(route('datasets.index'))
            ->delete(route('datasets.destroy', $dataset))
            ->assertRedirect(route('datasets.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('datasets', ['id' => $dataset->getKey()]);
        Storage::disk('local')->assertMissing($path);
    }

    // ------------------------------------------------------------------
    // authorization
    // ------------------------------------------------------------------

    #[DataProvider('writeRoutes')]
    public function test_every_write_route_is_forbidden_for_a_viewer(string $routeName, string $method): void
    {
        $viewer = User::factory()->viewer()->create();
        $dataset = $this->dataset();

        $this->actingAs($viewer)
            ->from(route('datasets.show', $dataset))
            ->call($method, $this->writeUri($routeName, $dataset), $this->writePayload($routeName))
            ->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $dataset->getKey()]);
    }

    #[DataProvider('writeRoutes')]
    public function test_every_write_route_is_accepted_for_an_analyst(string $routeName, string $method): void
    {
        // The dataset helper attributes the row to $this->analyst, so the
        // owner acts here: ownership enforcement (DatasetPolicy) lets the
        // owner through exactly as the old open surface did.
        $dataset = $this->dataset();

        // A redirect with no validation errors is the browser-level "the role
        // gate let this through and the action ran" answer: the wizard routes
        // redirect rather than render.
        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->call($method, $this->writeUri($routeName, $dataset), $this->writePayload($routeName))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    #[DataProvider('writeRoutes')]
    public function test_write_routes_are_forbidden_for_a_non_owner_analyst(string $routeName, string $method): void
    {
        // Upload is a create (no dataset to own); every analyst may upload.
        if ($routeName === 'datasets.store') {
            $this->markTestSkipped('upload is owner-independent by design.');
        }

        $dataset = $this->dataset();
        $other = User::factory()->analyst()->create();

        $this->actingAs($other)
            ->from(route('datasets.show', $dataset))
            ->call($method, $this->writeUri($routeName, $dataset), $this->writePayload($routeName))
            ->assertForbidden();
    }

    protected function writeUri(string $routeName, Dataset $dataset): string
    {
        return $routeName === 'datasets.store'
            ? route($routeName)
            : route($routeName, $dataset);
    }

    /** @return array<string, mixed> */
    protected function writePayload(string $routeName): array
    {
        return match ($routeName) {
            'datasets.store' => [
                'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal\n2026-01-05\n"),
                'dataset_type' => 'sales',
            ],
            'datasets.mapping' => ['mappings' => ['qty' => 'quantity']],
            'datasets.commit' => ['run_async' => true],
            default => [],
        };
    }
}
