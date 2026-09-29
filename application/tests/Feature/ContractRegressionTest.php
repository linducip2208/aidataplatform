<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\User;
use App\Services\AiEngineClient;
use App\Services\DatasetIngestionService;
use App\Support\ApiResponse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\EngineFakes;
use Tests\Fixtures\EngineFakeState;
use Tests\TestCase;

/**
 * Cross-layer regression pins for contracts other suites exercise but none
 * owns: the engine envelope shape, the quality-threshold fallback, the
 * terminal-status guarantee on re-check, the retry-on-5xx policy, the upload
 * multipart shape, and the audit rows the main flows must leave behind.
 *
 * Existing surface ONLY: every route, method and status asserted here ships
 * today. Nothing here depends on code other agents are adding concurrently;
 * the engine is faked at the HTTP boundary (Tests\Fixtures\EngineFakes) and
 * the one Laravel route this file needs beyond the shipped ones is defined
 * inline (`/_qa/envelope`) so no wiring change can break it.
 */
class ContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected EngineFakeState $engine;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai_engine.quality_threshold' => 0.75]);

        Storage::fake('local');

        $this->engine = EngineFakes::install();
        $this->analyst = User::factory()->analyst()->create();
    }

    protected function client(): AiEngineClient
    {
        return AiEngineClient::fromConfig();
    }

    protected function ingestion(): DatasetIngestionService
    {
        return $this->app->make(DatasetIngestionService::class);
    }

    /** @param array<string, mixed> $attributes */
    protected function dataset(array $attributes = []): Dataset
    {
        return Dataset::factory()->create(['import_job_id' => 42, ...$attributes]);
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

    // ------------------------------------------------------------------
    // engine envelope shape assumptions
    // ------------------------------------------------------------------

    public function test_a_success_envelope_unwraps_to_its_data_key(): void
    {
        $report = $this->client()->runQuality(42);

        $this->assertSame(0.91, $report['score']);
        $this->assertArrayHasKey('breakdown', $report);
        $this->assertArrayHasKey('issues', $report);
        $this->assertArrayHasKey('passed', $report);
    }

    public function test_a_failure_envelope_becomes_an_exception_carrying_the_engine_message(): void
    {
        $this->engine->failingJobs = [42 => 500];

        try {
            $this->client()->runQuality(42);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(500, $exception->upstreamStatus());
            $this->assertSame(502, $exception->statusForClient());
        }
    }

    public function test_health_returns_the_bare_body_and_not_an_envelope(): void
    {
        // /health answers with a bare model; unwrap() must never see it.
        $this->assertSame(
            ['status' => 'ok', 'version' => '1.4.0'],
            $this->client()->health(),
        );
    }

    public function test_the_laravel_envelope_is_data_shaped_and_not_engine_shaped(): void
    {
        // Self-contained route: this pins App\Support\ApiResponse, not the
        // router, so no other agent's wiring can move it.
        Route::get('/_qa/envelope', fn () => ApiResponse::data(['ok' => true]));

        $body = $this->getJson('/_qa/envelope')->assertOk()->json();

        $this->assertArrayHasKey('data', $body);
        $this->assertSame(['ok' => true], $body['data']);
        $this->assertArrayNotHasKey(
            'success',
            $body,
            'Laravel answers {data: ...}; the {success, data} envelope belongs to the engine. Mixing them breaks both clients.',
        );
    }

    // ------------------------------------------------------------------
    // quality threshold propagation
    // ------------------------------------------------------------------

    public function test_the_laravel_threshold_decides_when_the_engine_sends_no_passed_flag(): void
    {
        // EngineFakes computes `passed` from config by default; omitting the
        // key exercises the runQuality() fallback instead.
        $this->engine->omitPassedFlag = true;
        $this->engine->qualityScore = 0.70;

        $dataset = $this->dataset();

        $result = $this->ingestion()->runQuality($dataset);

        $this->assertSame(0.70, $result['score']);
        $this->assertSame(0.75, $result['threshold']);
        $this->assertSame(DatasetStatus::Quarantined, $dataset->fresh()->status());
        $this->assertSame('quarantine', $dataset->fresh()->quality_verdict);

        $detail = AuditLog::query()
            ->where('action', 'dataset.quality_checked')
            ->firstOrFail()->detail;

        $this->assertSame(0.75, (float) $detail['threshold']);
    }

    public function test_the_engine_passed_flag_wins_over_the_laravel_threshold(): void
    {
        // A 0.70 against Laravel's 0.75 would quarantine on the fallback, but
        // an explicit engine verdict is preferred when present.
        $this->engine->qualityScore = 0.70;
        $this->engine->enginePassed = true;

        $dataset = $this->dataset();

        $this->ingestion()->runQuality($dataset);

        $this->assertSame('pass', $dataset->fresh()->quality_verdict);
    }

    // ------------------------------------------------------------------
    // terminal-status preservation on re-check
    // ------------------------------------------------------------------

    public function test_a_committed_dataset_stays_committed_on_a_passing_recheck(): void
    {
        $this->engine->qualityScore = 0.93;

        $dataset = Dataset::factory()->committed()->create(['import_job_id' => 42]);
        $committedAt = $dataset->committed_at;

        $this->ingestion()->runQuality($dataset);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $fresh->status());
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertSame(0.93, (float) $fresh->quality_score);
        $this->assertTrue($committedAt->equalTo($fresh->committed_at));
    }

    public function test_a_committed_dataset_stays_committed_on_a_failing_recheck(): void
    {
        // Current runQuality contract: a committed row's data is already in
        // the warehouse, so a failing re-check records the verdict but must
        // not quarantine (let alone un-commit) the row.
        $this->engine->qualityScore = 0.31;

        $dataset = Dataset::factory()->committed()->create(['import_job_id' => 42]);
        $committedAt = $dataset->committed_at;

        $this->ingestion()->runQuality($dataset);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $fresh->status());
        $this->assertSame('quarantine', $fresh->quality_verdict);
        $this->assertTrue($committedAt->equalTo($fresh->committed_at));
    }

    // ------------------------------------------------------------------
    // retry-on-5xx
    // ------------------------------------------------------------------

    public function test_a_5xx_is_retried_but_a_422_is_sent_exactly_once(): void
    {
        $this->engine->failingJobs = [7 => 500, 8 => 422];

        try {
            $this->client()->runQuality(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(500, $exception->upstreamStatus());
        }

        try {
            $this->client()->runQuality(8);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException) {
            // The 422 path is asserted by its call count below.
        }

        $this->assertSame(
            2,
            EngineFakes::callsTo('/imports/quality/7'),
            'A 5xx must be attempted exactly twice: 1 try + the 1 retry AiEngineClient::http() configures with retry(2, 250).',
        );
        $this->assertSame(
            1,
            EngineFakes::callsTo('/imports/quality/8'),
            'A 422 must never be retried: re-sending a rejected payload doubles every validation failure.',
        );
    }

    // ------------------------------------------------------------------
    // upload multipart shape
    // ------------------------------------------------------------------

    public function test_the_upload_reaches_the_engine_as_multipart_with_the_dataset_type(): void
    {
        $result = $this->client()->uploadFile(
            UploadedFile::fake()->create('penjualan.csv', 8, 'text/csv'),
            'sales',
        );

        $this->assertSame(42, $result['import_job_id']);

        $matched = false;

        foreach (Http::recorded() as [$request]) {
            /** @var ClientRequest $request */
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/imports/upload')) {
                continue;
            }

            $this->assertStringContainsString('multipart/form-data', (string) $request->header('Content-Type')[0]);
            $this->assertStringContainsString('penjualan.csv', $request->body());
            // ClientRequest::data() only decodes url-encoded/JSON bodies, so
            // the dataset_type field is asserted against the raw multipart body.
            $this->assertStringContainsString('name="dataset_type"', $request->body());
            $this->assertStringContainsString('sales', $request->body());
            $matched = true;
        }

        $this->assertTrue($matched, 'No multipart POST to /imports/upload was recorded.');
    }

    // ------------------------------------------------------------------
    // audit rows for the main flows
    // ------------------------------------------------------------------

    public function test_the_full_wizard_leaves_one_audit_row_per_transition(): void
    {
        $this->engine->jobStatus = 'succeeded';

        $this->actingAs($this->analyst)
            ->post(route('datasets.store'), [
                'file' => UploadedFile::fake()->createWithContent('penjualan.csv', "tanggal,qty\n2026-01-05,4\n"),
                'dataset_type' => 'sales',
            ])
            ->assertRedirect();

        $dataset = Dataset::query()->sole();

        $this->actingAs($this->analyst)->post(route('datasets.preview', $dataset))->assertRedirect();
        $this->actingAs($this->analyst)->post(route('datasets.mapping', $dataset), [
            'mappings' => ['tanggal' => 'transaction_date', 'qty' => 'quantity'],
        ])->assertRedirect();
        $this->actingAs($this->analyst)->post(route('datasets.quality', $dataset))->assertRedirect();
        $this->actingAs($this->analyst)->post(route('datasets.commit', $dataset), ['run_async' => true])->assertRedirect();

        $this->ingestion()->syncStatus($dataset->fresh());

        $this->assertSame(DatasetStatus::Committed, $dataset->fresh()->status());
        $this->assertSame([
            'dataset.uploaded',
            'dataset.mapping_applied',
            'dataset.quality_checked',
            'dataset.committed',
            'dataset.status_synced',
        ], $this->auditActions($dataset));

        // Every row carries the acting user and a request IP.
        foreach (AuditLog::query()->where('resource', 'dataset')->get() as $log) {
            $this->assertSame($this->analyst->getKey(), $log->user_id);
            $this->assertSame($this->analyst->email, $log->actor);
            $this->assertNotEmpty($log->ip);
        }
    }

    // ------------------------------------------------------------------
    // import status mirror (terminal engine outcomes)
    // ------------------------------------------------------------------

    public function test_terminal_engine_outcomes_mirror_to_terminal_dataset_states(): void
    {
        $service = $this->ingestion();

        $this->engine->jobStatus = 'succeeded';
        $done = $this->dataset(['status' => DatasetStatus::Importing->value]);
        $service->syncStatus($done);
        $this->assertSame(DatasetStatus::Committed, $done->fresh()->status());

        $this->engine->jobStatus = 'failed';
        $failed = $this->dataset(['status' => DatasetStatus::Importing->value]);
        $service->syncStatus($failed);
        $this->assertSame(DatasetStatus::Failed, $failed->fresh()->status());

        // Terminal rows are never walked back by a later in-flight poll.
        $this->engine->jobStatus = 'queued';
        $service->syncStatus($failed);
        $this->assertSame(DatasetStatus::Failed, $failed->fresh()->status());
    }

    // ------------------------------------------------------------------
    // performance smoke: dataset list with 50 rows
    // ------------------------------------------------------------------

    /**
     * Lightweight perf smoke, not a benchmark: 50 datasets must render the
     * index inside a flat query budget (an N+1 would issue ~50 statements
     * here) and well inside 30s. Uses the QueryCountTest listener pattern —
     * the listener is attached AFTER seeding so setup queries are not counted.
     */
    public function test_dataset_list_renders_50_rows_within_a_flat_query_budget(): void
    {
        $admin = User::factory()->admin()->create();

        Dataset::factory()->count(50)->create(['user_id' => $admin->getKey()]);

        $queries = [];

        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $started = microtime(true);

        $response = $this->actingAs($admin)->get(route('datasets.index'));

        $elapsed = microtime(true) - $started;

        $response->assertOk();

        // The flat count must not be flat because the data went missing.
        $this->assertSame(50, $response->viewData('datasets')->total());

        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'datasets.index issued '.count($queries).' statements for 50 rows (measured 2: COUNT + page SELECT); anything near 50 is an N+1.'."\n"
            .implode("\n", $queries),
        );
        $this->assertLessThan(30, $elapsed, 'datasets.index took '.round($elapsed, 2).'s for 50 rows.');
    }
}
