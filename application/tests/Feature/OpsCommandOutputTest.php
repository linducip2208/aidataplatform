<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Jobs\RefreshQualityScoreJob;
use App\Models\Dataset;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What the operator actually reads at 02:15: the printed report and the exit
 * code. `OpsCommandsTest` covers what the commands do to the database and
 * `OpsJobsTest` covers the job in isolation, so nothing here re-asserts a
 * status transition or an audit row — every assertion below is about a line on
 * screen or an integer in `$?`.
 *
 * The contract:
 *  - the exit code alone is enough to script on. A command that exits 0 while
 *    something inside it failed is worse than one that crashes, because cron
 *    and every monitor built on it read the code and nothing else;
 *  - a count on screen is a count of something real. A dry run has no
 *    evidence about the state of a row it never asked the engine about;
 *  - a check that cannot run says so. Silence reads as a pass;
 *  - the shared service key never reaches a terminal, a log or a JSON body.
 */
class OpsCommandOutputTest extends TestCase
{
    use RefreshDatabase;

    /** Score the engine reports for `/imports/quality/{id}` unless overridden. */
    protected float $qualityScore = 0.91;

    /** Status the engine reports for `/imports/jobs/{id}`. */
    protected string $jobStatus = 'succeeded';

    /** When true `/health` answers 503, i.e. the engine is unreachable. */
    protected bool $healthDown = false;

    /** Overrides the `/readiness` body so a failing dependency can be described. */
    protected ?array $readiness = null;

    /** When set, `/models` answers with this HTTP status (a key rejection). */
    protected ?int $modelsStatus = null;

    /** The error body `/models` returns alongside a rejection. */
    protected string $modelsDetail = 'service key rejected';

    /** @var array<int, int> import job id => HTTP status the engine fails with */
    protected array $failingJobs = [];

    /** @var array<int, float> import job id => quality score the engine reports */
    protected array $qualityScores = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the values that would otherwise make `platform:doctor`
        // machine-dependent: APP_KEY is empty in a bare checkout, and
        // MAX_UPLOAD_MB is compared against the local php.ini upload limits.
        $this->pinConfiguration();

