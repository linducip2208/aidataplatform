<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Jobs\RefreshQualityScoreJob;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The three operator commands. Two of them write to the datasets table on the
 * nightly schedule (`routes/console.php` runs `sync:import-status --limit=100`
 * at 02:15 and `sync:quality --limit=100 --days=30` at 02:45), so a silent
 * regression here corrupts the status mirror for every user without anyone
 * noticing until the UI is wrong.
 */
class OpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** Score the engine reports for `/imports/quality/{id}`. */
    protected float $qualityScore = 0.91;

    /** Status the engine reports for `/imports/jobs/{id}`. */
    protected string $jobStatus = 'succeeded';

    /** When true `/health` answers 503, i.e. the engine is unreachable. */
    protected bool $healthDown = false;

    /** @var array<int, int> import job id => HTTP status the engine fails with */
    protected array $failingJobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the two config values that would otherwise make `platform:doctor`
        // machine-dependent: APP_KEY is empty in a bare checkout, and
        // MAX_UPLOAD_MB is compared against the local php.ini upload limits,
        // which differ per host.
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'ai_engine.max_upload_mb' => 1,
        ]);

        $this->fakeEngine();
    }

    /**
     * A single closure stub rather than a URL map: stub callbacks are matched
     * first-registered-wins, so a second `Http::fake()` in a test could never
     * override a map registered in `setUp()`. Every per-test knob is read at
     * request time instead.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            // `/health` and `/readiness` are the only routes the engine answers
            // with a bare model: `AiEngineClient::decode()` deliberately skips
            // the {success, data} envelope for them. Faking the envelope here
            // would make the doctor read "status=unknown" and quietly downgrade
            // a healthy engine to a warning, so these two bypass the envelope.
            if (str_ends_with($url, '/health')) {
                return $this->healthDown
                    ? Http::response(
                        ['detail' => 'upstream refused the shared secret '.(string) config('ai_engine.service_key')],
                        503,
                    )
                    : Http::response(['status' => 'ok', 'version' => '1.4.0'], 200);
            }

            if (str_ends_with($url, '/readiness')) {
                return Http::response(['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']], 200);
            }

            $jobId = preg_match('#/imports/(?:jobs|quality)/(\d+)$#', $url, $matches) === 1
                ? (int) $matches[1]
                : null;

            if ($jobId !== null && isset($this->failingJobs[$jobId])) {
                return Http::response(
                    ['detail' => 'the engine blew up on import job '.$jobId],
                    $this->failingJobs[$jobId],
                );
            }

            $payload = match (true) {
                str_contains($url, '/imports/quality/') => $this->qualityReport(),
                str_contains($url, '/imports/jobs/') => ['status' => $this->jobStatus, 'total_rows' => 4210],
                str_ends_with($url, '/models') => [['id' => 1, 'name' => 'churn-classifier', 'status' => 'PRODUCTION']],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /** @return array<string, mixed> */
    protected function qualityReport(): array
    {
        return [
            'score' => $this->qualityScore,
            'breakdown' => [
                'completeness' => 0.97,
                'uniqueness' => 0.88,
                'validity' => 0.92,
                'consistency' => 0.87,
            ],
            'issues' => [],
            'passed' => $this->qualityScore >= (float) config('ai_engine.quality_threshold'),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function waitingDataset(int $jobId, array $attributes = []): Dataset
    {
        return Dataset::factory()->create([
            'status' => DatasetStatus::Importing->value,
            'import_job_id' => $jobId,
            ...$attributes,
        ]);
    }

    /**
     * A committed dataset that the nightly sweep would actually pick up: the
     * schedule runs `--days=30`, and the factory's own `committed()` state
     * stamps `quality_checked_at` within the last 96 hours, i.e. fresh.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function committedDataset(int $jobId, array $attributes = []): Dataset
    {
        return Dataset::factory()->committed()->create([
            'import_job_id' => $jobId,
            'quality_checked_at' => Carbon::now()->subDays(45),
            ...$attributes,
        ]);
    }

    /**
     * `routes/console.php` is loaded through a `booted` callback registered
     * when the console kernel is first resolved, so the kernel has to be
     * resolved before the schedule — otherwise `Schedule` is built empty.
     *
     * @return list<string>
     */
    protected function scheduledCommands(): array
    {
        $this->app->make(ConsoleKernel::class);

        $commands = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            /** @var ScheduleEvent $event */
            if (is_string($event->command)) {
                $commands[] = $event->command;
            }
        }

        return $commands;
    }

    // ------------------------------------------------------------------
    // platform:doctor
    // ------------------------------------------------------------------

    public function test_platform_doctor_exits_zero_and_reports_the_config_database_and_engine_checks(): void
    {
        $this->artisan('platform:doctor')
            ->expectsOutputToContain('configuration')
            ->expectsOutputToContain('ai engine')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('status=ok')
            ->expectsOutputToContain('0 fail')
            ->doesntExpectOutputToContain('FAIL')
            ->assertExitCode(0);
    }

    public function test_platform_doctor_exits_one_and_says_the_engine_is_unreachable_without_ever_printing_the_service_key(): void
    {
        $this->healthDown = true;

        $exitCode = Artisan::call('platform:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('the engine is unreachable', $output);
        $this->assertStringContainsString('[redacted]', $output);
        $this->assertStringNotContainsString((string) config('ai_engine.service_key'), $output);
    }

    public function test_platform_doctor_json_output_never_contains_the_service_key(): void
    {
        $this->healthDown = true;

        $exitCode = Artisan::call('platform:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString((string) config('ai_engine.service_key'), $output);

        /** @var array{status?: string, summary?: array<string, int>, components?: array<string, array{status?: string, detail?: string}>} $report */
        $report = json_decode($output, true);

        $this->assertIsArray($report);
        $this->assertSame('down', $report['status']);
        $this->assertArrayHasKey('engine_health', $report['components']);
        $this->assertSame('down', $report['components']['engine_health']['status']);
        $this->assertStringContainsString('the engine is unreachable', (string) $report['components']['engine_health']['detail']);

        // The healthy `service_key` check reports the length, never the secret.
        $this->assertSame('ok', $report['components']['service_key']['status']);
        $this->assertStringNotContainsString(
            (string) config('ai_engine.service_key'),
            (string) $report['components']['service_key']['detail'],
        );
    }

    public function test_platform_doctor_json_is_machine_readable_for_a_healthy_deployment(): void
    {
        $exitCode = Artisan::call('platform:doctor', ['--json' => true]);

        /** @var array{status?: string, summary?: array<string, int>, components?: array<string, mixed>, checked_at?: string} $report */
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertContains($report['status'], ['ok', 'warn']);
        $this->assertSame(0, $report['summary']['down']);
        $this->assertArrayHasKey('engine_auth', $report['components']);
        $this->assertNotEmpty((string) $report['checked_at']);
    }

    public function test_platform_doctor_degrades_the_postgres_only_checks_instead_of_failing_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $this->artisan('platform:doctor')
            ->expectsOutputToContain('skipped: the connection driver is sqlite, the engine schema lives in PostgreSQL')
            ->expectsOutputToContain('0 fail')
            ->doesntExpectOutputToContain('FAIL')
            ->assertExitCode(0);
    }

    public function test_the_nightly_schedule_uses_the_options_these_tests_cover(): void
    {
        $schedule = implode("\n", $this->scheduledCommands());

        // `Schedule::command()` stores the command prefixed with the resolved
        // php binary and artisan path, so the signature is matched as a suffix.
        $this->assertStringContainsString('sync:import-status --limit=100', $schedule);
        $this->assertStringContainsString('sync:quality --limit=100 --days=30', $schedule);
    }

    // ------------------------------------------------------------------
    // sync:import-status
    // ------------------------------------------------------------------

    public function test_sync_import_status_commits_a_dataset_whose_engine_job_succeeded(): void
    {
        $this->jobStatus = 'succeeded';

        $dataset = $this->waitingDataset(1001);

        // `expectsOutputToContain` is satisfied one substring per written line,
        // so a single contiguous slice of the MOVED line is asserted.
        $this->artisan('sync:import-status')
            ->expectsOutputToContain('importing -> committed')
            ->assertExitCode(0);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $fresh->status());
        $this->assertSame(4210, $fresh->row_count);
        $this->assertSame(
            ['status' => 'succeeded', 'total_rows' => 4210],
            $fresh->metadata['import_job'],
        );

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/jobs/1001'));
    }

    public function test_sync_import_status_marks_a_dataset_whose_engine_job_failed(): void
    {
        $this->jobStatus = 'failed';

        $dataset = $this->waitingDataset(1002);

        $this->artisan('sync:import-status')
            ->expectsOutputToContain('importing -> failed')
            ->assertExitCode(0);

        $this->assertSame(DatasetStatus::Failed, $dataset->fresh()->status());
    }

    /**
     * Regression guard. `syncStatus()` used to map every unrecognised engine
     * status to `importing`, so a job that had not started yet dragged a
     * `previewing` or `mapped` dataset out of the pre-commit wizard state that
     * `DatasetController` drives the UI from.
     */
    public function test_sync_import_status_does_not_drag_a_queued_job_back_over_previewing_or_mapped(): void
    {
        $this->jobStatus = 'queued';

        $previewing = $this->waitingDataset(1003, ['status' => DatasetStatus::Previewing->value]);
        $mapped = $this->waitingDataset(1004, [
            'status' => DatasetStatus::Mapped->value,
            'mappings' => ['qty' => 'quantity'],
        ]);

        // The HELD line only prints the status it kept, so the transition is
        // asserted through the rows and the absence of `importing` in the output.
        $this->assertSame(0, Artisan::call('sync:import-status'));

        $output = Artisan::output();

        $this->assertStringContainsString('HELD', $output);
        $this->assertStringContainsString($previewing->uuid, $output);
        $this->assertStringContainsString($mapped->uuid, $output);
        $this->assertStringNotContainsString('importing', $output);

        $this->assertSame(DatasetStatus::Previewing, $previewing->fresh()->status());
        $this->assertSame(DatasetStatus::Mapped, $mapped->fresh()->status());
    }

    /**
     * The same guard one layer down: `RefreshQualityScoreJob` is not involved
     * here, so a regression in `DatasetIngestionService::syncStatus()` itself
     * cannot be papered over by any caller.
     */
    public function test_sync_status_alone_leaves_the_pre_commit_wizard_states_alone(): void
    {
        $this->jobStatus = 'queued';

        $ingestion = app(DatasetIngestionService::class);

        foreach ([DatasetStatus::Previewing, DatasetStatus::Mapped, DatasetStatus::Uploaded] as $status) {
            $dataset = $this->waitingDataset(1005, ['status' => $status->value]);

            $ingestion->syncStatus($dataset);

            $this->assertSame(
                $status,
                $dataset->fresh()->status(),
                'syncStatus() clobbered a '.$status->value.' dataset with a queued engine job.',
            );
        }
    }

    public function test_sync_import_status_honours_the_limit_option(): void
    {
        $this->jobStatus = 'succeeded';

        $oldest = $this->waitingDataset(1101);
        $second = $this->waitingDataset(1102);
        $skipped = $this->waitingDataset(1103);

        foreach ([$oldest, $second, $skipped] as $index => $dataset) {
            Dataset::query()->whereKey($dataset->getKey())->update([
                'updated_at' => Carbon::now()->subMinutes(30 - $index),
            ]);
        }

        $this->artisan('sync:import-status', ['--limit' => 2])
            ->expectsOutputToContain('Reconciled 2 dataset(s): 2 moved, 0 unchanged, 0 failed.')
            ->assertExitCode(0);

        $this->assertSame(DatasetStatus::Committed, $oldest->fresh()->status());
        $this->assertSame(DatasetStatus::Committed, $second->fresh()->status());
        $this->assertSame(DatasetStatus::Importing, $skipped->fresh()->status());

        Http::assertNotSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/jobs/1103'));
    }

    public function test_sync_import_status_reports_the_backlog_left_by_a_truncated_run(): void
    {
        // A queued job holds every row in its current open status, so all three
        // stay pending and the "raise --limit" note has something to report.
        $this->jobStatus = 'queued';

        $this->waitingDataset(1151);
        $this->waitingDataset(1152);
        $this->waitingDataset(1153);

        $this->artisan('sync:import-status', ['--limit' => 2])
            ->expectsOutputToContain('Reconciled 2 dataset(s): 0 moved, 2 unchanged, 0 failed. 3 still pending; raise --limit or run again')
            ->assertExitCode(0);

        $this->assertSame(3, Dataset::query()->where('status', DatasetStatus::Importing->value)->count());
    }

    public function test_sync_import_status_dry_run_changes_nothing_in_the_database(): void
    {
        $this->jobStatus = 'succeeded';

        $dataset = $this->waitingDataset(1201);
        $before = $dataset->fresh();

        $this->artisan('sync:import-status', ['--dry-run' => true])
            ->expectsOutputToContain('importing -> ?')
            ->assertExitCode(0);

        $after = $dataset->fresh();

        $this->assertSame(DatasetStatus::Importing, $after->status());
        $this->assertSame($before->updated_at->format('Y-m-d H:i:s'), $after->updated_at->format('Y-m-d H:i:s'));
        $this->assertNull($after->metadata['import_job'] ?? null);

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/jobs/'));
    }

    public function test_sync_import_status_dataset_option_scopes_the_run_to_one_dataset(): void
    {
        $this->jobStatus = 'succeeded';

        $target = $this->waitingDataset(1301);
        $untouched = $this->waitingDataset(1302);

        $this->artisan('sync:import-status', ['--dataset' => $target->uuid])
            ->expectsOutputToContain($target->uuid)
            ->doesntExpectOutputToContain($untouched->uuid)
            ->assertExitCode(0);

        $this->assertSame(DatasetStatus::Committed, $target->fresh()->status());
        $this->assertSame(DatasetStatus::Importing, $untouched->fresh()->status());
    }

    public function test_sync_import_status_reports_an_unknown_dataset_uuid(): void
    {
        $this->artisan('sync:import-status', ['--dataset' => '11111111-2222-4333-8444-555555555555'])
            ->expectsOutputToContain('No dataset with uuid')
            ->assertExitCode(1);
    }

    public function test_sync_import_status_keeps_going_when_one_engine_call_fails(): void
    {
        $this->failingJobs = [1402 => 500];

        $before = $this->waitingDataset(1401);
        $broken = $this->waitingDataset(1402);
        $after = $this->waitingDataset(1403);

        $this->artisan('sync:import-status')
            ->expectsOutputToContain('ERROR')
            ->expectsOutputToContain('3 dataset(s): 2 moved, 0 unchanged, 1 failed')
            ->assertExitCode(1);

        $this->assertSame(DatasetStatus::Committed, $before->fresh()->status());
        $this->assertSame(DatasetStatus::Committed, $after->fresh()->status());

        // The one that failed must be left exactly as it was, not half-updated.
        $this->assertSame(DatasetStatus::Importing, $broken->fresh()->status());
        $this->assertNull($broken->fresh()->metadata['import_job'] ?? null);
    }

    public function test_sync_import_status_refuses_to_run_when_the_engine_probe_fails(): void
    {
        $this->healthDown = true;

        $dataset = $this->waitingDataset(1501);

        $this->artisan('sync:import-status')
            ->expectsOutputToContain('Cannot reach the AI engine')
            ->assertExitCode(1);

        $this->assertSame(DatasetStatus::Importing, $dataset->fresh()->status());

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/jobs/'));
    }

    // ------------------------------------------------------------------
    // sync:quality — the regression guards
    // ------------------------------------------------------------------

    /**
     * Regression guard, the load-bearing one. `runQuality()` is written for the
     * pre-commit step and used to end with `'status' => Uploaded` on a pass.
     * The nightly sweep runs it over committed rows, so that bug demoted every
     * committed dataset to `uploaded` on every single night.
     */
    public function test_sync_quality_keeps_a_committed_dataset_committed(): void
    {
        $dataset = $this->committedDataset(2001);

        $this->artisan('sync:quality', ['--dataset' => $dataset->uuid])
            ->expectsOutputToContain('verdict pass  status committed')
            ->assertExitCode(0);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Committed, $fresh->status());
        $this->assertSame(0.91, (float) $fresh->quality_score);
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertNotNull($fresh->quality_checked_at);
    }

    /**
     * The same guard with the job removed from the path. `RefreshQualityScoreJob
     * ::refresh()` has its own un-commit restore, so a regression in
     * `DatasetIngestionService::runQuality()` is only visible when the service
     * is called directly.
     */
    public function test_run_quality_alone_does_not_un_commit_a_committed_dataset(): void
    {
        $dataset = $this->committedDataset(2002);

        $result = app(DatasetIngestionService::class)->runQuality($dataset);

        $this->assertSame(0.91, (float) $result['score']);
        $this->assertSame(DatasetStatus::Committed, $dataset->fresh()->status());
    }

    /**
     * A quarantined dataset is terminal too: re-checking it must refresh the
     * score without quietly promoting it back into the warehouse mirror, even
     * when the engine now considers it a pass.
     */
    public function test_sync_quality_keeps_a_quarantined_dataset_quarantined_even_when_the_engine_now_passes_it(): void
    {
        $dataset = Dataset::factory()->quarantined()->create(['import_job_id' => 2003]);

        $this->artisan('sync:quality', ['--dataset' => $dataset->uuid])
            ->expectsOutputToContain('status quarantined')
            ->assertExitCode(0);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Quarantined, $fresh->status());
        $this->assertSame(0.91, (float) $fresh->quality_score);
        $this->assertSame('pass', $fresh->quality_verdict);
    }

    public function test_sync_quality_keeps_a_quarantined_dataset_quarantined_when_the_engine_fails_it_again(): void
    {
        $this->qualityScore = 0.21;

        $dataset = Dataset::factory()->quarantined()->create(['import_job_id' => 2004]);

        $this->artisan('sync:quality', ['--dataset' => $dataset->uuid])
            ->expectsOutputToContain('verdict quarantine')
            ->assertExitCode(0);

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Quarantined, $fresh->status());
        $this->assertSame(0.21, (float) $fresh->quality_score);
        $this->assertSame('quarantine', $fresh->quality_verdict);
    }

    /**
     * The counterpart of the guard above, so the terminal-preservation arm is
     * demonstrably the only thing keeping committed rows off `uploaded`: the
     * same code path still walks a non-terminal pre-commit row to `uploaded`.
     */
    public function test_run_quality_still_walks_a_non_terminal_pre_commit_dataset_back_to_uploaded(): void
    {
        $dataset = $this->waitingDataset(2005, ['status' => DatasetStatus::Mapped->value]);

        app(DatasetIngestionService::class)->runQuality($dataset);

        $this->assertSame(DatasetStatus::Uploaded, $dataset->fresh()->status());
        $this->assertSame(0.91, (float) $dataset->fresh()->quality_score);
    }

    public function test_sync_quality_dry_run_leaves_the_score_and_the_timestamp_untouched(): void
    {
        $dataset = $this->committedDataset(2101, [
            'quality_score' => 0.5,
            'quality_checked_at' => Carbon::now()->subDays(90),
        ]);

        $frozen = $dataset->fresh()->quality_checked_at;

        $this->artisan('sync:quality', ['--dataset' => $dataset->uuid, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $fresh = $dataset->fresh();

        $this->assertSame(0.5, (float) $fresh->quality_score);
        $this->assertTrue($frozen->equalTo($fresh->quality_checked_at));
        $this->assertTrue(
            $fresh->quality_checked_at->lessThan(Carbon::now()->subDays(89)),
            'quality_checked_at was refreshed by a --dry-run.',
        );
        $this->assertSame(DatasetStatus::Committed, $fresh->status());

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/quality/'));
    }

    public function test_sync_quality_days_window_skips_a_fresh_dataset_and_picks_up_a_stale_one(): void
    {
        $fresh = $this->committedDataset(2201, [
            'quality_score' => 0.77,
            'quality_checked_at' => Carbon::now(),
        ]);

        $stale = $this->committedDataset(2202, [
            'quality_score' => 0.61,
            'quality_checked_at' => Carbon::now()->subDays(45),
        ]);

        $this->artisan('sync:quality', ['--days' => 30])
            ->expectsOutputToContain($stale->uuid)
            ->doesntExpectOutputToContain($fresh->uuid)
            ->assertExitCode(0);

        $this->assertSame(0.91, (float) $stale->fresh()->quality_score);
        $this->assertSame(0.77, (float) $fresh->fresh()->quality_score);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/quality/2202'));
        Http::assertNotSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/quality/2201'));
    }

    public function test_sync_quality_force_ignores_the_staleness_window(): void
    {
        $dataset = $this->committedDataset(2203, ['quality_checked_at' => Carbon::now()]);

        $this->artisan('sync:quality', ['--force' => true])
            ->expectsOutputToContain($dataset->uuid)
            ->assertExitCode(0);

        $this->assertSame(0.91, (float) $dataset->fresh()->quality_score);
    }

    public function test_sync_quality_writes_the_quality_audit_row(): void
    {
        $dataset = $this->committedDataset(2301);

        $this->artisan('sync:quality', ['--dataset' => $dataset->uuid])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dataset.quality_checked',
            'resource' => 'dataset',
            'resource_id' => $dataset->getKey(),
        ]);

        $log = AuditLog::query()
            ->where('action', 'dataset.quality_checked')
            ->where('resource_id', $dataset->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('system', $log->actor);
        $this->assertSame(0.91, (float) $log->detail['score']);
        $this->assertSame('pass', $log->detail['verdict']);
        $this->assertSame(0.75, (float) $log->detail['threshold']);
    }

    public function test_sync_quality_keeps_going_when_one_engine_call_fails_and_exits_non_zero(): void
    {
        $this->failingJobs = [2402 => 500];

        $before = $this->committedDataset(2401);
        $broken = $this->committedDataset(2402, [
            'quality_score' => 0.44,
            'quality_checked_at' => Carbon::now()->subDays(120),
        ]);
        $after = $this->committedDataset(2403);

        $this->artisan('sync:quality')
            ->expectsOutputToContain('ERROR')
            ->expectsOutputToContain('3 candidate(s), 2 refreshed, 0 quarantined, 0 dispatched, 1 failed')
            ->assertExitCode(1);

        $this->assertSame(0.91, (float) $before->fresh()->quality_score);
        $this->assertSame(0.91, (float) $after->fresh()->quality_score);

        // A failed call must not write a 0.0, which would read as "this dataset
        // failed quality" rather than "we could not ask".
        $brokenFresh = $broken->fresh();
        $this->assertSame(0.44, (float) $brokenFresh->quality_score);
        $this->assertTrue($brokenFresh->quality_checked_at->lessThan(Carbon::now()->subDays(119)));
        $this->assertSame(DatasetStatus::Committed, $brokenFresh->status());
    }

    public function test_sync_quality_queue_option_dispatches_one_job_per_dataset_instead_of_calling_the_engine(): void
    {
        Queue::fake();

        $first = $this->committedDataset(2501, ['quality_score' => 0.7]);
        $second = $this->committedDataset(2502, ['quality_score' => 0.7]);

        $this->artisan('sync:quality', ['--queue' => true])
            ->expectsOutputToContain('QUEUED')
            ->expectsOutputToContain('2 candidate(s), 0 refreshed, 0 quarantined, 2 dispatched, 0 failed')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshQualityScoreJob::class, 2);
        Queue::assertPushedOn('datasets', RefreshQualityScoreJob::class);
        Queue::assertPushed(
            RefreshQualityScoreJob::class,
            fn (RefreshQualityScoreJob $job): bool => $job->datasetUuid === $first->uuid,
        );
        Queue::assertPushed(
            RefreshQualityScoreJob::class,
            fn (RefreshQualityScoreJob $job): bool => $job->datasetUuid === $second->uuid,
        );

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/quality/'));
        $this->assertSame(0.7, (float) $first->fresh()->quality_score);
        $this->assertSame(0.7, (float) $second->fresh()->quality_score);
    }

    public function test_sync_quality_is_a_no_op_when_every_committed_dataset_is_fresh(): void
    {
        $dataset = $this->committedDataset(2601, [
            'quality_score' => 0.8,
            'quality_checked_at' => Carbon::now(),
        ]);

        $this->artisan('sync:quality')
            ->expectsOutputToContain('Every committed dataset has a fresh quality score. Nothing to re-check.')
            ->assertExitCode(0);

        $this->assertSame(0.8, (float) $dataset->fresh()->quality_score);

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/quality/'));
    }

    public function test_sync_quality_refuses_to_run_when_the_engine_probe_fails(): void
    {
        $this->healthDown = true;

        $dataset = $this->committedDataset(2701, [
            'quality_score' => 0.5,
            'quality_checked_at' => Carbon::now()->subDays(90),
        ]);

        $this->artisan('sync:quality')
            ->expectsOutputToContain('Cannot reach the AI engine')
            ->assertExitCode(1);

        $this->assertSame(0.5, (float) $dataset->fresh()->quality_score);

        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/quality/'));
    }

    public function test_sync_quality_reports_an_unknown_dataset_uuid(): void
    {
        $this->artisan('sync:quality', ['--dataset' => '11111111-2222-4333-8444-555555555555'])
            ->expectsOutputToContain('No dataset with uuid')
            ->assertExitCode(1);
    }
}
