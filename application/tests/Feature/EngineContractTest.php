<?php

namespace Tests\Feature;

use App\Exceptions\AiEngineException;
use App\Models\User;
use App\Services\AiEngineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the assumptions the Laravel orchestrator makes about the FastAPI engine.
 *
 * Every fake in this file is copied from `ai-engine/app/api/v1/*.py` and
 * `ai-engine/app/schemas/*.py`, so an engine change that breaks the contract
 * fails here rather than silently degrading a page in production.
 */
class EngineContractTest extends TestCase
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
     * promise, and stub callbacks are resolved first-registered-wins, so a
     * second `Http::fake()` in a test could never override a map registered in
     * `setUp()`. Per-test variation goes through `$forcedResponse`.
     */
    protected function fakeEngine(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($this->forcedResponse !== null) {
                return Http::response($this->forcedResponse['body'], $this->forcedResponse['status']);
            }

            $url = $request->url();

            // `/health` and `/readiness` are the two endpoints that answer with a
            // bare pydantic model / dict instead of the `{success, data}`
            // envelope. `health.py` declares `response_model=HealthResponse` for
            // the first and returns a plain dict for the second.
            if (str_ends_with($url, '/api/v1/health')) {
                return Http::response($this->healthPayload(), 200);
            }

            if (str_ends_with($url, '/api/v1/readiness')) {
                return Http::response(
                    ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
                    200,
                );
            }

            return Http::response(['success' => true, 'data' => $this->engineData($url)], 200);
        });
    }

    protected function engine(): AiEngineClient
    {
        return AiEngineClient::fromConfig();
    }

    /** @return array<string, mixed> */
    protected function engineData(string $url): array
    {
        return match (true) {
            str_ends_with($url, '/api/v1/models') => $this->modelList(),
            str_ends_with($url, '/promote') => $this->promotionResult(),
            (bool) preg_match('#/api/v1/models/\d+$#', $url) => $this->modelDetail(),
            str_ends_with($url, '/api/v1/training/train') => $this->trainingResult(),
            str_ends_with($url, '/api/v1/ai/chat') => $this->chatResult(),
            str_ends_with($url, '/api/v1/ai/report') => $this->executiveSummary(),
            str_ends_with($url, '/api/v1/rag/query') => $this->ragResult(),
            str_ends_with($url, '/api/v1/readiness') => ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
            default => [],
        };
    }

    /** `GET /api/v1/health` -> `HealthResponse`, bare, not enveloped. */
    protected function healthPayload(): array
    {
        return ['status' => 'ok', 'app' => 'ai-engine', 'env' => 'testing', 'version' => '1.0.0'];
    }

    /** `GET /api/v1/models` -> a list, one row per registry model. */
    protected function modelList(): array
    {
        return [
            [
                'id' => 7,
                'name' => 'forecast_penjualan_harian',
                'model_type' => 'forecast',
                'status' => 'PRODUCTION',
                'production_version_id' => 3,
            ],
        ];
    }

    /** `GET /api/v1/models/{id}` -> one model plus its `versions` list. */
    protected function modelDetail(): array
    {
        return [
            'id' => 7,
            'name' => 'forecast_penjualan_harian',
            'model_type' => 'forecast',
            'status' => 'PRODUCTION',
            'versions' => [
                [
                    'id' => 3,
                    'version' => 'v3',
                    'status' => 'PRODUCTION',
                    'metrics' => ['mape' => 0.081],
                    'artifact_path' => '/data/artifacts/forecast/v3.pkl',
                ],
            ],
        ];
    }

    /** `POST /api/v1/training/train` -> `TrainResponse`. */
    protected function trainingResult(): array
    {
        return [
            'model_id' => 7,
            'version_id' => 3,
            'version' => 'v1',
            'metrics' => ['mape' => 0.081],
            'status' => 'VALIDATED',
        ];
    }

    /** `POST /api/v1/models/{id}/promote` -> `app/ml/registry.py::promote`. */
    protected function promotionResult(): array
    {
        return ['model_id' => 7, 'version_id' => 3, 'status' => 'PRODUCTION'];
    }

    /** `POST /api/v1/ai/chat` -> `app/ai/agent.py::run_agent`. */
    protected function chatResult(): array
    {
        return [
            'answer' => 'Cabang BR-03 turun 12% dibanding minggu lalu.',
            'conversation_id' => 88,
            'evidence' => [['source' => 'query_sales', 'data' => ['rows' => []]]],
            'steps' => 3,
        ];
    }

    /** `POST /api/v1/ai/report` -> `app/ai/reporting.py::executive_summary`. */
    protected function executiveSummary(): array
    {
        return [
            'period' => 'weekly',
            'kpi' => ['revenue' => 1250000.0, 'orders' => 412],
            'finance' => ['net_profit' => 178000.0, 'margin_pct' => 14.24],
            'narrative' => 'Penjualan naik 4,2%.',
            'sections' => ['highlight' => ['Pendapatan naik 4,2%.']],
            'html' => '<!DOCTYPE html><html lang="id"></html>',
            'degraded' => false,
        ];
    }

    /** `POST /api/v1/rag/query` -> `app/ai/rag.py::query`. */
    protected function ragResult(): array
    {
        return [
            'answer' => 'Retensi 90 hari dijaga lewat voucher.',
            'citations' => [
                ['content' => 'Voucher dikirim pada hari ke-60.', 'score' => 0.83, 'document_id' => 4, 'chunk_index' => 0],
            ],
            'chunks' => [
                ['content' => 'Voucher dikirim pada hari ke-60.', 'score' => 0.83, 'document_id' => 4, 'chunk_index' => 0],
            ],
            'n_results' => 1,
        ];
    }

    // ------------------------------------------------------------------
    // health: the one unwrapped endpoint
    // ------------------------------------------------------------------

    public function test_health_returns_the_bare_engine_payload_without_unwrapping_it(): void
    {
        $health = $this->engine()->health();

        $this->assertSame($this->healthPayload(), $health);
        $this->assertSame('ok', $health['status']);
        $this->assertArrayNotHasKey('data', $health);
    }

    public function test_health_is_requested_from_the_documented_path(): void
    {
        $this->engine()->health();

        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'http://fastapi.test/api/v1/health'
            && $r->method() === 'GET');
    }

    public function test_a_five_hundred_health_throws_instead_of_returning_an_empty_array(): void
    {
        $this->forcedResponse = ['status' => 500, 'body' => ['detail' => 'engine crashed on startup']];

        try {
            $this->engine()->health();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(500, $exception->upstreamStatus());
            $this->assertSame('engine crashed on startup', $exception->getMessage());
        }
    }

    public function test_readiness_is_also_returned_unwrapped(): void
    {
        $this->assertSame(
            ['ready' => true, 'checks' => ['db' => 'up', 'redis' => 'up']],
            $this->engine()->readiness()
        );
    }

    // ------------------------------------------------------------------
    // failure envelopes
    // ------------------------------------------------------------------

    public function test_a_failed_envelope_becomes_an_exception_carrying_the_engine_message(): void
    {
        $this->forcedResponse = ['status' => 200, 'body' => [
            'success' => false,
            'error' => ['message' => 'no production churn model'],
        ]];

        $this->expectException(AiEngineException::class);
        $this->expectExceptionMessage('no production churn model');

        $this->engine()->predict('churn', 'churn-model');
    }

    public function test_the_fastapi_detail_shape_is_read_by_the_error_message(): void
    {
        // `app/core/security.py::require_service_auth` raises `HTTPException`,
        // which FastAPI serialises as `{"detail": "..."}` rather than the
        // `{success: false, error: {message}}` envelope.
        $this->forcedResponse = ['status' => 401, 'body' => [
            'detail' => 'Missing or invalid credentials. Provide X-Service-Key or Bearer token.',
        ]];

        try {
            $this->engine()->model(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame('Missing or invalid credentials. Provide X-Service-Key or Bearer token.', $exception->getMessage());
            $this->assertSame(401, $exception->upstreamStatus());
        }
    }

    public function test_a_404_with_no_message_falls_back_to_the_generic_endpoint_hint(): void
    {
        $this->forcedResponse = ['status' => 404, 'body' => []];

        try {
            $this->engine()->model(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(
                'AI engine endpoint not found. Check AI_ENGINE_URL and the engine version.',
                $exception->getMessage()
            );
        }
    }

    /**
     * `app/api/v1/models.py::get_model` reports a missing model as a `200` with
     * `{"success": false, ...}`, never as an HTTP 404, so the client sees an
     * envelope failure and reports 422 rather than 404.
     */
    public function test_a_missing_model_is_an_envelope_failure_not_an_http_404(): void
    {
        $this->forcedResponse = ['status' => 200, 'body' => [
            'success' => false,
            'error' => ['message' => 'model not found'],
        ]];

        try {
            $this->engine()->model(999);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame('model not found', $exception->getMessage());
            $this->assertSame(422, $exception->upstreamStatus());
            $this->assertSame(422, $exception->statusForClient());
        }
    }

    /** @return array<string, array{int, int}> */
    public static function upstreamStatusMapping(): array
    {
        return [
            'unauthorised' => [401, 502],
            'forbidden' => [403, 502],
            'not found' => [404, 404],
            'unprocessable' => [422, 422],
            'server error' => [500, 502],
            'service unavailable' => [503, 503],
        ];
    }

    #[DataProvider('upstreamStatusMapping')]
    public function test_an_upstream_status_maps_to_the_documented_client_status(int $upstream, int $client): void
    {
        $this->forcedResponse = ['status' => $upstream, 'body' => ['detail' => 'upstream failure']];

        try {
            $this->engine()->model(7);
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame($upstream, $exception->upstreamStatus());
            $this->assertSame($client, $exception->statusForClient());
        }
    }

    // ------------------------------------------------------------------
    // response shapes
    // ------------------------------------------------------------------

    public function test_the_model_list_is_the_engine_registry_row_shape(): void
    {
        $models = $this->engine()->models();

        $this->assertSame($this->modelList(), $models);
        $this->assertSame(
            ['id', 'name', 'model_type', 'status', 'production_version_id'],
            array_keys($models[0])
        );
    }

    public function test_the_model_detail_carries_the_version_rows_the_ml_page_renders(): void
    {
        $model = $this->engine()->model(7);

        $this->assertSame(['id', 'name', 'model_type', 'status', 'versions'], array_keys($model));
        $this->assertSame(
            ['id', 'version', 'status', 'metrics', 'artifact_path'],
            array_keys($model['versions'][0])
        );
    }

    public function test_the_training_response_is_unwrapped_into_the_train_response_shape(): void
    {
        $result = $this->engine()->train('forecast', 'forecast_penjualan_harian', ['horizon' => 30]);

        $this->assertSame(
            ['model_id', 'version_id', 'version', 'metrics', 'status'],
            array_keys($result)
        );
    }

    public function test_the_chat_response_is_unwrapped_into_the_agent_turn_shape(): void
    {
        $result = $this->engine()->chat('Cabang mana yang paling turun?');

        $this->assertSame(['answer', 'conversation_id', 'evidence', 'steps'], array_keys($result));
    }

    public function test_the_rag_response_exposes_both_citation_keys_the_engine_sends(): void
    {
        $result = $this->engine()->ragQuery('apa POLICY retensi?', 3);

        $this->assertSame(['answer', 'citations', 'chunks', 'n_results'], array_keys($result));
        $this->assertSame($result['citations'], $result['chunks']);
    }

    // ------------------------------------------------------------------
    // request bodies
    // ------------------------------------------------------------------

    public function test_the_train_request_body_matches_the_engine_train_schema(): void
    {
        $this->engine()->train('forecast', 'forecast_penjualan_harian', ['horizon' => 30], [
            ['date' => '2026-01-01', 'y' => 10],
        ]);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/training/train')) {
                return false;
            }

            $this->assertSame([
                'model_type' => 'forecast',
                'name' => 'forecast_penjualan_harian',
                'params' => ['horizon' => 30],
                'dataset' => [['date' => '2026-01-01', 'y' => 10]],
            ], $request->data());

            return true;
        });
    }

    public function test_the_promote_request_body_matches_the_engine_promote_schema(): void
    {
        $this->engine()->promoteModel(7, 3, 'STAGED');

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/models/7/promote')) {
                return false;
            }

            $this->assertSame(['version_id' => 3, 'to_status' => 'STAGED'], $request->data());

            return true;
        });
    }

    public function test_the_chat_request_body_matches_the_engine_chat_schema(): void
    {
        $this->engine()->chat('Cabang mana yang paling turun?', 88, ['branch' => 'BR-03']);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/ai/chat')) {
                return false;
            }

            $this->assertSame([
                'message' => 'Cabang mana yang paling turun?',
                'conversation_id' => 88,
                'context' => ['branch' => 'BR-03'],
            ], $request->data());

            return true;
        });
    }

    public function test_the_report_request_body_matches_the_engine_report_schema(): void
    {
        $this->engine()->report('monthly', 'BR-03', 'json');

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/ai/report')) {
                return false;
            }

            $this->assertSame([
                'period' => 'monthly',
                'branch' => 'BR-03',
                'format' => 'json',
            ], $request->data());

            return true;
        });
    }

    public function test_the_rag_query_request_body_matches_the_engine_rag_schema(): void
    {
        $this->engine()->ragQuery('apa POLICY retensi?', 3);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/rag/query')) {
                return false;
            }

            $this->assertSame(['query' => 'apa POLICY retensi?', 'top_k' => 3], $request->data());

            return true;
        });
    }

    public function test_every_request_carries_the_service_key_and_the_client_header(): void
    {
        $client = $this->engine();

        $client->health();
        $client->models();
        $client->model(7);
        $client->promoteModel(7, 3, 'PRODUCTION');
        $client->train('forecast', 'forecast_penjualan_harian', ['horizon' => 30]);
        $client->chat('Cabang mana yang paling turun?');
        $client->report('weekly');
        $client->ragQuery('apa POLICY retensi?');

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Service-Key', 'test-service-key')
            && $request->hasHeader('X-Client', 'laravel-orchestrator'));
    }

    // ------------------------------------------------------------------
    // API surfaces built on the same contract
    // ------------------------------------------------------------------

    public function test_the_agent_endpoint_returns_the_engine_answer_under_both_keys(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $response = $this->postJson(route('api.agent.chat'), ['message' => 'Cabang mana yang paling turun?'])
            ->assertOk();

        $this->assertSame($this->chatResult()['answer'], $response->json('data.answer'));
        $this->assertSame($response->json('data.answer'), $response->json('data.reply'));
    }

    public function test_the_agent_endpoint_passes_through_the_evidence_and_step_count(): void
    {
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.agent.chat'), ['message' => 'Cabang mana yang paling turun?'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 88)
            ->assertJsonPath('data.steps', 3)
            ->assertJsonPath('data.evidence.0.source', 'query_sales');
    }

    public function test_the_rag_endpoint_falls_back_to_chunks_when_the_engine_omits_citations(): void
    {
        $this->forcedResponse = ['status' => 200, 'body' => ['success' => true, 'data' => [
            'answer' => 'Retensi 90 hari dijaga lewat voucher.',
            'chunks' => [
                ['content' => 'Voucher dikirim pada hari ke-60.', 'score' => 0.83, 'document_id' => 4, 'chunk_index' => 0],
            ],
            'n_results' => 1,
        ]]];
        Sanctum::actingAs(User::factory()->analyst()->create());

        $this->postJson(route('api.rag.query'), ['query' => 'apa POLICY retensi?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Retensi 90 hari dijaga lewat voucher.')
            ->assertJsonPath('data.citations.0.document_id', 4);
    }
}
