<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shape contract for every `app/Http/Controllers/Api` action.
 *
 * `ApiContractTest` reads `docs/api.md` and checks the routes exist, the roles
 * line up and the status codes are the documented ones. This class covers the
 * half the document cannot state: the *shape* of each response body, the
 * `errors` key on a 422, and — the part that matters most — the fields that
 * must never leave the process.
 *
 * That last group is the regression this file exists for. A presenter's job is
 * to decide what a client gets; the moment one action returns a raw model or
 * a raw engine payload, a storage `disk`/`path`, a `checksum_sha256` or the
 * `metadata.validation` blob is one `->toArray()` away from the wire, and no
 * test notices until it is in a production response body. Every response
 * below is scanned recursively for those keys rather than spot-checked, so a
 * new leak anywhere in a nested payload fails here.
 *
 * Expectations are the *exact* key sets, not subsets: an added key is a
 * contract change and has to be deliberate, and `assertJsonStructure` cannot
 * see one.
 */
class ApiControllerContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Keys that must never appear anywhere in an API response, at any depth.
     *
     * `disk`/`path`/`stored_path`/`artifact_path` are the server's filesystem
     * layout; `checksum_sha256` fingerprints an uploaded business file; and
     * `validation` is the engine's raw upload-validator report, which carries
     * the checksum inside `meta` plus the engine's own stored filename.
     */
    private const FORBIDDEN_KEYS = [
        'disk',
        'path',
        'stored_path',
        'artifact_path',
        'checksum_sha256',
        'validation',
    ];

    /** A checksum that must not survive into any response body. */
    private const CANARY_CHECKSUM = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const STORED_PATH = '/code/datasets/9f2c1a_salaries_q1.csv';

    /**
     * Engine state, read at request time rather than re-faked per test: stub
     * callbacks are matched first-registered-wins, so a `Http::fake()` in a test
     * body could never override the one closure registered in `setUp()`.
     */
    protected ?int $engineStatus = null;

    protected bool $engineDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeEngine();
    }

    // ------------------------------------------------------------------
    // the leak regression
    // ------------------------------------------------------------------

    /**
     * One closure stub rather than a URL map: `Http::fake()` merges stubs
     * first-registered-wins, and every case below needs a different engine
     * payload, so the variation has to live inside the closure.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->engineDown) {
                throw new ConnectionException('connection refused');
            }

            $url = $request->url();

            if ($this->engineStatus !== null) {
                return Http::response(['error' => ['message' => 'boom']], $this->engineStatus);
            }

            // The engine's upload answer, verbatim: `stored_path` is the
            // server's own path and `meta.checksum_sha256` fingerprints the
            // upload. Both are persisted into `datasets.metadata.validation`.
            $validation = [
                'ok' => true,
                'errors' => [],
                'warnings' => [],
                'row_errors' => [],
                'meta' => [
                    'filename' => '9f2c1a_salaries_q1.csv',
                    'size_bytes' => 2048,
                    'mime' => 'text/csv',
                    'extension' => '.csv',
                    'checksum_sha256' => self::CANARY_CHECKSUM,
                ],
            ];

            $payload = match (true) {
                str_ends_with($url, '/api/v1/imports/upload') => [
                    'upload_id' => 1,
                    'import_job_id' => 4242,
                    'validation' => $validation,
                    'stored_path' => self::STORED_PATH,
                ],
                str_ends_with($url, '/api/v1/imports/quality/4242') => [
                    'score' => 0.91,
                    'breakdown' => [
                        'completeness' => 0.95,
                        'uniqueness' => 0.88,
                        'validity' => 0.93,
                        'consistency' => 0.9,
                    ],
                    'issues' => [],
                    'passed' => true,
                ],
                str_ends_with($url, '/api/v1/imports/commit') => [
                    'import_job_id' => 4242,
                    'status' => 'queued',
                ],
                // A job that has not been profiled yet still reports the
                // upload-validator blob, checksum and all, as its `report`.
                str_ends_with($url, '/api/v1/imports/jobs/4242') => [
                    'id' => 4242,
                    'status' => 'uploaded',
                    'progress' => 0.0,
                    'total_rows' => 0,
                    'processed_rows' => 0,
                    'error_rows' => 0,
                    'report' => $validation,
                ],
                str_ends_with($url, '/api/v1/training/train') => [
                    'model_id' => 7,
                    'version_id' => 3,
                    'version' => 'v1',
                    'metrics' => ['mape' => 0.081],
                    'status' => 'VALIDATED',
                ],
                str_ends_with($url, '/promote') => [
                    'model_id' => 7,
                    'version_id' => 3,
                    'status' => 'PRODUCTION',
                ],
                preg_match('#/api/v1/models/\d+$#', $url) === 1 => [
                    'id' => 7,
                    'name' => 'forecast_penjualan_harian',
                    'model_type' => 'forecast',
                    'status' => 'PRODUCTION',
                    'versions' => [[
                        'id' => 3,
                        'version' => 'v3',
                        'status' => 'PRODUCTION',
                        'metrics' => ['mape' => 0.081],
                        'artifact_path' => '/code/models/forecast/v3.pkl',
                    ]],
                ],
                str_ends_with($url, '/api/v1/models') => [
                    ['id' => 7, 'name' => 'forecast_penjualan_harian', 'model_type' => 'forecast',
                        'status' => 'PRODUCTION', 'production_version_id' => 3],
                ],
                str_ends_with($url, '/api/v1/ai/chat') => [
                    'answer' => 'Pendapatan harian naik 4,2%.',
                    'conversation_id' => 88,
                    'evidence' => [['source' => 'fact_sales', 'score' => 0.91]],
                    'steps' => 3,
                ],
                str_ends_with($url, '/api/v1/rag/query') => [
                    'answer' => 'K技能的margin is 18%.',
                    'citations' => [['title' => 'laporan margin', 'score' => 0.87]],
                ],
                str_ends_with($url, '/api/v1/analytics/kpi') => [
                    'revenue' => 1250000.5, 'orders' => 412, 'units' => 980.25, 'aov' => 3033.25,
                ],
                str_ends_with($url, '/api/v1/analytics/branches') => [
                    ['branch' => 'BR-01', 'revenue' => 900000.0],
                ],
                str_ends_with($url, '/api/v1/analytics/finance') => [
                    'net_profit' => 220000.0, 'margin_pct' => 18.0,
                ],
                default => [],
            };

            return Http::response(['success' => true, 'data' => $payload], 200);
        });
    }

    /**
     * Every API response, one by one, scanned for the keys that must not be
     * on the wire. Grouped by the leak each case used to have so a failure
     * names the action rather than a bare key.
     */
    public function test_no_api_response_leaks_a_storage_path_a_checksum_or_the_raw_validation_blob(): void
    {
        $leaks = [];

        foreach ($this->everyApiResponse() as $label => [$method, $uri, $payload]) {
            $response = $method === 'GET'
                ? $this->getJson($uri, $payload)
                : $this->postJson($uri, $payload);

            $this->assertNotSame(500, $response->getStatusCode(), "{$label} returned a 500: ".$response->getContent());

            $found = $this->forbiddenKeysIn($response->json());

            if ($found !== []) {
                $leaks[] = sprintf('%s leaked [%s]', $label, implode(', ', $found));
            }

            // The raw body, not just the decoded structure: a value that
            // merely *contains* the server path or the checksum is the same
            // disclosure, and a key scan would not see it.
            $body = $response->getContent();

            foreach ([self::CANARY_CHECKSUM, self::STORED_PATH] as $secret) {
                if (str_contains($body, $secret)) {
                    $leaks[] = sprintf('%s leaked the literal %s', $label, $secret);
                }
            }
        }

        $this->assertSame([], $leaks, implode(PHP_EOL, $leaks));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function everyApiResponse(): array
    {
        $analyst = User::factory()->analyst()->create();
        $admin = User::factory()->admin()->create();

        $uploaded = $this->uploadDataset($analyst);
        $profiled = Dataset::factory()->committed()->create(['import_job_id' => 4242]);

        $responses = [
            'POST /api/login' => ['POST', '/api/login', [
                'email' => $analyst->email, 'password' => 'Secret123!', 'device_name' => 'phpunit',
            ]],
            'GET /api/me' => ['GET', '/api/me', []],
            'GET /api/health' => ['GET', '/api/health', []],
            'GET /api/datasets' => ['GET', '/api/datasets', ['dataset_type' => 'sales', 'q' => 'Penjualan']],
            'POST /api/datasets' => ['POST', '/api/datasets', []],
            'GET /api/datasets/{uuid}' => ['GET', '/api/datasets/'.$uploaded->uuid, []],
            'GET /api/datasets/{uuid}/quality' => ['GET', '/api/datasets/'.$uploaded->uuid.'/quality', []],
            'GET /api/import-jobs/{id}' => ['GET', '/api/import-jobs/4242', []],
            'GET /api/analytics/kpi' => ['GET', '/api/analytics/kpi', ['date_from' => '2026-01-01']],
            'GET /api/analytics/trend' => ['GET', '/api/analytics/trend', ['granularity' => 'daily']],
            'GET /api/analytics/rfm' => ['GET', '/api/analytics/rfm', []],
            'GET /api/analytics/abc' => ['GET', '/api/analytics/abc', []],
            'GET /api/analytics/cohort' => ['GET', '/api/analytics/cohort', []],
            'GET /api/analytics/branches' => ['GET', '/api/analytics/branches', []],
            'GET /api/analytics/finance' => ['GET', '/api/analytics/finance', []],
            'GET /api/ml/models' => ['GET', '/api/ml/models', []],
            'GET /api/ml/models/{id}' => ['GET', '/api/ml/models/7', []],
            'POST /api/agent/chat' => ['POST', '/api/agent/chat', ['message' => 'Bagaimana margin Q1?']],
            'POST /api/rag/query' => ['POST', '/api/rag/query', ['question' => 'Apa margin Q1?', 'top_k' => 5]],
        ];

        // `mapping`, `commit`, `destroy`, `train` and `promote` mutate, so each
        // runs against its own row: they are walked after the reads above, in
        // the same order, with the same leak scan.
        Sanctum::actingAs($analyst);
        $responses['POST /api/datasets/{uuid}/mapping'] = ['POST', '/api/datasets/'.$profiled->uuid.'/mapping', [
            'mappings' => ['transaction_date' => 'transaction_date'],
        ]];
        $responses['POST /api/datasets/{uuid}/commit'] = ['POST', '/api/datasets/'.$profiled->uuid.'/commit', [
            'run_async' => true,
        ]];

        Sanctum::actingAs($admin);
        $responses['POST /api/ml/train'] = ['POST', '/api/ml/train', [
            'model_type' => 'forecast', 'name' => 'forecast_penjualan_harian',
        ]];
        $responses['POST /api/ml/models/{id}/promote'] = ['POST', '/api/ml/models/7/promote', [
            'version_id' => 3, 'to_status' => 'PRODUCTION',
        ]];
        $responses['POST /api/logout'] = ['POST', '/api/logout', []];

        Sanctum::actingAs($analyst);
        $responses['DELETE /api/datasets/{uuid}'] = ['DELETE', '/api/datasets/'.$uploaded->uuid, []];

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, string>
     */
    private function forbiddenKeysIn(array $json): array
    {
        $found = [];

        array_walk_recursive($json, function (mixed $value, mixed $key) use (&$found): void {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                $found[] = $key;
            }
        });

        sort($found);

        return array_values(array_unique($found));
    }

    // ------------------------------------------------------------------
    // top-level envelopes
    // ------------------------------------------------------------------

    public function test_auth_and_meta_responses_carry_the_documented_top_level_keys(): void
    {
        $user = User::factory()->admin()->create(['password' => bcrypt('Secret123!')]);

        $this->assertSame(['data'], $this->keysOf(
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertOk()
        ), 'POST /api/login');

        Sanctum::actingAs($user);

        $this->assertSame(['data'], $this->keysOf($this->getJson('/api/me')->assertOk()), 'GET /api/me');
        $this->assertSame(['data'], $this->keysOf($this->getJson('/api/health')->assertOk()), 'GET /api/health');

        // `ApiResponse::message()`: a fourth envelope shape the doc's
        // "Envelope conventions" section does not list. Pinned here so a
        // change to it is deliberate.
        $this->assertSame(['message'], $this->keysOf($this->postJson('/api/logout')->assertOk()), 'POST /api/logout');
    }

    public function test_the_login_payload_carries_exactly_the_documented_user_keys(): void
    {
        $user = User::factory()->analyst()->create(['password' => bcrypt('Secret123!')]);

        $data = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()->json('data');

        $this->assertSame(['token', 'user'], $this->sortedKeys($data));
        $this->assertSame(
            ['email', 'id', 'is_active', 'last_login_at', 'name', 'role', 'role_label'],
            $this->sortedKeys($data['user']),
        );

        Sanctum::actingAs($user);

        $this->assertSame(
            $this->sortedKeys($data['user']),
            $this->sortedKeys($this->getJson('/api/me')->assertOk()->json('data')),
            'GET /api/me must present the user exactly as POST /api/login does.',
        );
    }

    public function test_the_dataset_list_carries_the_documented_list_envelope_and_meta_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        Dataset::factory()->count(2)->create();

        $payload = $this->getJson('/api/datasets')->assertOk()->json();

        $this->assertSame(['data', 'meta', 'query'], $this->sortedKeys($payload));
        $this->assertSame(['last_page', 'page', 'per_page', 'total'], $this->sortedKeys($payload['meta']));
        $this->assertIsList($payload['data']);
    }

    public function test_the_dataset_index_and_show_agree_on_the_presented_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->committed()->create();

        $indexed = $this->getJson('/api/datasets')->assertOk()->json('data.0');
        $shown = $this->getJson('/api/datasets/'.$dataset->uuid)->assertOk()->json('data');

        // `show` adds the detailed keys and nothing else: a row must not
        // change shape between the list and the detail view.
        $this->assertSame(
            array_values(array_diff($this->sortedKeys($shown), ['columns', 'mappings', 'metadata'])),
            $this->sortedKeys($indexed),
        );
        $this->assertSame(['columns', 'mappings', 'metadata'], array_values(array_diff(
            $this->sortedKeys($shown), $this->sortedKeys($indexed),
        )));
    }

    public function test_the_dataset_payload_carries_exactly_the_presented_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->committed()->create();

        $this->assertSame(
            ['column_count', 'columns', 'committed_at', 'created_at', 'dataset_type', 'id', 'import_job_id',
                'mappings', 'metadata', 'name', 'quality_score', 'quality_verdict', 'row_count',
                'size_bytes', 'source_filename', 'status'],
            $this->sortedKeys($this->getJson('/api/datasets/'.$dataset->uuid)->assertOk()->json('data')),
        );
    }

    public function test_a_created_dataset_answers_201_with_the_same_presented_keys_as_the_index(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $payload = $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('gaji.csv', "kode,nilai\nA,1\n"),
            'dataset_type' => 'expenses',
        ])->assertCreated()->json('data');

        $this->assertSame('expenses', $payload['dataset_type']);
        $this->assertSame(4242, $payload['import_job_id']);

        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->assertSame(
            $this->sortedKeys($this->getJson('/api/datasets')->json('data.0')),
            $this->sortedKeys($payload),
        );
    }

    public function test_the_quality_payload_carries_exactly_the_documented_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = $this->uploadDataset(User::factory()->analyst()->create());

        $payload = $this->getJson('/api/datasets/'.$dataset->uuid.'/quality')->assertOk()->json('data');

        $this->assertSame(
            ['checks', 'dataset_id', 'issues', 'profiled_at', 'score', 'threshold', 'verdict'],
            $this->sortedKeys($payload),
        );
        $this->assertSame($dataset->uuid, $payload['dataset_id']);
    }

    public function test_the_mapping_response_is_the_dataset_presenter_not_a_raw_model(): void
    {
        $analyst = User::factory()->analyst()->create();
        Sanctum::actingAs($analyst);
        $dataset = Dataset::factory()->create([
            'import_job_id' => 4242,
            'columns' => [['name' => 'transaction_date']],
        ]);

        $payload = $this->postJson('/api/datasets/'.$dataset->uuid.'/mapping', [
            'mappings' => ['transaction_date' => 'transaction_date'],
        ])->assertOk()->json('data');

        $this->assertSame(
            $this->sortedKeys($this->getJson('/api/datasets')->json('data.0')),
            $this->sortedKeys($payload),
            'Mapping returns the same presenter the index uses, not a raw model.',
        );
    }

    public function test_the_commit_payload_carries_exactly_the_documented_keys_and_answers_202(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->create(['import_job_id' => 4242]);

        $response = $this->postJson('/api/datasets/'.$dataset->uuid.'/commit', ['run_async' => true])->assertStatus(202);

        $this->assertSame(['data'], $this->keysOf($response));
        $this->assertSame(['import_job_id', 'result', 'status'], $this->sortedKeys($response->json('data')));
    }

    public function test_the_import_job_payload_carries_exactly_the_keys_the_doc_lists(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $payload = $this->getJson('/api/import-jobs/4242')->assertOk()->json('data');

        $this->assertSame([
            'error', 'error_rows', 'job_id', 'processed_rows', 'progress',
            'report', 'status', 'total_rows', 'type',
        ], $this->sortedKeys($payload));
        $this->assertSame(4242, $payload['job_id']);
        $this->assertSame('import', $payload['type']);
    }

    public function test_the_analytics_health_payload_carries_exactly_its_two_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->assertSame(['app', 'engine'], $this->sortedKeys($this->getJson('/api/health')->assertOk()->json('data')));
    }

    public function test_the_agent_and_rag_payloads_carry_exactly_the_documented_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $chat = $this->postJson('/api/agent/chat', ['message' => 'Bagaimana margin Q1?'])->assertOk()->json('data');
        $this->assertSame(
            ['answer', 'conversation_id', 'evidence', 'reply', 'steps'],
            $this->sortedKeys($chat),
        );
        $this->assertSame($chat['answer'], $chat['reply'], '`reply` is documented as an alias of `answer`.');

        $rag = $this->postJson('/api/rag/query', ['query' => 'Apa margin Q1?'])->assertOk()->json('data');
        $this->assertSame(['answer', 'citations'], $this->sortedKeys($rag));
    }

    public function test_the_ml_payloads_carry_exactly_their_documented_keys(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->assertIsList($this->getJson('/api/ml/models')->assertOk()->json('data'));

        $model = $this->getJson('/api/ml/models/7')->assertOk()->json('data');
        $this->assertSame(['id', 'model_type', 'name', 'status', 'versions'], $this->sortedKeys($model));
        $this->assertSame(
            ['id', 'metrics', 'status', 'version'],
            $this->sortedKeys($model['versions'][0]),
            'A model version must not carry the artifact path it was loaded from.',
        );

        $this->assertSame(['data'], $this->keysOf(
            $this->postJson('/api/ml/train', ['model_type' => 'forecast', 'name' => 'forecast_harian'])->assertStatus(202),
        ));
        $this->assertSame(['data'], $this->keysOf(
            $this->postJson('/api/ml/models/7/promote', ['version_id' => 3])->assertOk(),
        ));
    }

    // ------------------------------------------------------------------
    // 422 carries `errors` keyed by the field the client sent
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<int, string>}>
     */
    public static function validationCases(): array
    {
        return [
            'login: missing email' => ['POST', '/api/login', ['password' => 'Secret123!'], ['email']],
            'login: bad email' => ['POST', '/api/login', ['email' => 'not-an-email', 'password' => 'x'], ['email']],
            'datasets: missing file' => ['POST', '/api/datasets', ['dataset_type' => 'sales'], ['file']],
            'datasets: missing dataset_type' => ['POST', '/api/datasets', ['file' => 'x'], ['dataset_type']],
            'ml/train: bad model_type' => ['POST', '/api/ml/train', ['model_type' => 'prophet', 'name' => 'n'], ['model_type']],
            'ml/train: missing name' => ['POST', '/api/ml/train', ['model_type' => 'forecast'], ['name']],
            'ml/promote: missing version_id' => ['POST', '/api/ml/models/7/promote', [], ['version_id']],
            'ml/promote: bad to_status' => ['POST', '/api/ml/models/7/promote', [
                'version_id' => 3, 'to_status' => 'production',
            ], ['to_status']],
            'agent: missing message' => ['POST', '/api/agent/chat', [], ['message']],
            'agent: over-long message' => ['POST', '/api/agent/chat', ['message' => str_repeat('a', 4001)], ['message']],
            'rag: neither question nor query' => ['POST', '/api/rag/query', [], ['question', 'query']],
            'rag: top_k out of range' => ['POST', '/api/rag/query', ['question' => 'q', 'top_k' => 99], ['top_k']],
            'analytics: bad granularity' => ['GET', '/api/analytics/kpi', ['granularity' => 'hourly'], ['granularity']],
            'analytics: bad date_from' => ['GET', '/api/analytics/trend', ['date_from' => 'yesterday'], ['date_from']],
            'import-jobs: non-numeric id' => ['GET', '/api/import-jobs/not-a-number', [], ['importJobId']],
            'ml/models: non-numeric id' => ['GET', '/api/ml/models/not-a-number', [], ['modelId']],
        ];
    }

    /**
     * @param  string  $method  `GET` sends `$payload` as query string, anything else as a JSON body.
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validationCases')]
    public function test_a_422_carries_errors_keyed_by_the_field_the_client_sent(
        string $method,
        string $uri,
        array $payload,
        array $fields,
    ): void {
        Sanctum::actingAs(User::factory()->admin()->create());

        // `getJson()`'s second argument is headers, not a query string, so the
        // filters have to be built into the URI.
        $response = $method === 'GET'
            ? $this->getJson($payload === [] ? $uri : $uri.'?'.http_build_query($payload))
            : $this->postJson($uri, $payload);

        $response->assertStatus(422);

        $body = $response->json();

        $this->assertArrayHasKey('errors', $body, "{$method} {$uri} answered 422 with no `errors` object.");
        $this->assertNotSame([], $body['errors'], "{$method} {$uri} answered 422 with an empty `errors` object.");

        foreach ($fields as $field) {
            $this->assertArrayHasKey(
                $field,
                $body['errors'],
                "{$method} {$uri} answered 422 without an `errors.{$field}` entry. Got: ".implode(', ', array_keys($body['errors'])),
            );
            $this->assertNotEmpty($body['errors'][$field], "{$method} {$uri} keyed `{$field}` with no message.");
        }
    }

    public function test_a_mapping_with_no_usable_column_is_a_422_keyed_by_mappings(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());
        $dataset = Dataset::factory()->create(['import_job_id' => 4242]);

        $body = $this->postJson('/api/datasets/'.$dataset->uuid.'/mapping', [
            'mappings' => ['transaction_date' => '   '],
        ])->assertStatus(422)->json();

        $this->assertArrayHasKey('mappings', $body['errors'],
            'An empty mapping set must be reported against the `mappings` field, not as a body with no `errors`.');
    }

    // ------------------------------------------------------------------
    // audit coverage on the mutating actions
    // ------------------------------------------------------------------

    public function test_every_mutating_api_action_writes_an_audit_row(): void
    {
        $analyst = User::factory()->analyst()->create();
        $admin = User::factory()->admin()->create(['password' => bcrypt('Secret123!')]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'Secret123!'])->assertOk();
        $this->assertTrue(
            AuditLog::query()->where('action', 'auth.api_login')->where('user_id', $admin->getKey())->exists(),
            'POST /api/login does not write an audit row.',
        );

        Sanctum::actingAs($analyst);
        $dataset = $this->uploadDataset($analyst);
        $this->assertTrue(AuditLog::query()->where('action', 'dataset.uploaded')->exists(),
            'POST /api/datasets does not write an audit row.');

        $this->getJson('/api/datasets/'.$dataset->uuid.'/quality')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'dataset.quality_checked')->exists(),
            'GET /api/datasets/{uuid}/quality mutates the row and must write an audit row.');

        $this->postJson('/api/datasets/'.$dataset->uuid.'/mapping', [
            'mappings' => ['transaction_date' => 'transaction_date'],
        ])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'dataset.mapping_applied')->exists(),
            'POST /api/datasets/{uuid}/mapping does not write an audit row.');

        $this->postJson('/api/datasets/'.$dataset->uuid.'/commit', ['run_async' => true])->assertStatus(202);
        $this->assertTrue(AuditLog::query()->where('action', 'dataset.committed')->exists(),
            'POST /api/datasets/{uuid}/commit does not write an audit row.');

        $this->postJson('/api/agent/chat', ['message' => 'Bagaimana margin Q1?'])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'agent.chat')->exists(),
            'POST /api/agent/chat does not write an audit row.');

        Sanctum::actingAs($admin);
        $this->postJson('/api/ml/train', ['model_type' => 'forecast', 'name' => 'forecast_harian'])->assertStatus(202);
        $this->assertTrue(AuditLog::query()->where('action', 'model.trained')->exists(),
            'POST /api/ml/train does not write an audit row.');

        $this->postJson('/api/ml/models/7/promote', ['version_id' => 3])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'model.promoted')->exists(),
            'POST /api/ml/models/{id}/promote does not write an audit row.');

        Sanctum::actingAs($analyst);
        $this->deleteJson('/api/datasets/'.$dataset->uuid)->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'dataset.deleted')->exists(),
            'DELETE /api/datasets/{uuid} does not write an audit row.');

        $this->postJson('/api/logout')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.api_logout')->exists(),
            'POST /api/logout does not write an audit row.');
    }

    public function test_an_api_audit_row_records_the_bearer_actor_not_the_web_guard(): void
    {
        $analyst = User::factory()->analyst()->create();

        Sanctum::actingAs($analyst);
        $this->postJson('/api/agent/chat', ['message' => 'Bagaimana margin Q1?'])->assertOk();

        $row = AuditLog::query()->where('action', 'agent.chat')->sole();

        $this->assertSame($analyst->getKey(), $row->user_id,
            'An API action recorded a null actor: the audit helper resolved the web guard, not sanctum.');
        $this->assertSame($analyst->email, $row->actor);
    }

    public function test_another_accounts_conversation_id_is_refused_and_audited_under_the_caller(): void
    {
        $owner = User::factory()->analyst()->create();
        $other = User::factory()->analyst()->create();

        ChatThread::factory()->create(['user_id' => $owner->getKey(), 'ai_conversation_id' => 88]);

        Sanctum::actingAs($other);
        $this->postJson('/api/agent/chat', ['message' => '?', 'conversation_id' => 88])
            ->assertStatus(422)
            ->assertJsonPath('errors.conversation_id.0', 'Unknown conversation for this account.');

        $this->assertSame(0, AuditLog::query()->where('action', 'agent.chat')->count(),
            'A refused conversation must not be audited as a completed turn.');
    }

    // ------------------------------------------------------------------
    // engine failures surface as the documented status, never a 500
    // ------------------------------------------------------------------

    public function test_engine_failures_surface_as_the_documented_status_not_a_500(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // Connection refused.
        $this->engineDown = true;

        foreach (['/api/analytics/kpi', '/api/import-jobs/4242'] as $uri) {
            $this->getJson($uri)
                ->assertStatus(503)
                ->assertJsonPath('code', 'ai_engine_error');
        }

        foreach (['/api/agent/chat', '/api/rag/query', '/api/ml/models'] as $uri) {
            $response = match ($uri) {
                '/api/agent/chat' => $this->postJson($uri, ['message' => 'hi']),
                '/api/rag/query' => $this->postJson($uri, ['question' => 'q']),
                default => $this->getJson($uri),
            };

            $response->assertStatus(503)->assertJsonPath('code', 'ai_engine_error');
        }

        // Engine 5xx and a rejected service key are both 502: the platform is
        // misconfigured, not the browser.
        $this->engineDown = false;

        foreach ([500, 502, 401] as $status) {
            $this->engineStatus = $status;

            $this->getJson('/api/analytics/kpi')
                ->assertStatus(502)
                ->assertJsonPath('code', 'ai_engine_error');
        }

        // Upstream 4xx is a client error and stays a 422.
        $this->engineStatus = 422;

        $this->getJson('/api/analytics/kpi')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_engine_error');
    }

    public function test_health_stays_200_with_the_engine_down(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->engineDown = true;

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.engine.status', 'unreachable');
    }

    public function test_an_unknown_import_job_is_404_not_a_500(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->engineStatus = 404;

        $this->getJson('/api/import-jobs/9999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_an_unknown_model_is_404_not_a_500(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->engineStatus = 404;

        $this->getJson('/api/ml/models/9999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    protected function uploadDataset(User $user): Dataset
    {
        Sanctum::actingAs($user);

        $this->post(route('api.datasets.store'), [
            'file' => UploadedFile::fake()->createWithContent('gaji.csv', "kode,nilai\nA,1\n"),
            'dataset_type' => 'expenses',
        ])->assertCreated();

        return Dataset::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, string>
     */
    private function keysOf(mixed $response): array
    {
        $json = $response instanceof \Illuminate\Testing\TestResponse ? $response->json() : $response;

        $this->assertIsArray($json, 'Expected a JSON object at the top level of the response.');

        return $this->sortedKeys($json);
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array<int, string>
     */
    private function sortedKeys(array $keys): array
    {
        $keys = array_keys($keys);
        sort($keys);

        return array_values($keys);
    }
}