        $this->fakeEngine();
    }

    protected function pinConfiguration(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'ai_engine.max_upload_mb' => 1,
            'ai_engine.quality_threshold' => 0.75,
            'ai_engine.service_key' => 'test-service-key',
        ]);
    }

    /**
     * A single closure stub rather than a URL map: stub callbacks are matched
     * first-registered-wins, so a second `Http::fake()` in a test could never
     * override a stub registered in `setUp()`. Every knob is read at request
     * time instead, which is what makes per-test variation possible.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            // `/health` and `/readiness` answer with a bare model rather than
            // the {success, data} envelope `AiEngineClient::unwrap()` expects, so
            // faking the envelope here would make the doctor read a healthy
            // engine as "status=unknown" and downgrade it to a warning.
            if (str_ends_with($url, '/health')) {
                return $this->healthDown
                    ? Http::response(['detail' => 'the fastapi container is restarting'], 503)
                    : Http::response(['status' => 'ok', 'version' => '1.4.0'], 200);
            }

            if (str_ends_with($url, '/readiness')) {
                return Http::response(
                    $this->readiness ?? ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
                    200,
                );
            }

            if (str_ends_with($url, '/models')) {
                return $this->modelsStatus === null
                    ? Http::response(['success' => true, 'data' => [['id' => 1, 'name' => 'churn-classifier']]], 200)
                    : Http::response(['detail' => $this->modelsDetail], $this->modelsStatus);
            }

            $jobId = preg_match('#/imports/(?:jobs|quality)/(\d+)$#', $url, $matches) === 1
                ? (int) $matches[1]
                : null;

            if ($jobId !== null && isset($this->failingJobs[$jobId])) {
                return Http::response(['detail' => 'the engine blew up on import job '.$jobId], $this->failingJobs[$jobId]);
            }

            $score = $this->qualityScores[$jobId] ?? $this->qualityScore;

            $payload = match (true) {
                str_contains($url, '/imports/quality/') => [
                    'score' => $score,
                    'breakdown' => [
                        'completeness' => 0.97,
                        'uniqueness' => 0.88,
                        'validity' => 0.92,
                        'consistency' => 0.87,
                    ],
                    'issues' => [],
                    'passed' => $score >= (float) config('ai_engine.quality_threshold'),
                ],
                str_contains($url, '/imports/jobs/') => ['status' => $this->jobStatus, 'total_rows' => 4210],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /**
     * A dataset the nightly status sweep would pick up: an open status with an
     * import job behind it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function waitingDataset(int $jobId, array $attributes = []): Dataset
    {
        return Dataset::factory()->importing()->create([
            'import_job_id' => $jobId,
            ...$attributes,
        ]);
    }

    /**
     * A committed dataset the nightly quality sweep would pick up at
     * `--days=30`: the factory's own `committed()` state stamps a check within
     * the last 96 hours, so the window has to be pushed back explicitly.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function staleCommittedDataset(int $jobId, array $attributes = []): Dataset
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
     * @return list<ScheduleEvent>
     */
    protected function scheduledEvents(): array
    {
        $this->app->make(ConsoleKernel::class);

        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            /** @var ScheduleEvent $event */
            if (is_string($event->command)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<ScheduleEvent>
     */
    protected function eventsRunning(string $needle): array
    {
        return array_values(array_filter(
            $this->scheduledEvents(),
            static fn (ScheduleEvent $event): bool => str_contains((string) $event->command, $needle),
        ));
    }

    /**
     * The tagged lines `PlatformDoctorCommand::render()` prints, counted per
     * status. Anchored on the line prefix so prose that happens to contain
     * "FAIL" is not counted: this measures the report, not the wording.
     *
     * @return array{ok: int, warn: int, down: int}
     */
    protected function tallyTags(string $output): array
    {
        preg_match_all('/^\s{2}(PASS|WARN|FAIL)\s{2}\S/m', $output, $matches);

        $tags = $matches[1] ?? [];

        return [
            'ok' => count(array_filter($tags, static fn (string $tag): bool => $tag === 'PASS')),
            'warn' => count(array_filter($tags, static fn (string $tag): bool => $tag === 'WARN')),
            'down' => count(array_filter($tags, static fn (string $tag): bool => $tag === 'FAIL')),
        ];
    }

    /**
     * @return array{status?: string, summary?: array<string, int>, components?: array<string, array{status?: string, detail?: string, remedy?: string|null}>, checked_at?: string}
     */
    protected function doctorJson(string $output): array
    {
        $decoded = json_decode($output, true);

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'platform:doctor --json did not emit valid JSON: '.json_last_error_msg().' in: '.$output,
        );

        $this->assertIsArray($decoded);

        /** @var array{status?: string, summary?: array<string, int>, components?: array<string, array{status?: string, detail?: string, remedy?: string|null}>, checked_at?: string} $decoded */
        return $decoded;
    }

    protected function engineCallsTo(string $path): int
    {
        return Http::recorded(
            static fn (ClientRequest $request): bool => str_contains($request->url(), $path),
        )->count();
    }

    // ------------------------------------------------------------------
    // platform:doctor — exit codes
    // ------------------------------------------------------------------

    public function test_platform_doctor_exits_zero_when_no_check_failed(): void
    {
        $exitCode = Artisan::call('platform:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, 'A deployment with no failed check must exit 0.');
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertMatchesRegularExpression('/\d+ ok, \d+ warn, 0 fail/', $output);
    }

    /**
     * A warning is a degraded deployment, not a broken one: the code stays 0 so
     * a monitor does not page, but the operator is told to look. This is the
     * state the sqlite-only checks produce, and the one a config drift such as
     * `APP_ENV=staging` produces on Postgres.
     */
    public function test_platform_doctor_exits_zero_but_warns_loudly_when_a_check_is_only_degraded(): void
    {
        config(['app.env' => 'staging']);

        $exitCode = Artisan::call('platform:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('APP_ENV=staging', $output);
        $this->assertStringContainsString('remedy: set APP_ENV=production once the deployment is live', $output);
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertMatchesRegularExpression('/\d+ ok, \d+ warn, 0 fail/', $output);
        $this->assertStringContainsString('Usable with warnings.', $output);
    }

    /**
     * The load-bearing exit-code case. `platform:doctor` is the one command an
     * operator is guaranteed to read the code of, and a broken shared secret is
     * the failure it exists to catch: non-zero exit, the name of the check that
     * failed, and the remedy.
     */
    public function test_platform_doctor_exits_one_and_names_the_failed_check_and_its_remedy(): void
    {
        config(['ai_engine.service_key' => '']);

        $exitCode = Artisan::call('platform:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'An empty SERVICE_API_KEY must not exit 0.');
        $this->assertStringContainsString('service_key', $output);
        $this->assertStringContainsString('SERVICE_API_KEY is empty', $output);
        $this->assertStringContainsString(
            'remedy: set SERVICE_API_KEY identically in application/.env and ai-engine/.env, then restart both services',
            $output,
        );
        $this->assertStringContainsString('The deployment is not sane.', $output);
        $this->assertMatchesRegularExpression('/\d+ ok, \d+ warn, [1-9]\d* fail/', $output);
    }

    // ------------------------------------------------------------------
    // platform:doctor — both reports have to tell the same story
    // ------------------------------------------------------------------

    public function test_platform_doctor_json_carries_the_same_verdict_as_the_text_report_in_all_three_states(): void
    {
        $states = [
            'healthy' => [[], 0, 'warn', 'Usable with warnings.'],
            'degraded' => [['app.env' => 'staging'], 0, 'warn', 'Usable with warnings.'],
            'broken' => [['ai_engine.service_key' => ''], 1, 'down', 'The deployment is not sane.'],
        ];

        foreach ($states as $label => [$overrides, $expectedExit, $expectedStatus, $expectedVerdict]) {
            $this->pinConfiguration();
            config($overrides);

            $textExit = Artisan::call('platform:doctor');
            $text = Artisan::output();

            $jsonExit = Artisan::call('platform:doctor', ['--json' => true]);
            $report = $this->doctorJson(Artisan::output());

            $this->assertSame($expectedExit, $textExit, $label.': text report exit code');
            $this->assertSame($expectedExit, $jsonExit, $label.': --json must not disagree with the text report');
            $this->assertSame($expectedStatus, $report['status'] ?? null, $label.': json status');
            $this->assertStringContainsString($expectedVerdict, $text, $label.': verdict sentence');
            $this->assertNotEmpty((string) ($report['checked_at'] ?? ''), $label.': checked_at');

            // The summary has to be the tally the operator reads on screen,
            // otherwise a monitor and a human disagree about the same run.
            $tags = $this->tallyTags($text);

            $this->assertSame($report['summary']['down'] ?? -1, $tags['down'], $label.': failed count');
            $this->assertSame($report['summary']['warn'] ?? -1, $tags['warn'], $label.': warning count');
            $this->assertSame($report['summary']['ok'] ?? -1, $tags['ok'], $label.': passing count');

            // The code follows the failed count and nothing else.
            $this->assertSame(
                $expectedExit,
                $tags['down'] > 0 ? 1 : 0,
                $label.': the exit code must follow the failed count.',
            );
        }
    }

    // ------------------------------------------------------------------
    // platform:doctor — the service key never reaches a terminal
    // ------------------------------------------------------------------

    public function test_platform_doctor_redacts_the_service_key_from_a_rejection_that_embeds_a_connection_string(): void
    {
        $key = 'svc-9f31c0b7-REJECTED-4ad2';

        config(['ai_engine.service_key' => $key]);

        // The rejection arrives on the authenticated probe, and a real engine
        // quotes the DSN it tried, password included.
        $this->modelsStatus = 403;
        $this->modelsDetail = 'could not authenticate against postgresql://aida:'.$key.'@postgres:5432/aida';

        $textExit = Artisan::call('platform:doctor');
        $text = Artisan::output();

        $jsonExit = Artisan::call('platform:doctor', ['--json' => true]);
        $json = Artisan::output();

        $this->assertSame(1, $textExit);
        $this->assertSame(1, $jsonExit);
        $this->assertStringContainsString('SERVICE_API_KEY does not match', $text);
        $this->assertStringContainsString('[redacted]', $text);
        $this->assertStringNotContainsString($key, $text, 'The text report printed SERVICE_API_KEY verbatim.');
        $this->assertStringNotContainsString($key, $json, 'The JSON report printed SERVICE_API_KEY verbatim.');
    }

    /**
     * The same guarantee on the readiness path.
     *
     * A "not ready" engine describes its failing dependency, and a database
     * dependency's failure text is routinely a DSN — password included. The
     * doctor redacts its own messages through `PlatformHealth::redact()`, but
     * that redaction covers the exception and envelope paths; the readiness
     * summary is assembled from the engine's own `checks` map and printed as-is.
     */
    public function test_platform_doctor_never_prints_the_service_key_when_the_readiness_probe_describes_a_failing_connection(): void
    {
        $key = 'k9-READINESS-SECRET-2f4a';

        config(['ai_engine.service_key' => $key]);

        $this->readiness = [
            'ready' => false,
            'checks' => ['db' => 'down: postgresql://aida:'.$key.'@db:5432/aida'],
        ];

        $exitCode = Artisan::call('platform:doctor');
        $output = Artisan::output();

        $this->assertStringContainsString('engine_readiness', $output);
        $this->assertStringContainsString('not ready', $output);

        // A "not ready" engine is only a warning, so the code is 0 and nothing
        // that scripts on `$?` would ever surface this line.
        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(
            $key,
            $output,
            'The text report printed SERVICE_API_KEY verbatim in the readiness summary.',
        );
        $this->assertStringContainsString('[redacted]', $output);

        $jsonExit = Artisan::call('platform:doctor', ['--json' => true]);
        $this->assertSame(0, $jsonExit);
        $this->assertStringNotContainsString(
            $key,
            Artisan::output(),
            'The JSON report printed SERVICE_API_KEY verbatim in the readiness summary.',
        );
    }

    // ------------------------------------------------------------------
    // platform:doctor — a check that cannot run must say so
    // ------------------------------------------------------------------

    public function test_platform_doctor_says_it_skipped_the_postgres_only_checks_on_sqlite_instead_of_passing_them_silently(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $exitCode = Artisan::call('platform:doctor');
        $text = Artisan::output();

        $this->assertSame(0, $exitCode, 'The engine schema lives in Postgres; sqlite must not read as a broken deployment.');

        // Both the extensions check and the engine-tables check have to say it,
        // each in its own right, on its own line.
        $this->assertSame(
            2,
            substr_count($text, 'skipped: the connection driver is sqlite, the engine schema lives in PostgreSQL'),
            'Every check that did not run must say so; a check that cannot run silently is a check that lies.',
        );
        $this->assertStringNotContainsString('FAIL', $text);

        Artisan::call('platform:doctor', ['--json' => true]);
        $report = $this->doctorJson(Artisan::output());

        foreach (['database_extensions', 'engine_tables'] as $check) {
            $this->assertSame('warn', $report['components'][$check]['status'] ?? null, $check.' must be a warning: neither a pass nor a failure.');
            $this->assertStringStartsWith(
                'skipped: the connection driver is sqlite',
                (string) ($report['components'][$check]['detail'] ?? ''),
                $check.' must state that it did not run.',
            );
        }

        $this->assertSame(0, $report['summary']['down'] ?? -1);
    }

    // ------------------------------------------------------------------
    // sync:import-status — exit codes and counts
    // ------------------------------------------------------------------

    public function test_sync_import_status_exits_zero_and_counts_every_dataset_it_moved(): void
    {
        foreach ([3101, 3102, 3103] as $jobId) {
            $this->waitingDataset($jobId);
        }

        $exitCode = Artisan::call('sync:import-status');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Reconciled 3 dataset(s): 3 moved, 0 unchanged, 0 failed.', $output);
        $this->assertSame(3, preg_match_all('/^\s+MOVED\s+\S+/m', $output));

        $this->assertSame(0, Dataset::query()->where('status', DatasetStatus::Importing->value)->count());
    }

    /**
     * The state a monitoring rule most needs: nothing at all was reconciled.
     * Two of three is a "page me"; zero of three is "the whole sweep is
     * broken", and must still be a non-zero exit rather than a quiet success.
     */
    public function test_sync_import_status_exits_one_when_every_single_dataset_failed(): void
    {
        $this->failingJobs = [3201 => 500, 3202 => 500, 3203 => 503];

        $datasets = array_map(fn (int $jobId): Dataset => $this->waitingDataset($jobId), [3201, 3202, 3203]);

        $exitCode = Artisan::call('sync:import-status');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'A run in which nothing succeeded must not exit 0.');
        $this->assertStringContainsString('Reconciled 3 dataset(s): 0 moved, 0 unchanged, 3 failed.', $output);
        $this->assertSame(3, preg_match_all('/^\s+ERROR\s+\S+/m', $output));
        $this->assertStringNotContainsString('MOVED', $output);
        $this->assertStringNotContainsString('HELD', $output);

        // A failed run leaves the mirror exactly as it found it, not half
        // rewritten: the UI and the warehouse read these rows.
        foreach ($datasets as $dataset) {
            $this->assertSame(DatasetStatus::Importing, $dataset->fresh()->status());
            $this->assertNull($dataset->fresh()->metadata['import_job'] ?? null);
        }
    }

    /**
     * `--dry-run` is the line an operator runs by hand before pressing go, so
     * its tally has to describe what it did: it listed candidates. It
     * reconciled nothing and it never asked the engine about any of them, so it
     * has no evidence that any of them is "unchanged", and must not claim it.
     */
    public function test_sync_import_status_dry_run_reports_the_candidates_it_would_reconcile_and_claims_no_outcome(): void
    {
        $oldest = $this->waitingDataset(3301);
        $second = $this->waitingDataset(3302);
        $skipped = $this->waitingDataset(3303);

        foreach ([$oldest, $second, $skipped] as $index => $dataset) {
            Dataset::query()->whereKey($dataset->getKey())->update([
                'updated_at' => Carbon::now()->subMinutes(30 - $index),
            ]);
        }

        $exitCode = Artisan::call('sync:import-status', ['--dry-run' => true, '--limit' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry-run', $output);
        $this->assertStringContainsString($oldest->uuid, $output);
        $this->assertStringContainsString($second->uuid, $output);
        $this->assertStringNotContainsString($skipped->uuid, $output);
        $this->assertStringContainsString('importing -> ?', $output);

        $this->assertStringContainsString(
            'Reconciled 2 dataset(s): 0 moved, 0 unchanged, 0 failed. 3 still pending; raise --limit or run again',
            $output,
            'A dry run reported rows it never checked as "unchanged"; the engine was never asked about them.',
        );

        $this->assertSame(0, $this->engineCallsTo('/imports/jobs/'), 'A dry run called the engine.');

        foreach ([$oldest, $second, $skipped] as $dataset) {
            $this->assertSame(DatasetStatus::Importing, $dataset->fresh()->status());
            $this->assertNull($dataset->fresh()->metadata['import_job'] ?? null);
        }
    }

    // ------------------------------------------------------------------
    // sync:quality — exit codes, counts and options
    // ------------------------------------------------------------------

    /**
     * Every counter the summary prints, driven by a fixture whose rows end in
     * three different states: two pass, one the engine now rejects, one it could
     * not be asked about at all.
     */
    public function test_sync_quality_counts_passed_quarantined_and_failed_rows_and_exits_one(): void
    {
        $this->qualityScores = [4103 => 0.21];
        $this->failingJobs = [4104 => 500];

        $this->staleCommittedDataset(4101);
        $this->staleCommittedDataset(4102);
        $this->staleCommittedDataset(4103);
        $broken = $this->staleCommittedDataset(4104, [
            'quality_score' => 0.44,
            'quality_checked_at' => Carbon::now()->subDays(120),
        ]);

        $exitCode = Artisan::call('sync:quality');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'One row we could not check is still a failed run.');
        $this->assertStringContainsString(
            // The quarantined tally counts rows whose *verdict* is quarantine.
            // A committed row keeps its status now that `runQuality()` no longer
            // quarantines a row whose data is already in the warehouse, so the
            // sweep counts it as refreshed with a failing verdict. The verdict
            // is still recorded and the row is still reported below.
            'Quality pass: 4 candidate(s), 3 refreshed, 0 quarantined, 0 dispatched, 1 failed.',
            $output,
        );

        $this->assertStringContainsString('QUARAN', $output);
        $this->assertStringContainsString('score 21.0%', $output);
        $this->assertStringContainsString('verdict quarantine', $output);
        $this->assertSame(1, preg_match_all('/^\s+ERROR\s+\S+/m', $output));

        // Four rows, one pass each, plus the single retry `AiEngineClient::http()`
        // configures with `retry(2, 250, throw: false)`: the row that came back
        // 500 is the one that is asked twice. The other three are not re-asked,
        // and the failed row is not retried by the sweep itself.
        $this->assertSame(5, $this->engineCallsTo('/imports/quality/'));

        // The "could not ask" row keeps its last known score; 0.0 would read
        // downstream as "this dataset failed quality".
        $this->assertSame(0.44, (float) $broken->fresh()->quality_score);
    }

    /**
     * `--days` is "older than", so the window decides which rows are old enough
     * to re-check. Three fixtures straddle it: never checked, checked inside the
     * window, checked outside it.
     */
    public function test_sync_quality_days_window_reports_only_the_candidates_it_will_re_check(): void
    {
        $never = $this->staleCommittedDataset(4201, ['quality_checked_at' => null]);
        $recent = $this->staleCommittedDataset(4202, [
            'quality_score' => 0.77,
            'quality_checked_at' => Carbon::now()->subDays(10),
        ]);
        $old = $this->staleCommittedDataset(4203);

        $exitCode = Artisan::call('sync:quality', ['--days' => 30]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Quality pass: 2 candidate(s), 2 refreshed, 0 quarantined, 0 dispatched, 0 failed.', $output);

        $this->assertStringContainsString($never->uuid, $output);
        $this->assertStringContainsString($old->uuid, $output);
        $this->assertStringNotContainsString($recent->uuid, $output);

        $this->assertSame(0.77, (float) $recent->fresh()->quality_score);
        $this->assertSame(2, $this->engineCallsTo('/imports/quality/'));
    }

    /**
     * The same fixtures with a wider window: a 120 day window leaves two rows
     * that a 30 day window would have re-checked. If `--days` were silently
     * ignored, both runs would report the same numbers and the nightly
     * `--days=30` in `routes/console.php` would mean nothing.
     */
    public function test_sync_quality_days_window_widens_to_re_check_fewer_rows(): void
    {
        $never = $this->staleCommittedDataset(4301, ['quality_checked_at' => null]);
        $recent = $this->staleCommittedDataset(4302, ['quality_checked_at' => Carbon::now()->subDays(10)]);
        $old = $this->staleCommittedDataset(4303);
        $ancient = $this->staleCommittedDataset(4304, ['quality_checked_at' => Carbon::now()->subDays(200)]);

        $exitCode = Artisan::call('sync:quality', ['--days' => 120]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Quality pass: 2 candidate(s), 2 refreshed, 0 quarantined, 0 dispatched, 0 failed.', $output);

        $this->assertStringContainsString($never->uuid, $output);
        $this->assertStringContainsString($ancient->uuid, $output);
        $this->assertStringNotContainsString($recent->uuid, $output);
        $this->assertStringNotContainsString($old->uuid, $output);

        $this->assertSame(2, $this->engineCallsTo('/imports/quality/'));
    }

    /**
     * `--limit` truncates the sweep, so the summary has to say so: a run that
     * checked one row of three and reported no numbers at all is how a nightly
     * backlog grows unnoticed for a month.
     */
    public function test_sync_quality_limit_truncates_the_sweep_and_reports_the_backlog_it_left(): void
    {
        $this->staleCommittedDataset(4401);
        $this->staleCommittedDataset(4402);
        $this->staleCommittedDataset(4403);

        $exitCode = Artisan::call('sync:quality', ['--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 candidate(s), 1 refreshed, 0 quarantined, 0 dispatched, 0 failed.', $output);
        $this->assertStringContainsString('still stale; raise --limit or run again', $output);
        $this->assertSame(1, $this->engineCallsTo('/imports/quality/'));
    }

    public function test_sync_quality_dry_run_reports_the_staleness_it_would_act_on_without_asking_the_engine(): void
    {
        $first = $this->staleCommittedDataset(4501, ['quality_score' => 0.5]);
        $second = $this->staleCommittedDataset(4502, [
            'quality_checked_at' => Carbon::now()->subDays(60),
        ]);

        $exitCode = Artisan::call('sync:quality', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry-run', $output);
        $this->assertStringContainsString($first->uuid, $output);
        $this->assertStringContainsString($second->uuid, $output);
        $this->assertStringContainsString('last checked 45 day(s) ago, window is 30 day(s)', $output);
        $this->assertStringContainsString('last checked 60 day(s) ago, window is 30 day(s)', $output);
        $this->assertStringContainsString(
            'Quality pass: 2 candidate(s), 0 refreshed, 0 quarantined, 0 dispatched, 0 failed.',
            $output,
        );

        $this->assertSame(0, $this->engineCallsTo('/imports/quality/'));

        $this->assertSame(0.5, (float) $first->fresh()->quality_score);
        $this->assertTrue($first->fresh()->quality_checked_at->lessThan(Carbon::now()->subDays(44)));
        $this->assertSame(DatasetStatus::Committed, $first->fresh()->status());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ------------------------------------------------------------------
    // the schedule
    // ------------------------------------------------------------------

    /**
     * `routes/console.php` runs these two commands every night. If the entries
     * were dropped, the status mirror and the quality scores would simply stop
     * updating — no page, no error, and no failure anywhere else in the suite,
     * because nothing else reads the scheduler. The overlap lock matters for the
     * same reason: a run that died mid-sweep would otherwise block every later
     * night, silently.
     */
    public function test_both_nightly_commands_are_scheduled_with_the_overlap_lock(): void
    {
        $importStatus = $this->eventsRunning('sync:import-status');
        $quality = $this->eventsRunning('sync:quality');

        $this->assertCount(1, $importStatus, 'sync:import-status is no longer scheduled.');
        $this->assertCount(1, $quality, 'sync:quality is no longer scheduled.');

        $importStatus = $importStatus[0];
        $quality = $quality[0];

        $this->assertStringContainsString('sync:import-status --limit=100', (string) $importStatus->command);
        $this->assertStringContainsString('sync:quality --limit=100 --days=30', (string) $quality->command);

        // 02:15 and 02:45, thirty minutes apart, so the two sweeps cannot
        // compete for the same engine on the same night.
        $this->assertSame('15 2 * * *', $importStatus->expression, 'sync:import-status no longer runs at 02:15.');
        $this->assertSame('45 2 * * *', $quality->expression, 'sync:quality no longer runs at 02:45.');

        foreach (['sync:import-status' => $importStatus, 'sync:quality' => $quality] as $name => $event) {
            $this->assertTrue(
                (bool) $event->withoutOverlapping,
                $name.' lost withoutOverlapping(): a sweep that dies mid-run would block every later night.',
            );
            $this->assertSame(30, $event->expiresAt, $name.': the overlap lock must expire on its own.');
        }

        $this->assertNotSame(
            (string) $importStatus->command,
            (string) $quality->command,
            'Both sweeps are registered as the same command, so only one of them would ever run.',
        );
    }

    // ------------------------------------------------------------------
    // RefreshQualityScoreJob
    // ------------------------------------------------------------------

    /**
     * `--queue` is the only thing standing between a slow engine and a stalled
     * scheduler, so the QUEUED lines an operator reads have to correspond one to
     * one with the payloads on the `datasets` queue, and to nothing else.
     */
    public function test_sync_quality_queue_prints_one_line_per_payload_pushed_to_the_datasets_queue(): void
    {
        Queue::fake();

        $first = $this->staleCommittedDataset(5101);
        $second = $this->staleCommittedDataset(5102);
        $this->staleCommittedDataset(5103, ['status' => DatasetStatus::Uploaded->value]);

        $exitCode = Artisan::call('sync:quality', ['--queue' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('2 candidate(s), 0 refreshed, 0 quarantined, 2 dispatched, 0 failed.', $output);

        preg_match_all('/^\s+QUEUED\s+(\S+)/m', $output, $matches);
        $printed = $matches[1] ?? [];

        $pushed = Queue::pushedJobs()[RefreshQualityScoreJob::class] ?? [];

        $this->assertSame(
            $printed,
            array_map(static fn (array $entry): string => $entry['job']->datasetUuid, $pushed),
            'A payload was dispatched that the operator was never told about, or a line was printed for a payload that was never queued.',
        );
        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], $printed);

        foreach ($pushed as $entry) {
            $this->assertSame('datasets', $entry['queue']);
            $this->assertSame('datasets', $entry['job']->queue);
        }

        $this->assertSame(0, $this->engineCallsTo('/imports/quality/'));
    }

    /**
     * A worker that cannot reach the engine has to mark the job failed, so the
     * queue shows it and `queue:failed` lists it. The one thing it must never
     * do is write `0.0`: that number is read downstream as "this dataset failed
     * quality", and it would quarantine a warehouse that is merely unreachable.
     */
    public function test_a_dispatched_refresh_job_is_marked_failed_and_leaves_the_previous_score_alone(): void
    {
        $this->failingJobs = [5201 => 503];

        $dataset = $this->staleCommittedDataset(5201, ['quality_score' => 0.77]);
        $frozen = $dataset->fresh()->quality_checked_at;

        /** @var list<JobFailed> $failures */
        $failures = [];
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
            $failures[] = $event;
        });

        $caught = null;

        try {
            // `Bus::dispatch` is the same path `sync:quality --queue` takes: the
            // job is pushed onto the queue named by the job itself, so the real
            // queue machinery runs it and the sync connection rethrows.
            Bus::dispatch(new RefreshQualityScoreJob($dataset->uuid));
        } catch (AiEngineException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'A dead engine must not be swallowed by the job.');
        $this->assertSame(503, $caught->upstreamStatus());

        $this->assertCount(1, $failures, 'The job must be marked failed, not silently dropped.');
        $this->assertTrue($failures[0]->job->hasFailed());
        $this->assertSame(RefreshQualityScoreJob::class, $failures[0]->job->payload()['displayName'] ?? null);
        $this->assertInstanceOf(AiEngineException::class, $failures[0]->exception);

        $fresh = $dataset->fresh();

        $this->assertSame(0.77, (float) $fresh->quality_score, 'A failed check wrote a score; 0.0 would read as "failed quality".');
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertTrue($frozen->equalTo($fresh->quality_checked_at));
        $this->assertSame(DatasetStatus::Committed, $fresh->status());
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
