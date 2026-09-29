<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reusable fake for the FastAPI AI engine.
 *
 * Unifies the per-suite closures in OpsCommandOutputTest, WebWorkflowTest,
 * DatasetLifecycleTest, AuditTrailTest and QueryCountTest into one builder
 * that mirrors the REAL engine contracts:
 *
 * - business routes answer the `{success: true, data: ...}` envelope that
 *   `AiEngineClient::unwrap()` expects;
 * - `/health` and `/readiness` answer with a BARE body (no envelope), because
 *   `AiEngineClient::health()/readiness()` go through `decode()`, not
 *   `unwrap()` — faking the envelope there makes the client report the engine
 *   as healthy-but-empty and the doctor misread it;
 * - `/imports/quality/{id}` carries `score/breakdown/issues/passed`, the shape
 *   `DatasetIngestionService::runQuality()` reads.
 *
 * Per-test variation travels through the returned EngineFakeState object,
 * because `Http` stub callbacks are matched first-registered-wins: a second
 * `Http::fake()` inside a test can never override the closure registered in
 * `setUp()`. Reading `$state->qualityScore` / `$state->jobStatus` /
 * `$state->failingJobs` at request time is what makes per-test variation
 * possible without re-registering the fake.
 *
 * Usage:
 *
 *     $this->engine = EngineFakes::install();          // in setUp()
 *     $this->engine->qualityScore = 0.42;              // per test
 *     $this->engine->failingJobs = [42 => 500];
 *     EngineFakes::callsTo('/imports/quality/');      // assertions
 */
final class EngineFakes
{
    /**
     * Register the single closure fake and hand back the mutable state the
     * test drives. Pass an existing state to share it across helpers.
     */
    public static function install(?EngineFakeState $state = null): EngineFakeState
    {
        $state ??= new EngineFakeState;

        Http::fake(function (ClientRequest $request) use ($state) {
            return self::respond($request, $state);
        });

        return $state;
    }

    /**
     * How many requests (including retried ones) were sent to a URL fragment.
     *
     * Retries go through the fake again, so this counts every attempt — which
     * is exactly what pins `AiEngineClient::http()`'s `retry(2, 250)` policy:
     * a 500-backed call is attempted more than once, a 422-backed call once.
     */
    public static function callsTo(string $path): int
    {
        return Http::recorded(
            static fn (ClientRequest $request): bool => str_contains($request->url(), $path),
        )->count();
    }

    /**
     * The quality report `GET /imports/quality/{id}` returns.
     *
     * When $passed is null the flag follows the Laravel threshold, the same
     * fallback `runQuality()` applies when the engine omits the key; pass an
     * explicit bool to pin the engine's own verdict independently of the score.
     *
     * @return array<string, mixed>
     */
    public static function qualityReport(float $score, ?bool $passed = null): array
    {
        $threshold = (float) config('ai_engine.quality_threshold', 0.75);

        return [
            'score' => $score,
            'breakdown' => [
                'completeness' => 0.97,
                'uniqueness' => 0.88,
                'validity' => 0.92,
                'consistency' => 0.87,
            ],
            'issues' => [],
            'passed' => $passed ?? $score >= $threshold,
        ];
    }

    /** @return array<string, mixed> */
    protected static function qualityFor(EngineFakeState $state, ?int $jobId): array
    {
        $report = self::qualityReport(
            $state->qualityScores[$jobId] ?? $state->qualityScore,
            $state->enginePassed,
        );

        if ($state->omitPassedFlag) {
            unset($report['passed']);
        }

        return $report;
    }

