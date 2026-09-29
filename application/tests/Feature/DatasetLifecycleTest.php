<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Jobs\RefreshQualityScoreJob;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\User;
use App\Services\DatasetIngestionService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `DatasetIngestionService` as a state machine.
 *
 * It is the only thing that moves `datasets.status`, and the UI, the token API,
 * `sync:import-status` and the nightly `sync:quality` sweep all read that
 * column rather than the engine. So the assertions here are deliberately about
 * *the state after each step*, not about one behaviour per test: the failure
 * that matters is a transition that parks a row in a state nothing can move it
 * out of, and that is invisible to a test that only checks the happy path's
 * final value.
 *
 * Every engine interaction goes through one closure fake. A second
 * `Http::fake()` cannot override an earlier stub — stub callbacks resolve
 * first-registered-wins — so per-test variation is expressed as state on
 * `$this` that the stub reads at request time.
 */
class DatasetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** How the engine is broken, or null for a healthy engine. */
    protected ?string $engineFailure = null;

    /** Score the engine reports for `GET /imports/quality/{job}`. */
    protected float $qualityScore = 0.91;

    /**
     * The engine's own `passed` flag. `null` omits the key so `runQuality()`
     * falls back to `config('ai_engine.quality_threshold')` — it always
     * prefers the engine's answer when one is present.
     */
    protected ?bool $enginePassed = null;

    /** Status the engine reports for `POST /imports/commit`. */
    protected string $commitStatus = 'queued';

    /** Status the engine reports for `GET /imports/jobs/{job}`. */
    protected string $jobStatus = 'done';

    /** Whether the upload's `validation.ok` comes back true. */
    protected bool $uploadValid = true;

    protected User $analyst;

    /** Engine failure kinds, and the client status each one must produce. */
    private const API_FAILURE_STATUS = [
        'unreachable' => 503,
        'server_error' => 502,
        'auth' => 502,
        'rejected' => 422,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai_engine.quality_threshold' => 0.75]);

        Storage::fake('local');

        $this->analyst = User::factory()->analyst()->create();
        $this->fakeEngine();
    }

    // ------------------------------------------------------------------
    // happy path
    // ------------------------------------------------------------------

    public function test_the_full_happy_path_walks_upload_to_committed_and_audits_every_transition(): void
    {
        // `run_async` is the default an async commit takes: the engine accepts
        // the job and reports `queued`, so the mirror parks on `importing` and
        // the terminal transition only happens when a client polls the job.
        $this->commitStatus = 'queued';

        $this->actingAs($this->analyst)
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal,qty\n2026-01-05,4\n"),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $dataset = Dataset::query()->sole();

        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset));
        $this->assertSame(42, $dataset->import_job_id);
        $this->assertSame('penjualan.csv', $dataset->source_filename);
        $this->assertNull($dataset->committed_at);
        $this->assertEmpty($dataset->columnNames());

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $dataset = $dataset->fresh();

        // Preview is a round trip that leaves the row where it found it; the
        // work it did is the column profile.
        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset));
        $this->assertSame(['tanggal', 'qty'], $dataset->columnNames());
        $this->assertSame(128, (int) $dataset->row_count);
        $this->assertSame(2, (int) $dataset->column_count);
        $this->assertNull($dataset->committed_at);

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
            ])
            ->assertRedirect(route('datasets.show', $dataset));

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Mapped, $this->statusOf($dataset));
        $this->assertSame(
            ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
            $dataset->mappings
        );

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.quality', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset));
        $this->assertSame('pass', $dataset->quality_verdict);
        $this->assertSame(0.91, (float) $dataset->quality_score);
        $this->assertNotNull($dataset->quality_checked_at);
        $this->assertNull($dataset->committed_at);

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.commit', $dataset), ['run_async' => true])
            ->assertRedirect(route('imports.show', $dataset))
            ->assertSessionHas('status');

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Importing, $this->statusOf($dataset));
        $this->assertNotNull($dataset->committed_at);

        $stampedAt = $dataset->committed_at;

        // The client polls `GET /imports/jobs/{id}`; the engine has finished.
        $this->jobStatus = 'done';

        $this->ingestion()->syncStatus($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));
        $this->assertSame(128, (int) $dataset->row_count);
        $this->assertTrue(
            $stampedAt->equalTo($dataset->committed_at),
            'polling the job must not restamp committed_at'
        );

        $this->assertSame([
            'dataset.uploaded',
            'dataset.mapping_applied',
            'dataset.quality_checked',
            'dataset.committed',
            'dataset.status_synced',
        ], $this->auditActions($dataset));

        foreach (['dataset.uploaded', 'dataset.mapping_applied', 'dataset.quality_checked', 'dataset.committed'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'resource' => 'dataset',
                'resource_id' => $dataset->getKey(),
                'user_id' => $this->analyst->getKey(),
            ]);
        }
    }

    public function test_a_commit_the_engine_finishes_inline_lands_on_committed_without_a_poll(): void
    {
        $this->commitStatus = 'succeeded';

        $dataset = $this->dataset(['status' => DatasetStatus::Mapped->value]);

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.commit', $dataset), ['run_async' => false])
            ->assertRedirect(route('imports.show', $dataset));

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));
        $this->assertNotNull($dataset->committed_at);
        $this->assertSame(0, $this->syncAuditCount($dataset), 'a commit that never left `importing` has nothing to sync');
    }

    // ------------------------------------------------------------------
    // the quality branch
    // ------------------------------------------------------------------

    public function test_a_score_below_the_threshold_quarantines_and_the_dataset_stays_quarantined(): void
    {
        $this->qualityScore = 0.42;

        $dataset = $this->dataset();

        $this->runQuality($dataset);
        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($dataset));

        $this->runQuality($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($dataset));
        $this->assertSame('quarantine', $dataset->quality_verdict);
        $this->assertSame(0.42, (float) $dataset->quality_score);
        $this->assertNull($dataset->committed_at);

        // A second run still records the outcome, so the re-check is auditable
        // even though the state did not move.
        $this->assertSame([
            'dataset.quality_checked',
            'dataset.quality_checked',
        ], $this->auditActions($dataset));
    }

    public function test_a_score_above_the_threshold_lands_on_uploaded_and_never_on_quarantined(): void
    {
        $this->qualityScore = 0.91;

        $dataset = $this->dataset(['status' => DatasetStatus::Mapped->value]);

        $this->runQuality($dataset);
        $this->runQuality($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset));
        $this->assertNotSame(DatasetStatus::Quarantined, $this->statusOf($dataset));
        $this->assertSame('pass', $dataset->quality_verdict);
    }

    public function test_the_laravel_threshold_decides_when_the_engine_sends_no_passed_flag(): void
    {
        // `docs/data-quality.md` §3: Laravel prefers the engine's `passed` and
        // only falls back to its own threshold when the key is absent. A 0.70
        // against Laravel's 0.75 is therefore a quarantine here even though the
        // engine's own default threshold (0.6) would have passed it.
        $this->qualityScore = 0.70;
        $this->enginePassed = null;

        $dataset = $this->dataset();

        $result = $this->ingestion()->runQuality($dataset);

        $this->assertSame(0.70, $result['score']);
        $this->assertSame(0.75, $result['threshold']);
        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($dataset));

        $this->enginePassed = true;

        $this->runQuality($dataset);

        $this->assertSame('pass', $dataset->fresh()->quality_verdict);
    }

    public function test_a_quarantined_dataset_is_terminal_and_a_later_passing_run_does_not_release_it(): void
    {
        // Current behaviour, pinned deliberately: `runQuality()` guards on
        // `isTerminal()`, and `quarantined` is terminal, so a passing re-check
        // cannot move the row back to `uploaded`. `docs/data-quality.md` §3 says
        // the opposite ("it will not import until something re-checks it and the
        // score improves"); the code does not implement that, and the nightly
        // sweep never re-checks a non-committed row, so today the only way out
        // is a re-upload.
        $this->qualityScore = 0.42;
        $dataset = $this->dataset();

        $this->runQuality($dataset);
        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($dataset));

        $this->qualityScore = 0.99;

        $this->runQuality($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($dataset));
        $this->assertSame('pass', $dataset->quality_verdict, 'the verdict is still refreshed even though the state is not');
    }

    // ------------------------------------------------------------------
    // the re-check guard: the nightly sync:quality sweep runs on committed rows
    // ------------------------------------------------------------------

    public function test_a_passing_recheck_of_a_committed_dataset_leaves_it_committed(): void
    {
        // The regression this guards: `routes/console.php` runs
        // `sync:quality` every night over committed rows. Un-committing the
        // mirror on every pass would rewrite the status of the whole warehouse
        // once a night while the rows themselves stayed in place.
        $this->qualityScore = 0.93;

        $dataset = Dataset::factory()->committed()->create(['import_job_id' => 42]);
        $committedAt = $dataset->committed_at;

        $this->runQuality($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));
        $this->assertSame('pass', $dataset->quality_verdict);
        $this->assertSame(0.93, (float) $dataset->quality_score);
        $this->assertNotNull($dataset->quality_checked_at);
        $this->assertTrue($committedAt->equalTo($dataset->committed_at));
        $this->assertSame(['dataset.quality_checked'], $this->auditActions($dataset));
    }

    public function test_a_failing_recheck_of_a_committed_dataset_records_the_verdict_but_keeps_it_committed(): void
    {
        // A committed dataset's rows are already in the warehouse. Quarantining
        // it would say otherwise — and both re-check entry points
        // (`SyncQualityCommand::query()`, `RefreshQualityScoreJob::handle()`)
        // only look at non-terminal rows, so the sweep would quarantine it and
        // then never revisit it. The verdict is still recorded, so the quality
        // page shows the problem without lying about the warehouse.
        $this->qualityScore = 0.31;

        $dataset = Dataset::factory()->committed()->create(['import_job_id' => 42]);
        $committedAt = $dataset->committed_at;

        $this->runQuality($dataset);

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));
        $this->assertSame('quarantine', $dataset->quality_verdict, 'The failing verdict is still recorded.');
        $this->assertTrue(
            $committedAt->equalTo($dataset->committed_at),
            'the re-check does not clear committed_at'
        );

        // And the sweep keeps seeing it, because it is still committed: a
        // quarantined row would drop out of the candidate set and never be
        // re-checked again.
        $this->assertSame(0, $this->syncAuditCount($dataset));

        (new RefreshQualityScoreJob($dataset->uuid))->handle($this->ingestion());

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));

        $sibling = Dataset::factory()->committed()->create(['import_job_id' => 43]);

        $this->assertSame(0, Artisan::call('sync:quality', ['--force' => true, '--dry-run' => true]));

        $output = Artisan::output();

        $this->assertStringContainsString($sibling->uuid, $output, 'a committed sibling is still a sweep candidate');
        $this->assertStringContainsString(
            $dataset->uuid,
            $output,
            'a re-checked committed row stays in the sweep'
        );
    }

    // ------------------------------------------------------------------
    // syncStatus: the engine's job status drives the mirror
    // ------------------------------------------------------------------

    public function test_sync_status_only_transitions_on_an_engine_status_that_means_something(): void
    {
        $service = $this->ingestion();

        // Pre-commit states are held by every in-flight engine status.
        $mapped = $this->dataset(['status' => DatasetStatus::Mapped->value]);

        foreach (['queued', 'uploaded', 'pending'] as $inFlight) {
            $this->jobStatus = $inFlight;

            $service->syncStatus($mapped);

            $this->assertSame(
                DatasetStatus::Mapped,
                $this->statusOf($mapped),
                "engine status `{$inFlight}` must hold a pre-commit row"
            );
        }

        $this->assertSame(0, $this->syncAuditCount($mapped), 'a held state writes no audit row');

        // Terminal engine outcomes.
        foreach (['succeeded', 'success', 'completed', 'done'] as $finished) {
            $this->jobStatus = $finished;

            $service->syncStatus($mapped);

            $this->assertSame(
                DatasetStatus::Committed,
                $this->statusOf($mapped),
                "engine status `{$finished}` must commit the row"
            );
        }

        $this->assertSame(1, $this->syncAuditCount($mapped), 'only the transition is audited, not each poll');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dataset.status_synced',
            'resource_id' => $mapped->getKey(),
        ]);

        // Polling again after the transition changes nothing and writes nothing.
        $this->jobStatus = 'done';
        $service->syncStatus($mapped);

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($mapped));
        $this->assertSame(1, $this->syncAuditCount($mapped));

        // The failure family, including the two that are terminal in the engine.
        foreach (['failed', 'error', 'cancelled', 'canceled', 'aborted'] as $terminal) {
            $dataset = $this->dataset(['status' => DatasetStatus::Importing->value]);

            $this->jobStatus = $terminal;

            $service->syncStatus($dataset);

            $fresh = $dataset->fresh();

            $this->assertSame(
                DatasetStatus::Failed,
                $this->statusOf($dataset),
                "engine status `{$terminal}` must fail the row"
            );
            $this->assertTrue(
                $fresh->status()->isTerminal(),
                "engine status `{$terminal}` must leave a terminal state"
            );
            $this->assertSame(1, $this->syncAuditCount($dataset));

            // And a terminal row is not walked back by a later in-flight poll.
            $this->jobStatus = 'queued';
            $service->syncStatus($dataset);

            $this->assertSame(DatasetStatus::Failed, $this->statusOf($dataset));
            $this->assertSame(1, $this->syncAuditCount($dataset));
        }

        // A quarantined row is held by a poll too, so the mirror cannot quietly
        // release it between the sweep and an operator's decision.
        $quarantined = Dataset::factory()->quarantined()->create(['import_job_id' => 44]);

        $this->jobStatus = 'queued';
        $service->syncStatus($quarantined);

        $this->assertSame(DatasetStatus::Quarantined, $this->statusOf($quarantined));
        $this->assertSame(0, $this->syncAuditCount($quarantined));
    }

    public function test_an_unrecognised_engine_status_never_drives_a_pre_commit_row_out_of_its_state(): void
    {
        $service = $this->ingestion();

        // `done_with_errors` is not a hypothetical: the engine writes it for a
        // partial load (`ai-engine/app/ingestion/etl.py:301`) and this build has
        // never heard of it.
        foreach (['done_with_errors', 'retrying', 'suspended', ''] as $unknown) {
            foreach ([
                DatasetStatus::Uploaded,
                DatasetStatus::Previewing,
                DatasetStatus::Mapped,
                DatasetStatus::Importing,
            ] as $held) {
                $dataset = $this->dataset(['status' => $held->value]);

                $this->jobStatus = $unknown;

                $service->syncStatus($dataset);

                $this->assertSame(
                    $held,
                    $this->statusOf($dataset),
                    sprintf('engine status `%s` must not move a `%s` row', $unknown, $held->value)
                );
                $this->assertSame(0, $this->syncAuditCount($dataset));
            }
        }
    }

    public function test_an_unrecognised_engine_status_leaves_a_committed_row_committed(): void
    {
        // `done_with_errors` is a real status the engine writes for a partial
        // load, and it is not in this build's known set. An unrecognised status
        // must not drive a transition: the unrecognised guard used to list only
        // the pre-commit states, so a committed row fell through to
        // `default => Importing` and reported as still importing, with no
        // engine-side change and no way for the UI to tell the difference.
        $this->jobStatus = 'done_with_errors';

        $dataset = Dataset::factory()->committed()->create(['import_job_id' => 42]);

        $this->ingestion()->syncStatus($dataset);

        $this->assertSame(DatasetStatus::Committed, $this->statusOf($dataset));
        $this->assertSame(0, $this->syncAuditCount($dataset), 'No transition, so no status_synced audit row.');
    }

    // ------------------------------------------------------------------
    // mapping
    // ------------------------------------------------------------------

    public function test_a_mapping_drops_a_column_the_dataset_does_not_have_and_stores_what_was_applied(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => [
                    'tanggal' => 'transaction_date',
                    'qty' => 'quantity',
                    'harga_tidak_ada' => 'selling_price',
                ],
            ])
            ->assertRedirect(route('datasets.show', $dataset));

        $dataset = $dataset->fresh();

        $this->assertSame(
            ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
            $dataset->mappings,
            'a column the engine would silently skip must not be stored as applied'
        );
        $this->assertSame(DatasetStatus::Mapped, $this->statusOf($dataset));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/mapping')
            && $request['mappings'] === ['tanggal' => 'transaction_date', 'qty' => 'quantity']
            && $request['import_job_id'] === 42);

        $this->assertSame(['dataset.mapping_applied'], $this->auditActions($dataset));
    }

    public function test_a_mapping_where_every_column_is_unknown_is_a_422_and_stores_nothing(): void
    {
        $dataset = $this->dataset(['mappings' => null]);

        Sanctum::actingAs($this->analyst);

        $this->postJson(route('api.datasets.mapping', $dataset), [
            'mappings' => ['harga_a' => 'selling_price', 'harga_b' => 'cost_price'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_engine_error')
            ->assertJsonPath('operation', 'imports.mapping');

        $dataset = $dataset->fresh();

        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset), 'a rejected mapping must not advance the row');
        $this->assertNull($dataset->mappings);
        $this->assertSame([], $this->auditActions($dataset));

        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/mapping'));

        // The Blade route surfaces the same rejection as a flash, not a 500.
        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), [
                'mappings' => ['harga_a' => 'selling_price'],
            ])
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }

    public function test_a_dataset_with_no_profiled_columns_passes_its_mapping_through_untouched(): void
    {
        // A freshly uploaded row has no `columns` yet. Refusing the mapping
        // there would block the wizard between upload and preview, where the
        // analyst is told to run the preview first.
        $dataset = $this->dataset(['columns' => [], 'mappings' => null]);

        $this->assertSame([], $dataset->columnNames());

        $submitted = ['tanggal' => 'transaction_date', 'harga_tidak_ada' => 'selling_price'];

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.mapping', $dataset), ['mappings' => $submitted])
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $dataset = $dataset->fresh();

        $this->assertSame($submitted, $dataset->mappings);
        $this->assertSame(DatasetStatus::Mapped, $this->statusOf($dataset));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/mapping')
            && $request['mappings'] === $submitted);
    }

    // ------------------------------------------------------------------
    // commit
    // ------------------------------------------------------------------

    public function test_commit_sends_both_run_async_values_and_records_each_on_the_audit_row(): void
    {
        $async = $this->dataset(['status' => DatasetStatus::Mapped->value]);
        $sync = $this->dataset(['status' => DatasetStatus::Mapped->value]);

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $async))
            ->post(route('datasets.commit', $async), ['run_async' => true])
            ->assertRedirect(route('imports.show', $async));

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $sync))
            ->post(route('datasets.commit', $sync), ['run_async' => false])
            ->assertRedirect(route('imports.show', $sync));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/commit')
            && $request['import_job_id'] === $async->import_job_id
            && $request['run_async'] === true);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/commit')
            && $request['import_job_id'] === $sync->import_job_id
            && $request['run_async'] === false);

        $this->assertSame(['dataset.committed'], $this->auditActions($async));
        $this->assertSame(['dataset.committed'], $this->auditActions($sync));

        $this->assertTrue(
            $this->auditDetail($async, 'dataset.committed')['async'],
            'the audit row must record that the import was queued'
        );
        $this->assertFalse(
            $this->auditDetail($sync, 'dataset.committed')['async'],
            'the audit row must record that the import was run inline'
        );
    }

    public function test_the_api_commit_forwards_run_async_too(): void
    {
        Sanctum::actingAs($this->analyst);

        $dataset = $this->dataset(['status' => DatasetStatus::Mapped->value]);

        $this->postJson(route('api.datasets.commit', $dataset), ['run_async' => false])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.import_job_id', 42);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/v1/imports/commit')
            && $request['run_async'] === false);

        $this->assertFalse($this->auditDetail($dataset, 'dataset.committed')['async']);
    }

    // ------------------------------------------------------------------
    // engine failures
    // ------------------------------------------------------------------

    public function test_every_engine_failure_on_the_web_routes_is_a_flash_error_and_never_a_500(): void
    {
        foreach (array_keys(self::API_FAILURE_STATUS) as $kind) {
            foreach ($this->webFailureActions() as $label => $action) {
                $this->engineFailure = $kind;

                $response = $action();

                $this->assertSame(302, $response->getStatusCode(), "{$kind} on {$label}");
                $this->assertNotSame(500, $response->getStatusCode(), "{$kind} on {$label}");
                $response->assertSessionHas('error', null, "{$kind} on {$label}");
                $response->assertSessionMissing('status', "{$kind} on {$label}");

                $this->engineFailure = null;
            }
        }
    }

    public function test_every_engine_failure_on_the_api_routes_is_a_json_error_and_never_a_500(): void
    {
        foreach (self::API_FAILURE_STATUS as $kind => $expected) {
            foreach ($this->apiFailureActions() as $label => $action) {
                $this->engineFailure = $kind;

                $response = $action();

                $this->assertNotSame(500, $response->getStatusCode(), "{$kind} on {$label}");
                $this->assertSame(
                    $expected,
                    $response->getStatusCode(),
                    "{$kind} on {$label}: ".$response->getContent()
                );

                $response->assertJsonPath('code', 'ai_engine_error')
                    ->assertJsonStructure(['message', 'code', 'operation']);

                $this->engineFailure = null;
            }
        }
    }

    public function test_a_failed_preview_strands_the_row_in_previewing_until_the_next_one_recovers_it(): void
    {
        $this->engineFailure = 'unreachable';

        $dataset = $this->dataset();

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('error');

        $this->assertSame(
            DatasetStatus::Previewing,
            $this->statusOf($dataset),
            'preview writes `previewing` before the engine call, so a failure strands the row there'
        );

        $this->engineFailure = null;

        $this->actingAs($this->analyst)
            ->from(route('datasets.show', $dataset))
            ->post(route('datasets.preview', $dataset))
            ->assertRedirect(route('datasets.show', $dataset))
            ->assertSessionHas('status');

        $this->assertSame(DatasetStatus::Uploaded, $this->statusOf($dataset));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A single closure stub rather than a URL map, for the reason given on the
     * class: stub callbacks are resolved first-registered-wins, so a
     * `Http::fake()` registered inside a test could never override this one.
     * Every per-test knob below is read at request time.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if ($this->engineFailure !== null) {
                return $this->failureResponse($this->engineFailure);
            }

            // `/health` and `/readiness` answer with a bare model rather than
            // the `{success, data}` envelope (`AiEngineClient::decode()`
            // deliberately skips the unwrap for them), so faking the envelope
            // here would make `sync:quality`'s preflight report the engine as
            // unhealthy and the command would exit before it lists anything.
            if (str_ends_with($url, '/health')) {
                return Http::response(['status' => 'ok', 'version' => '1.4.0'], 200);
            }

            if (str_ends_with($url, '/readiness')) {
                return Http::response(['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']], 200);
            }

            $payload = match (true) {
                str_ends_with($url, '/imports/upload') => [
                    'upload_id' => 7,
                    'import_job_id' => 42,
                    'validation' => [
                        'ok' => $this->uploadValid,
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
                        ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'sample' => ['2026-01-05']],
                        ['name' => 'qty', 'dtype' => 'int64', 'missing' => 3, 'sample' => ['4']],
                    ],
                    'sample_rows' => [['tanggal' => '2026-01-05', 'qty' => 4]],
                    'warnings' => [],
                    'errors' => [],
                ],
                str_ends_with($url, '/imports/mapping/suggest') => [
                    'tanggal' => 'transaction_date',
                    'qty' => 'quantity',
                ],
                str_ends_with($url, '/imports/mapping') => ['mappings' => true],
                str_contains($url, '/imports/quality/') => $this->qualityReport(),
                str_ends_with($url, '/imports/commit') => [
                    'import_job_id' => 42,
                    'status' => $this->commitStatus,
                    'row_count' => 128,
                ],
                str_contains($url, '/imports/jobs/') => [
                    'import_job_id' => 42,
                    'status' => $this->jobStatus,
                    'progress' => 1.0,
                    'total_rows' => 128,
                    'processed_rows' => 128,
                    'error_rows' => 0,
                ],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /**
     * One entry per way the engine can fail, all of which have to reach the
     * caller as a usable message rather than a 500:
     *
     * - `unreachable`   the socket is refused  -> AiEngineException 503
     * - `server_error`  the engine answers 500 -> AiEngineException 500 -> 502
     * - `auth`          the service key is refused -> AiEngineException 401 -> 502
     * - `rejected`      a 200 carrying `{success: false}` -> AiEngineException 422
     *
     * No return type on purpose: a stub callback hands back whatever
     * `Http::response()` produced (a `FulfilledPromise`), and declaring
     * `Response` here raises a `TypeError` inside `AiEngineClient::send()`,
     * which its `catch (Throwable)` then reports as a 502 for *every* failure
     * kind — the fake would pass while testing nothing.
     *
     * @return PromiseInterface
     */
    protected function failureResponse(string $kind)
    {
        return match ($kind) {
            'unreachable' => throw new ConnectionException('Connection refused'),
            'server_error' => Http::response(['detail' => 'the ETL worker pool is on fire'], 500),
            'auth' => Http::response(['error' => ['message' => 'Invalid service key']], 401),
            'rejected' => Http::response(
                ['success' => false, 'error' => ['message' => 'unsupported file encoding: latin-1']],
                200
            ),
            default => Http::response(['detail' => 'unknown failure'], 500),
        };
    }

    /** @return array<string, mixed> */
    protected function qualityReport(): array
    {
        $report = [
            'score' => $this->qualityScore,
            'breakdown' => [
                'completeness' => 0.97,
                'uniqueness' => 0.88,
                'validity' => 0.92,
                'consistency' => 0.87,
            ],
            'issues' => [],
        ];

        if ($this->enginePassed !== null) {
            $report['passed'] = $this->enginePassed;
        }

        return $report;
    }

    protected function ingestion(): DatasetIngestionService
    {
        return $this->app->make(DatasetIngestionService::class);
    }

    /**
     * Read the status back out of the database rather than off the in-memory
     * model: this is a state machine, so every assertion is about what the next
     * request (or the nightly sweep) will actually see.
     */
    protected function statusOf(Dataset $dataset): DatasetStatus
    {
        return $dataset->fresh()->status;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function dataset(array $attributes = []): Dataset
    {
        return Dataset::factory()->forUser($this->analyst)->create(['import_job_id' => 42, ...$attributes]);
    }

    protected function runQuality(Dataset $dataset): void
    {
        $this->ingestion()->runQuality($dataset);
    }

    /** @return list<string> */
    protected function auditActions(Dataset $dataset): array
    {
        return AuditLog::query()
            ->where('resource', 'dataset')
            ->where('resource_id', $dataset->getKey())
            ->orderBy('id')
            ->pluck('action')
            ->all();
    }

    protected function syncAuditCount(Dataset $dataset): int
    {
        return AuditLog::query()
            ->where('resource', 'dataset')
            ->where('resource_id', $dataset->getKey())
            ->where('action', 'dataset.status_synced')
            ->count();
    }

    /** @return array<string, mixed> */
    protected function auditDetail(Dataset $dataset, string $action): array
    {
        $log = AuditLog::query()
            ->where('resource', 'dataset')
            ->where('resource_id', $dataset->getKey())
            ->where('action', $action)
            ->firstOrFail();

        return (array) $log->detail;
    }

    /** @return array<string, callable(): TestResponse> */
    protected function webFailureActions(): array
    {
        return [
            'datasets.store' => fn (): TestResponse => $this->actingAs($this->analyst)
                ->from(route('datasets.create'))
                ->post(route('datasets.store'), [
                    'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal,qty\n"),
                    'dataset_type' => 'sales',
                ]),

            'datasets.preview' => function (): TestResponse {
                $dataset = $this->dataset();

                return $this->actingAs($this->analyst)
                    ->from(route('datasets.show', $dataset))
                    ->post(route('datasets.preview', $dataset));
            },

            'datasets.mapping' => function (): TestResponse {
                $dataset = $this->dataset();

                return $this->actingAs($this->analyst)
                    ->from(route('datasets.show', $dataset))
                    ->post(route('datasets.mapping', $dataset), [
                        'mappings' => ['tanggal' => 'transaction_date'],
                    ]);
            },

            'datasets.quality' => function (): TestResponse {
                $dataset = $this->dataset();

                return $this->actingAs($this->analyst)
                    ->from(route('datasets.show', $dataset))
                    ->post(route('datasets.quality', $dataset));
            },

            'datasets.commit' => function (): TestResponse {
                $dataset = $this->dataset(['status' => DatasetStatus::Mapped->value]);

                return $this->actingAs($this->analyst)
                    ->from(route('datasets.show', $dataset))
                    ->post(route('datasets.commit', $dataset), ['run_async' => true]);
            },
        ];
    }

    /** @return array<string, callable(): TestResponse> */
    protected function apiFailureActions(): array
    {
        return [
            'api.datasets.store' => fn (): TestResponse => $this->asApiUser()
                ->postJson(route('api.datasets.store'), [
                    'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal,qty\n"),
                    'dataset_type' => 'sales',
                ]),

            'api.datasets.quality' => function (): TestResponse {
                $dataset = $this->dataset();

                return $this->asApiUser()->getJson(route('api.datasets.quality', $dataset));
            },

            'api.datasets.mapping' => function (): TestResponse {
                $dataset = $this->dataset();

                return $this->asApiUser()->postJson(route('api.datasets.mapping', $dataset), [
                    'mappings' => ['tanggal' => 'transaction_date'],
                ]);
            },

            'api.datasets.commit' => function (): TestResponse {
                $dataset = $this->dataset(['status' => DatasetStatus::Mapped->value]);

                return $this->asApiUser()->postJson(route('api.datasets.commit', $dataset), [
                    'run_async' => true,
                ]);
            },
        ];
    }

    /**
     * Not named `withToken()`: that is a method on `MakesHttpRequests` with a
     * public signature, and redeclaring it as protected is a fatal error.
     */
    protected function asApiUser(): self
    {
        Sanctum::actingAs($this->analyst);

        return $this;
    }
}
