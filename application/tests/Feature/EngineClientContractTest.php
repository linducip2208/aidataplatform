<?php

namespace Tests\Feature;

use App\Exceptions\AiEngineException;
use App\Models\User;
use App\Services\AiEngineClient;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins the wire contract of `AiEngineClient` itself.
 *
 * `AiEngineClientTest` covers the envelope and `EngineContractTest` covers the
 * shapes the engine returns. Neither walks the class looking for a method that
 * stopped being called, so this file enumerates the client surface instead:
 * every public network method is listed in `clientEndpoint()` and checked
 * against the route `ai-engine/app/api/v1/*.py` declares. A new method that
 * nobody pins fails `test_every_public_method_of_the_client_is_pinned_here`.
 *
 * Failures are asserted as a pair: the status the browser finally sees
 * (`AiEngineException::statusForClient()`, which `bootstrap/app.php` uses) and
 * the message Laravel renders, so a mapping change is a test failure rather
 * than a support ticket.
 */
class EngineClientContractTest extends TestCase
{
    use RefreshDatabase;

    /** Read at request time, not at fake-registration time: stub callbacks are first-registered-wins. */
    protected ?array $forcedResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEngine();
    }

    /**
     * One closure stub rather than a URL map: `Http::response()` returns a
     * promise and stub callbacks are resolved first-registered-wins, so a
     * per-test `Http::fake()` could never override a map registered in
     * `setUp()`. Per-test variation goes through `force()`.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->forcedResponse !== null) {
                return Http::response(
                    $this->forcedResponse['body'],
                    $this->forcedResponse['status'],
                    $this->forcedResponse['headers'],
                );
            }

            // The only two endpoints that answer with a bare dict rather than
            // the `{success, data}` envelope: `health.py` declares
            // `response_model=HealthResponse` and `readiness()` returns a dict.
            return match (true) {
                str_ends_with($request->url(), '/api/v1/health') => Http::response(
                    ['status' => 'ok', 'app' => 'ai-engine', 'env' => 'testing', 'version' => '1.0.0'],
                    200,
                ),
                str_ends_with($request->url(), '/api/v1/readiness') => Http::response(
                    ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
                    200,
                ),
                default => Http::response(['success' => true, 'data' => []], 200),
            };
        });
    }

    protected function engine(): AiEngineClient
    {
        return AiEngineClient::fromConfig();
    }

    /** @param array<string, string> $headers */
    protected function force(int $status, mixed $body = [], array $headers = []): void
    {
        $this->forcedResponse = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * Every public method that talks to the engine, keyed by method name so the
     * completeness check below can diff it against the real class. `$path` is
     * the full URL, `config('ai_engine.base_url') . '/api/v1' . <route>`.
     *
     * @return array<string, array{string, string, Closure(AiEngineClient): mixed}>
     */
    public static function clientEndpoint(): array
    {
        return [
            // imports — ai-engine/app/api/v1/imports.py
            // `uploadFile()` is not here: it cannot send anything at all today,
            // so it is pinned by `test_the_upload_never_reaches_the_engine`.
            'preview' => ['GET', '/api/v1/imports/preview/7', fn (AiEngineClient $c): array => $c->preview(7)],
            'suggestMapping' => ['POST', '/api/v1/imports/mapping/suggest', fn (AiEngineClient $c): array => $c->suggestMapping(
                ['date', 'total'],
                'sales',
            )],
            'applyMapping' => ['POST', '/api/v1/imports/mapping', fn (AiEngineClient $c): array => $c->applyMapping(
                7,
                ['total' => 'revenue'],
                'sales',
                'template-retail',
            )],
            'runQuality' => ['GET', '/api/v1/imports/quality/7', fn (AiEngineClient $c): array => $c->runQuality(7)],
            'commitImport' => ['POST', '/api/v1/imports/commit', fn (AiEngineClient $c): array => $c->commitImport(7)],
            'importJob' => ['GET', '/api/v1/imports/jobs/7', fn (AiEngineClient $c): array => $c->importJob(7)],

            // analytics — ai-engine/app/api/v1/analytics.py
            'kpi' => ['POST', '/api/v1/analytics/kpi', fn (AiEngineClient $c): array => $c->kpi(['granularity' => 'daily'])],
            'trend' => ['POST', '/api/v1/analytics/trend', fn (AiEngineClient $c): array => $c->trend(['granularity' => 'daily'])],
            'rfm' => ['POST', '/api/v1/analytics/rfm', fn (AiEngineClient $c): array => $c->rfm()],
            'abc' => ['POST', '/api/v1/analytics/abc', fn (AiEngineClient $c): array => $c->abc()],
            'cohort' => ['POST', '/api/v1/analytics/cohort', fn (AiEngineClient $c): array => $c->cohort()],
            'branches' => ['GET', '/api/v1/analytics/branches', fn (AiEngineClient $c): array => $c->branches()],
            'finance' => ['GET', '/api/v1/analytics/finance', fn (AiEngineClient $c): array => $c->finance()],

            // ml — forecast.py, customers.py, inventory.py, anomaly.py, recommendation.py
            'forecast' => ['POST', '/api/v1/forecast', fn (AiEngineClient $c): array => $c->forecast([['date' => '2026-01-01', 'y' => 10]])],
            'churn' => ['POST', '/api/v1/customers/churn', fn (AiEngineClient $c): array => $c->churn([['customer_id' => 'C-1']])],
            'segment' => ['POST', '/api/v1/customers/segment', fn (AiEngineClient $c): array => $c->segment([['customer_id' => 'C-1']])],
            'inventoryHealth' => ['POST', '/api/v1/inventory/health', fn (AiEngineClient $c): array => $c->inventoryHealth()],
            'detectAnomalies' => ['POST', '/api/v1/anomaly/detect', fn (AiEngineClient $c): array => $c->detectAnomalies([['ts' => '2026-01-01', 'y' => 10]])],
            'recommend' => ['POST', '/api/v1/recommend', fn (AiEngineClient $c): array => $c->recommend('C-1', 'P-9')],

            // ml — models.py, training.py
            'models' => ['GET', '/api/v1/models', fn (AiEngineClient $c): array => $c->models()],
            'model' => ['GET', '/api/v1/models/7', fn (AiEngineClient $c): array => $c->model(7)],
            'promoteModel' => ['POST', '/api/v1/models/7/promote', fn (AiEngineClient $c): array => $c->promoteModel(7, 3)],
            'train' => ['POST', '/api/v1/training/train', fn (AiEngineClient $c): array => $c->train('forecast', 'f_v1')],
            'predict' => ['POST', '/api/v1/training/predict', fn (AiEngineClient $c): array => $c->predict('forecast', 'f_v1')],

            // ai / rag — ai.py, rag.py
            'chat' => ['POST', '/api/v1/ai/chat', fn (AiEngineClient $c): array => $c->chat('hi')],
            'report' => ['POST', '/api/v1/ai/report', fn (AiEngineClient $c): array => $c->report('weekly')],
            'ragIngest' => ['POST', '/api/v1/rag/ingest', fn (AiEngineClient $c): array => $c->ragIngest('Policy', 'Isi')],
            'ragQuery' => ['POST', '/api/v1/rag/query', fn (AiEngineClient $c): array => $c->ragQuery('apa retensi?')],

            // health / meta — the one pair that is not enveloped
            'health' => ['GET', '/api/v1/health', fn (AiEngineClient $c): array => $c->health()],
            'readiness' => ['GET', '/api/v1/readiness', fn (AiEngineClient $c): array => $c->readiness()],
        ];
    }

    #[DataProvider('clientEndpoint')]
    public function test_every_client_method_hits_the_documented_engine_route(string $method, string $path, Closure $call): void
    {
        $call($this->engine());

        $recorded = Http::recorded();

        // Exactly once: a successful call is not retried, so a second entry here
        // would mean the method fires its request more than once.
        $this->assertCount(1, $recorded, sprintf('%s %s was not sent exactly once.', $method, $path));

        [$request] = $recorded[0];

        $this->assertSame($method, $request->method(), "Wrong verb for {$path}.");
        $this->assertSame('http://fastapi.test'.$path, $request->url(), "Wrong URL for {$method}.");
    }

    /**
     * The guard that makes the list above load-bearing: a method added to the
     * client without a pinned route, or a pinned method removed from the class,
     * fails here instead of going untested.
     */
    public function test_every_public_method_of_the_client_is_pinned_here(): void
    {
        $declared = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(AiEngineClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        // `isConfigured()` answers from config, it never calls the engine.
        // `fromConfig()` and the constructor build the object, not a request.
        // `uploadFile()` is pinned apart from the list above because it never
        // gets as far as the wire — see its own test for the reason.
        $declared = array_values(array_diff($declared, [
            '__construct',
            'fromConfig',
            'isConfigured',
            'uploadFile',
        ]));
        sort($declared);

        $pinned = array_keys(self::clientEndpoint());
        sort($pinned);

        $this->assertSame($declared, $pinned);
    }

    // ------------------------------------------------------------------
    // request bodies
    // ------------------------------------------------------------------

    /**
     * `run_async` and `mappings` are the two fields the ingestion screens
     * cannot work without: a dropped `mappings` silently imports unmapped
     * columns, and a dropped `run_async` turns a queued job into a request
     * that times out. `false` is the case that breaks — it is the value a
     * falsy check would eat, and it differs from the engine's own default
     * (`ImportCommit.run_async: bool = False`).
     *
     * @return array<string, array{bool, array<string, mixed>}>
     */
    public static function commitRunAsync(): array
    {
        $mappings = ['date' => 'tanggal', 'total' => 'revenue'];

        return [
            'queued' => [true, [
                'import_job_id' => 7,
                'dataset_type' => 'sales',
                'mappings' => $mappings,
                'run_async' => true,
            ]],
            'synchronous' => [false, [
                'import_job_id' => 7,
                'dataset_type' => 'sales',
                'mappings' => $mappings,
                'run_async' => false,
            ]],
        ];
    }

    #[DataProvider('commitRunAsync')]
    public function test_the_commit_body_forwards_the_mapping_and_the_run_async_flag(bool $runAsync, array $expected): void
    {
        $this->engine()->commitImport(7, ['date' => 'tanggal', 'total' => 'revenue'], 'sales', $runAsync);

        $this->assertSentTo('/api/v1/imports/commit', $expected);
    }

    public function test_the_mapping_apply_body_carries_the_job_the_mapping_and_the_template(): void
    {
        $this->engine()->applyMapping(7, ['total' => 'revenue'], 'sales', 'template-retail');

        // `MappingRequest` in `app/schemas/imports.py`: all four keys, plus a
        // null `save_as_template` has to stay a JSON null rather than vanish.
        $this->assertSentTo('/api/v1/imports/mapping', [
            'import_job_id' => 7,
            'dataset_type' => 'sales',
            'mappings' => ['total' => 'revenue'],
            'save_as_template' => 'template-retail',
        ]);

        $this->engine()->applyMapping(7, [], 'sales');

        $this->assertSentTo('/api/v1/imports/mapping', [
            'import_job_id' => 7,
            'dataset_type' => 'sales',
            'mappings' => [],
            'save_as_template' => null,
        ]);
    }

    public function test_the_mapping_suggest_body_lists_the_columns_the_preview_returned(): void
    {
        // `mapping_suggest(payload: dict)` reads `columns` and `dataset_type`
        // out of a raw dict, so an associative array would reach the engine as
        // an object and iterate as keys.
        $this->engine()->suggestMapping([3 => 'date', 7 => 'total'], 'inventory');

        $this->assertSentTo('/api/v1/imports/mapping/suggest', [
            'columns' => ['date', 'total'],
            'dataset_type' => 'inventory',
        ]);
    }

    public function test_the_upload_reaches_the_engine_as_multipart(): void
    {
        // `upload_file(file: UploadFile = File(...), dataset_type: Form(...))`
        // takes a multipart body with a `file` part.
        //
        // This was broken: the shared builder set `Http::asJson()`, so the
        // payload was json-encoded before the multipart option was read,
        // `json_encode()` threw on the UploadedFile, and every upload in the
        // platform failed without a request being made. The client now builds
        // this one call with `asMultipart()` + `attach()`.
        $this->force(200, ['success' => true, 'data' => ['import_job_id' => 7]]);

        $result = $this->engine()->uploadFile(
            UploadedFile::fake()->create('sales.csv', 8, 'text/csv'),
            'sales',
        );

        $this->assertSame(7, $result['import_job_id']);

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/imports/upload')) {
                return false;
            }

            $this->assertStringContainsString('multipart/form-data', (string) $request->header('Content-Type')[0]);
            $this->assertStringContainsString('sales.csv', $request->body());

            return true;
        });
    }

    /**
     * The analytics pages send the whole filter block the URL carries; anything
     * the client drops is a filter the user believes is applied and is not.
     *
     * @return array<string, array{string, string}>
     */
    public static function analyticsPost(): array
    {
        return [
            'kpi' => ['kpi', '/api/v1/analytics/kpi'],
            'trend' => ['trend', '/api/v1/analytics/trend'],
            'rfm' => ['rfm', '/api/v1/analytics/rfm'],
            'abc' => ['abc', '/api/v1/analytics/abc'],
            'cohort' => ['cohort', '/api/v1/analytics/cohort'],
        ];
    }

    #[DataProvider('analyticsPost')]
    public function test_the_analytics_filter_reaches_the_engine_verbatim(string $method, string $path): void
    {
        $filter = [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'branch' => 'BR-03',
            'category' => 'food',
            'granularity' => 'monthly',
        ];

        $this->engine()->{$method}($filter);

        $this->assertSentTo($path, $filter);
    }

    public function test_the_forecast_horizon_and_granularity_reach_the_engine_instead_of_being_dropped(): void
    {
        $history = [3 => ['date' => '2026-01-01', 'y' => 10]];

        $this->engine()->forecast($history, 365, 'monthly');

        // `ForecastRequest` caps `horizon` at 365 (`le=365`); 365 is the largest
        // value the engine accepts, so it has to arrive unrewritten.
        $this->assertSentTo('/api/v1/forecast', [
            'history' => [['date' => '2026-01-01', 'y' => 10]],
            'horizon' => 365,
            'granularity' => 'monthly',
        ]);
    }

    /**
     * @return array<string, array{string, Closure(AiEngineClient): array, array<string, mixed>}>
     */
    public static function postBody(): array
    {
        return [
            'churn' => ['/api/v1/customers/churn', fn (AiEngineClient $c): array => $c->churn([
                ['customer_id' => 'C-1', 'revenue' => 1200.0],
            ]), ['customers' => [['customer_id' => 'C-1', 'revenue' => 1200.0]]]],
            'segment' => ['/api/v1/customers/segment', fn (AiEngineClient $c): array => $c->segment(
                [['customer_id' => 'C-1']],
                6,
            ), ['customers' => [['customer_id' => 'C-1']], 'n_clusters' => 6]],
            'inventory health' => ['/api/v1/inventory/health', fn (AiEngineClient $c): array => $c->inventoryHealth([
                'branch' => 'BR-03',
            ]), ['branch' => 'BR-03']],
            'anomaly detect' => ['/api/v1/anomaly/detect', fn (AiEngineClient $c): array => $c->detectAnomalies([
                ['ts' => '2026-01-01', 'y' => 10],
            ], 3.0), ['series' => [['ts' => '2026-01-01', 'y' => 10]], 'sensitivity' => 3.0]],
            'recommend' => ['/api/v1/recommend', fn (AiEngineClient $c): array => $c->recommend('C-1', 'P-9', 5), [
                'customer_id' => 'C-1',
                'product_id' => 'P-9',
                'top_k' => 5,
            ]],
            'predict' => ['/api/v1/training/predict', fn (AiEngineClient $c): array => $c->predict(
                'forecast',
                'f_v1',
                ['date' => '2026-02-01'],
            ), [
                'model_type' => 'forecast',
                'model_name' => 'f_v1',
                'payload' => ['date' => '2026-02-01'],
            ]],
            'rag ingest' => ['/api/v1/rag/ingest', fn (AiEngineClient $c): array => $c->ragIngest(
                'Kebijakan retensi',
                'Voucher dikirim pada hari ke-60.',
                'api',
                'md',
            ), [
                'title' => 'Kebijakan retensi',
                'content' => 'Voucher dikirim pada hari ke-60.',
                'source' => 'api',
                'doc_type' => 'md',
            ]],
        ];
    }

    #[DataProvider('postBody')]
    public function test_the_post_body_matches_the_engine_request_schema(string $path, Closure $call, array $expected): void
    {
        $call($this->engine());

        $this->assertSentTo($path, $expected);
    }

    public function test_an_optional_argument_left_out_arrives_as_a_json_null_and_not_as_a_missing_key(): void
    {
        // `TrainRequest`/`ChatRequest` declare these `Optional[...] = None`.
        // A key that disappears is indistinguishable from one set to null for
        // pydantic, but a key that disappears here is also invisible in a log.
        $this->engine()->predict('churn');

        $this->assertSentTo('/api/v1/training/predict', [
            'model_type' => 'churn',
            'model_name' => null,
            'payload' => [],
        ]);
    }

    public function test_the_recommendation_top_k_is_forwarded_unchanged_at_the_engine_maximum(): void
    {
        // `RecommendRequest.top_k` is `ge=1, le=50`.
        $this->engine()->recommend('C-1', 'P-9', 50);

        $this->assertSentTo('/api/v1/recommend', [
            'customer_id' => 'C-1',
            'product_id' => 'P-9',
            'top_k' => 50,
        ]);
    }

    public function test_the_rag_query_top_k_is_forwarded_unchanged_at_the_engine_maximum(): void
    {
        // `RagQueryRequest.top_k` is `ge=1, le=20` — a different cap from the
        // recommendation endpoint, which is why each is pinned on its own.
        $this->engine()->ragQuery('apa POLICY retensi?', 20);

        $this->assertSentTo('/api/v1/rag/query', ['query' => 'apa POLICY retensi?', 'top_k' => 20]);
    }

    public function test_an_out_of_range_top_k_is_sent_verbatim_and_rejected_by_the_engine(): void
    {
        // Documents the gap rather than the wish: the client does not clamp, so
        // 51 leaves this process and pydantic answers 422. Clamping belongs at
        // the edge (the controllers), not here, but the day someone adds it this
        // test fails and the intent gets reviewed.
        $this->force(422, ['detail' => [[
            'type' => 'less_than_equal',
            'loc' => ['body', 'top_k'],
            'msg' => 'Input should be less than or equal to 50',
            'input' => 51,
            'ctx' => ['le' => 50],
        ]]]);

        try {
            $this->engine()->recommend('C-1', 'P-9', 51);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(422, $exception->upstreamStatus());
            $this->assertSame(422, $exception->statusForClient());
        }

        $this->assertSentTo('/api/v1/recommend', [
            'customer_id' => 'C-1',
            'product_id' => 'P-9',
            'top_k' => 51,
        ]);
    }

    // ------------------------------------------------------------------
    // headers
    // ------------------------------------------------------------------

    public function test_the_service_key_and_client_headers_are_on_every_request_including_the_last_of_a_chain(): void
    {
        $client = $this->engine();

        $client->health();
        $client->kpi(['granularity' => 'daily']);
        $client->commitImport(7, ['total' => 'revenue']);
        // The deep one: fourth call, long after the connection was built.
        $client->chat('Cabang mana yang paling turun?', 88, ['branch' => 'BR-03']);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/ai/chat')) {
                return false;
            }

            $this->assertTrue($request->hasHeader('X-Service-Key', 'test-service-key'));
            $this->assertTrue($request->hasHeader('X-Client', 'laravel-orchestrator'));
            // `asJson()`/`acceptJson()` on every call, including the GETs: the
            // engine's `require_service_auth` and its exception serialiser both
            // key off these.
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));

            return true;
        });

        // Not just the one matched above: every request recorded in the chain.
        $recorded = Http::recorded();

        $this->assertCount(4, $recorded);

        foreach ($recorded as [$request, $response]) {
            $this->assertTrue(
                $request->hasHeader('X-Service-Key', 'test-service-key'),
                'Missing service key on '.$request->method().' '.$request->url(),
            );
            $this->assertTrue(
                $request->hasHeader('X-Client', 'laravel-orchestrator'),
                'Missing client header on '.$request->method().' '.$request->url(),
            );
        }
    }

    public function test_the_service_key_header_name_is_configurable_and_is_sent_under_that_name(): void
    {
        // `SERVICE_API_KEY_HEADER` is read by both services; if the rename does
        // not survive to the wire the engine answers 401 and the operator is
        // sent looking for a key mismatch.
        config(['ai_engine.service_key_header' => 'X-Platform-Key']);

        $this->engine()->kpi();

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Platform-Key', 'test-service-key')
            && ! $request->hasHeader('X-Service-Key'));
    }

    // ------------------------------------------------------------------
    // failure mapping
    // ------------------------------------------------------------------

    /**
     * The status the caller sees is `statusForClient()`, the message is what
     * `bootstrap/app.php` renders into the JSON body or `errors.engine`.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function upstreamFailure(): array
    {
        return [
            'bad request' => [400, 422, 'AI engine request failed with status 400.'],
            'unauthorised' => [401, 502, 'AI engine rejected the service key. Check SERVICE_API_KEY matches on both services.'],
            'forbidden' => [403, 502, 'AI engine rejected the service key. Check SERVICE_API_KEY matches on both services.'],
            'not found' => [404, 404, 'AI engine endpoint not found. Check AI_ENGINE_URL and the engine version.'],
            'conflict' => [409, 422, 'AI engine request failed with status 409.'],
            'unprocessable' => [422, 422, 'AI engine rejected the request payload.'],
            'rate limited' => [429, 429, 'AI engine rate limit reached. Retry shortly.'],
            // The 5xx wording is the specific server-error text. It was the
            // generic fallback for a long time because `errorMessage()` had a
            // `$status >= 500 => '…'` arm that `match()` compared with `===`
            // against a bool, so it could never fire.
            'server error' => [500, 502, 'AI engine returned a server error (500).'],
            'bad gateway' => [502, 502, 'AI engine returned a server error (502).'],
            'unavailable' => [503, 503, 'AI engine returned a server error (503).'],
        ];
    }

    #[DataProvider('upstreamFailure')]
    public function test_an_upstream_status_maps_to_the_status_and_message_a_user_sees(int $upstream, int $client, string $message): void
    {
        // An empty body: this asserts the fallback wording, not the engine's.
        $this->force($upstream, []);

        try {
            $this->engine()->model(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame($upstream, $exception->upstreamStatus());
            $this->assertSame($client, $exception->statusForClient());
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('models.show', $exception->operation());
        }
    }

    public function test_the_engines_own_detail_for_a_401_is_read_and_not_replaced_by_the_generic_hint(): void
    {
        // `app/core/security.py::require_service_auth` raises `HTTPException`,
        // which FastAPI serialises as `{"detail": "..."}` — not the
        // `{success: false, error: {message}}` envelope the client looks for
        // first. That string is the only thing naming the real problem, so it
        // must survive instead of the generic "check SERVICE_API_KEY" text.
        $this->force(401, ['detail' => 'Missing or invalid credentials. Provide X-Service-Key or Bearer token.']);

        try {
            $this->engine()->model(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(
                'Missing or invalid credentials. Provide X-Service-Key or Bearer token.',
                $exception->getMessage()
            );
            $this->assertSame(401, $exception->upstreamStatus());
            $this->assertSame(502, $exception->statusForClient());
        }
    }

    public function test_a_pydantic_validation_detail_list_names_the_field_and_the_reason(): void
    {
        // Every FastAPI `RequestValidationError` answers with `detail` as a LIST
        // of objects. The client flattens it, so a rejected payload tells the
        // operator which field and why instead of only "the payload was
        // rejected".
        $this->force(422, ['detail' => [[
            'type' => 'missing',
            'loc' => ['body', 'horizon'],
            'msg' => 'Field required',
            'input' => null,
        ]]]);

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(
                'AI engine rejected the request payload — horizon: Field required',
                $exception->getMessage(),
            );
            $this->assertSame(422, $exception->statusForClient());
        }
    }

    public function test_a_connection_failure_surfaces_as_503_with_the_unreachable_message(): void
    {
        // The real transport raises a Guzzle `ConnectException`, which
        // `PendingRequest` marshals into its own `ConnectionException` — the
        // only type the client catches. Faking the Guzzle class exercises that
        // marshalling; faking the Laravel one would skip it entirely.
        Http::fake(fn () => throw new ConnectException(
            'cURL error 7: Failed to connect to fastapi port 8000: Connection refused',
            new PsrRequest('GET', 'http://fastapi.test/api/v1/analytics/kpi'),
        ));

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(503, $exception->upstreamStatus());
            $this->assertSame(503, $exception->statusForClient());
            $this->assertSame(
                'AI engine unreachable at http://fastapi.test. Is the fastapi service running?',
                $exception->getMessage()
            );
            $this->assertSame('analytics.kpi', $exception->operation());
        }
    }

    public function test_an_html_error_page_from_a_reverse_proxy_becomes_a_message_and_not_a_parse_error(): void
    {
        // In front of the engine there is nginx. When it answers 502 the body is
        // an HTML error page, `json()` returns null, and the only way out is the
        // status-based fallback — an exception about parsing JSON here would
        // hide a proxy problem behind a stack trace.
        $this->force(
            502,
            "<!DOCTYPE html>\n<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>",
            ['Content-Type' => 'text/html'],
        );

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame('AI engine returned a server error (502).', $exception->getMessage());
            $this->assertSame(502, $exception->upstreamStatus());
            $this->assertSame(502, $exception->statusForClient());
            $this->assertSame('analytics.kpi', $exception->operation());
        }
    }

    public function test_a_non_json_two_hundred_becomes_an_empty_array_and_not_a_parse_error(): void
    {
        // The same proxy, on a path it serves itself: a 200 that is not the
        // engine's answer at all. `unwrap()` answers `[]`, so a page renders
        // "no data" rather than reporting that it never reached the engine.
        $this->force(200, '<html><body>maintenance</body></html>', ['Content-Type' => 'text/html']);

        $this->assertSame([], $this->engine()->kpi());
    }

    // ------------------------------------------------------------------
    // envelope edge cases
    // ------------------------------------------------------------------

    public function test_a_two_hundred_with_success_false_and_no_error_key_is_still_a_failure(): void
    {
        $this->force(200, ['success' => false]);

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            // No `error`, no `detail`, no `message` anywhere in the body, so the
            // fallback quotes the HTTP status — which was 200. The status the
            // client sees is still 422, so the page fails loudly, but the text
            // it shows is nonsense.
            $this->assertSame('AI engine request failed with status 200.', $exception->getMessage());
            $this->assertSame(422, $exception->upstreamStatus());
            $this->assertSame(422, $exception->statusForClient());
        }
    }

    public function test_a_two_hundred_envelope_with_no_data_key_unwraps_to_an_empty_array(): void
    {
        $this->force(200, ['success' => true]);

        $this->assertSame([], $this->engine()->kpi());
    }

    public function test_a_body_without_a_success_key_passes_through_unchanged(): void
    {
        // `ai/chat` is declared `response_model=dict`, so the envelope is not
        // guaranteed on every route: a bare dict has to survive as-is.
        $this->force(200, ['answer' => 'BR-03 turun 12%', 'steps' => 2]);

        $this->assertSame(
            ['answer' => 'BR-03 turun 12%', 'steps' => 2],
            $this->engine()->chat('Branches mana yang paling turun?'),
        );
    }

    // ------------------------------------------------------------------
    // health / readiness: the asymmetry, in both directions
    // ------------------------------------------------------------------

    public function test_health_and_readiness_return_the_bare_body_and_not_the_data_key(): void
    {
        $client = $this->engine();

        $this->assertSame(
            ['status' => 'ok', 'app' => 'ai-engine', 'env' => 'testing', 'version' => '1.0.0'],
            $client->health(),
        );
        $this->assertSame(
            ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
            $client->readiness(),
        );
    }

    public function test_health_never_unwraps_an_envelope_even_when_the_engine_sends_one(): void
    {
        // Direction one: `health()`/`readiness()` go through `decode()`, not
        // `unwrap()`. A test in this repo once asserted the opposite, so the
        // behaviour is pinned from both ends: even handed an envelope, the bare
        // pair returns it whole, envelope keys and all.
        $this->force(200, ['success' => true, 'data' => ['status' => 'ok']]);

        $this->assertSame(
            ['success' => true, 'data' => ['status' => 'ok']],
            $this->engine()->health(),
        );
    }

    public function test_every_other_endpoint_unwraps_the_very_envelope_health_returns_whole(): void
    {
        // Direction two: the same bytes through an enveloped endpoint collapse
        // to `data`. If the two ever swap, one of these two tests fails.
        $this->force(200, ['success' => true, 'data' => ['status' => 'ok']]);

        $this->assertSame(['status' => 'ok'], $this->engine()->kpi());
    }

    public function test_health_reports_the_http_status_but_not_an_envelope_failure(): void
    {
        // The other side of `decode()`: it checks the status and ignores the
        // envelope, so an enveloped failure would be reported as a healthy
        // body. The engine cannot do that today (`health.py` returns a
        // `HealthResponse`), and this pins what would happen if it ever did.
        $this->force(200, ['success' => false, 'error' => ['message' => 'database is down']]);

        $this->assertSame(
            ['success' => false, 'error' => ['message' => 'database is down']],
            $this->engine()->health(),
        );
    }

    // ------------------------------------------------------------------
    // what the failure looks like from outside the service
    // ------------------------------------------------------------------

    public function test_an_upstream_failure_reaches_the_api_caller_as_the_status_and_message_laravel_renders(): void
    {
        $this->force(401, ['detail' => 'Missing or invalid credentials.']);
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->getJson(route('api.ml.models'))
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_engine_error')
            ->assertJsonPath('operation', 'models.list')
            ->assertJsonPath('message', 'Missing or invalid credentials.');
    }

    /**
     * Asserts the body of the most recent request to `$path`, so a field that
     * stops being forwarded fails here instead of being rejected by the engine
     * at runtime.
     *
     * @param  array<string, mixed>  $expected
     */
    protected function assertSentTo(string $path, array $expected): void
    {
        $last = Http::recorded()->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), $path)
        )->last();

        $this->assertNotNull($last, "No request was sent to {$path}.");
        $this->assertSame($expected, $last[0]->data(), "Wrong body for {$path}.");
    }
}