    /** @return mixed */
    public static function respond(ClientRequest $request, EngineFakeState $state)
    {
        $url = $request->url();

        // Bare bodies, never the envelope (see class docblock).
        if (str_ends_with($url, '/health')) {
            return $state->healthDown
                ? Http::response(['detail' => 'the fastapi container is restarting'], 503)
                : Http::response(['status' => 'ok', 'version' => '1.4.0'], 200);
        }

        if (str_ends_with($url, '/readiness')) {
            return Http::response(
                $state->readiness ?? ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
                200,
            );
        }

        if (str_ends_with($url, '/models')) {
            return $state->modelsStatus === null
                ? Http::response(['success' => true, 'data' => [['id' => 1, 'name' => 'churn-classifier']]], 200)
                : Http::response(['detail' => $state->modelsDetail], $state->modelsStatus);
        }

        // Per-job failure injection, e.g. [42 => 500].
        $jobId = preg_match('#/imports/(?:jobs|quality)/(\d+)$#', $url, $matches) === 1
            ? (int) $matches[1]
            : null;

        if ($jobId !== null && isset($state->failingJobs[$jobId])) {
            return Http::response(['detail' => 'the engine blew up on import job '.$jobId], $state->failingJobs[$jobId]);
        }

        $payload = match (true) {
            str_ends_with($url, '/imports/upload') => [
                'import_job_id' => 42,
                'validation' => [
                    'ok' => $state->uploadValid,
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
            str_contains($url, '/imports/quality/') => self::qualityFor($state, $jobId),
            str_contains($url, '/imports/jobs/') => [
                'status' => $state->jobStatus,
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
            str_ends_with($url, '/imports/commit') => [
                'import_job_id' => 42,
                'status' => $state->commitStatus,
                'row_count' => 128,
            ],
            str_ends_with($url, '/analytics/kpi') => [
                'revenue' => 1250000000, 'orders' => 3140, 'units' => 9820,
                'aov' => 398089, 'growth_pct' => 12.4, 'margin_pct' => 31.8,
            ],
            str_ends_with($url, '/analytics/trend') => [
                ['period' => '2026-01-01', 'revenue' => 40000000, 'orders' => 100, 'units' => 300],
            ],
            str_ends_with($url, '/analytics/rfm') => [
                ['customer' => 'Toko Maju 001', 'recency_days' => 10, 'frequency' => 4,
                    'monetary' => 2500000, 'r_score' => 4, 'f_score' => 3, 'm_score' => 5,
                    'segment' => 'loyal'],
            ],
            str_ends_with($url, '/analytics/abc') => [
                ['product' => 'Minyak Goreng 1L', 'revenue' => 90000000,
                    'share_pct' => 1.5, 'cumulative_pct' => 10.0, 'grade' => 'A'],
            ],
            str_ends_with($url, '/analytics/cohort') => [
                ['cohort' => '2026-01', 'period_offset' => 0, 'retention_pct' => 100.0, 'active_customers' => 500],
            ],
            str_ends_with($url, '/analytics/branches') => [
                ['branch' => 'BR-01', 'revenue' => 80000000, 'orders' => 900, 'share_pct' => 8.3],
            ],
            str_ends_with($url, '/analytics/finance') => [
                'total_revenue' => 1250000000, 'total_cogs' => 850000000, 'total_expenses' => 120000000,
                'gross_profit' => 400000000, 'net_profit' => 280000000, 'margin_pct' => 22.4,
            ],
            str_ends_with($url, '/ai/chat') => [
                'answer' => 'Penjualan naik 12% QoQ.',
                'conversation_id' => 77,
                'evidence' => [],
                'steps' => 2,
            ],
            str_ends_with($url, '/ai/report') => [
                'narrative' => 'Penjualan naik konsisten sepanjang periode.',
                'kpi' => ['revenue' => 1250000000, 'orders' => 3140],
            ],
            str_ends_with($url, '/rag/ingest') => [
                'document_id' => 1, 'n_chunks' => 2, 'n_embedded' => 2, 'status' => 'created',
            ],
            str_ends_with($url, '/rag/query') => [
                'answer' => 'Voucher dikirim pada hari ke-60.',
                'citations' => [],
                'chunks' => [],
                'n_results' => 0,
            ],
            str_ends_with($url, '/forecast') => ['forecast' => [], 'method' => 'baseline', 'metrics' => []],
            str_ends_with($url, '/customers/churn') => ['scores' => []],
            str_ends_with($url, '/customers/segment') => ['segments' => []],
            str_ends_with($url, '/inventory/health') => ['items' => []],
            str_ends_with($url, '/anomaly/detect') => ['anomalies' => []],
            str_ends_with($url, '/recommend') => ['items' => []],
            str_ends_with($url, '/training/train') => ['model_id' => 7, 'version' => 'v3'],
            str_ends_with($url, '/training/predict') => ['prediction' => null],
            default => [],
        };

        return Http::response(['success' => true, 'data' => $payload], 200);
    }
}
