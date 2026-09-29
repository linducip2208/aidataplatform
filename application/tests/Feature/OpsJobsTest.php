<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Jobs\RefreshQualityScoreJob;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `RefreshQualityScoreJob` is the unit of work behind `sync:quality --queue`.
 * Its payload is a single dataset uuid, it only ever touches committed rows,
 * and it has to fail loudly rather than write a `0.0` that would read as
 * "this dataset failed quality" instead of "we could not ask the engine".
 */
class OpsJobsTest extends TestCase
{
    use RefreshDatabase;

    protected float $qualityScore = 0.91;

    /** When set, `/imports/quality/{id}` answers with this HTTP status. */
    protected ?int $failingStatus = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'ai_engine.quality_threshold' => 0.75,
        ]);

        $this->fakeEngine();
    }

    /**
     * One closure stub rather than a URL map: stub callbacks are matched
     * first-registered-wins, so per-test variation has to go through state read
     * at request time.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/imports/quality/')) {
                if ($this->failingStatus !== null) {
                    return Http::response(['detail' => 'the engine is down'], $this->failingStatus);
                }

                return Http::response([
                    'success' => true,
                    'data' => [
                        'score' => $this->qualityScore,
                        'breakdown' => [
                            'completeness' => 0.97,
                            'uniqueness' => 0.88,
                            'validity' => 0.92,
                            'consistency' => 0.87,
                        ],
                        'issues' => [],
                        'passed' => $this->qualityScore >= (float) config('ai_engine.quality_threshold'),
                    ],
                ], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function committedDataset(array $attributes = []): Dataset
    {
        return Dataset::factory()->committed()->create([
            'import_job_id' => 9001,
            ...$attributes,
        ]);
    }

    protected function runJob(RefreshQualityScoreJob $job): void
    {
        $job->handle($this->app->make(DatasetIngestionService::class));
    }

    // ------------------------------------------------------------------
    // dispatch contract
    // ------------------------------------------------------------------

    public function test_the_job_is_dispatched_to_the_datasets_queue_with_only_the_dataset_uuid(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();

        RefreshQualityScoreJob::dispatch($uuid);

        Queue::assertPushedOn('datasets', RefreshQualityScoreJob::class);
        Queue::assertPushed(RefreshQualityScoreJob::class, 1);

        /** @var RefreshQualityScoreJob $pushed */
        $pushed = Queue::pushedJobs()[RefreshQualityScoreJob::class][0]['job'];

        $this->assertInstanceOf(ShouldQueue::class, $pushed);
        $this->assertSame($uuid, $pushed->datasetUuid);
        $this->assertSame('datasets', $pushed->queue);
        $this->assertSame(2, $pushed->tries);
        $this->assertSame(60, $pushed->backoff);
        $this->assertSame(300, $pushed->timeout);
    }

    // ------------------------------------------------------------------
    // running it
    // ------------------------------------------------------------------

    public function test_running_the_job_inline_persists_the_score_and_writes_the_audit_row(): void
    {
        $dataset = $this->committedDataset(['quality_score' => null, 'quality_checked_at' => null]);

        $this->runJob(new RefreshQualityScoreJob($dataset->uuid));

        $fresh = $dataset->fresh();

        $this->assertSame(0.91, (float) $fresh->quality_score);
        $this->assertSame('pass', $fresh->quality_verdict);
        $this->assertNotNull($fresh->quality_checked_at);
        $this->assertSame(0.91, (float) $fresh->metadata['quality']['score']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dataset.quality_checked',
            'resource' => 'dataset',
            'resource_id' => $dataset->getKey(),
        ]);

        Http::assertSent(fn (ClientRequest $r): bool => str_ends_with($r->url(), '/api/v1/imports/quality/9001'));
    }

    public function test_running_the_job_leaves_a_committed_dataset_committed(): void
    {
        $dataset = $this->committedDataset();

        $outcome = (new RefreshQualityScoreJob($dataset->uuid))
            ->refresh($this->app->make(DatasetIngestionService::class), $dataset);

        $this->assertSame(0.91, $outcome['score']);
        $this->assertSame('pass', $outcome['verdict']);
        $this->assertSame('committed', $outcome['status']);
        $this->assertSame(DatasetStatus::Committed, $dataset->fresh()->status());
    }

    public function test_running_the_job_twice_does_not_corrupt_the_row_or_double_count(): void
    {
        $dataset = $this->committedDataset([
            'quality_score' => 0.62,
            'quality_checked_at' => Carbon::now()->subDays(90),
        ]);

        $this->runJob(new RefreshQualityScoreJob($dataset->uuid));
        $first = $dataset->fresh();

        $this->runJob(new RefreshQualityScoreJob($dataset->uuid));
        $second = $dataset->fresh();

        // A second pass must overwrite, never accumulate.
        $this->assertSame(0.91, (float) $second->quality_score);
        $this->assertSame('pass', $second->quality_verdict);
        $this->assertSame(DatasetStatus::Committed, $second->status());
        $this->assertIsArray($second->metadata['quality']);
        $this->assertSame(0.91, (float) $second->metadata['quality']['score']);

        $this->assertTrue($second->quality_checked_at->greaterThanOrEqualTo($first->quality_checked_at));

        // Two runs, two audit rows: the trail is append-only, the dataset row is not.
        $this->assertSame(2, $this->qualityAuditCountFor($dataset));
    }

    public function test_the_job_skips_a_dataset_that_is_not_committed(): void
    {
        $dataset = Dataset::factory()->quarantined()->create(['import_job_id' => 9002]);

        $this->runJob(new RefreshQualityScoreJob($dataset->uuid));

        $fresh = $dataset->fresh();

        $this->assertSame(DatasetStatus::Quarantined, $fresh->status());
        Http::assertNotSent(fn (ClientRequest $r): bool => str_contains($r->url(), '/imports/quality/9002'));
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'dataset.quality_checked',
            'resource_id' => $dataset->getKey(),
        ]);
    }

    public function test_the_job_does_nothing_for_a_dataset_that_no_longer_exists(): void
    {
        $this->runJob(new RefreshQualityScoreJob((string) Str::uuid()));

        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // failure handling
    // ------------------------------------------------------------------

    public function test_the_job_throws_instead_of_writing_a_zero_score_when_the_engine_is_down(): void
    {
        $this->failingStatus = 503;

        $dataset = $this->committedDataset([
            'quality_score' => 0.77,
            'quality_checked_at' => Carbon::now()->subDays(45),
        ]);

        $frozen = $dataset->fresh()->quality_checked_at;
        $caught = null;

        try {
            $this->runJob(new RefreshQualityScoreJob($dataset->uuid));
        } catch (AiEngineException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'A dead engine must not be swallowed: the score has to stay untouched.');
        $this->assertSame(503, $caught->upstreamStatus());

        $fresh = $dataset->fresh();

        // The load-bearing assertion: a 0.0 here would read downstream as
        // "this dataset failed quality" and quarantine a healthy warehouse.
        $this->assertSame(0.77, (float) $fresh->quality_score);
        $this->assertTrue($frozen->equalTo($fresh->quality_checked_at));
        $this->assertSame(DatasetStatus::Committed, $fresh->status());

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'dataset.quality_checked',
            'resource_id' => $dataset->getKey(),
        ]);
    }

    public function test_a_poor_recheck_score_is_recorded_without_walking_a_committed_dataset(): void
    {
        $this->qualityScore = 0.12;

        $dataset = $this->committedDataset();

        $this->runJob(new RefreshQualityScoreJob($dataset->uuid));

        $fresh = $dataset->fresh();

        $this->assertSame(0.12, (float) $fresh->quality_score);
        $this->assertSame('quarantine', $fresh->quality_verdict);
        // Pinned deliberately: the rows are already in the warehouse, and both
        // re-check entry points only look at non-terminal rows, so quarantining
        // here would strand a committed row permanently with no path back.
        // `runQuality()` keeps the terminal status and the recorded verdict
        // and score still surface the problem on the quality page.
        $this->assertSame(DatasetStatus::Committed, $fresh->status());
    }

    protected function qualityAuditCountFor(Dataset $dataset): int
    {
        return AuditLog::query()
            ->where('action', 'dataset.quality_checked')
            ->where('resource_id', $dataset->getKey())
            ->count();
    }
}
