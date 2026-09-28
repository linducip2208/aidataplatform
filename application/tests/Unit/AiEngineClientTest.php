<?php

namespace Tests\Unit;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiEngineClientTest extends TestCase
{
    protected function engine(): AiEngineClient
    {
        return AiEngineClient::fromConfig();
    }

    public function test_kpi_unwraps_the_success_envelope(): void
    {
        // A raw JSON body, not an array: `json_encode` drops the `.0` from a
        // whole float, so an array fake would decode back as int and hide the
        // type the engine actually sends.
        Http::fake(['*/api/v1/analytics/kpi' => Http::response(
            '{"success":true,"data":{"revenue":1250000.0,"orders":412,"aov":3033.0}}',
            200,
            ['Content-Type' => 'application/json'],
        )]);

        $this->assertSame(
            ['revenue' => 1250000.0, 'orders' => 412, 'aov' => 3033.0],
            $this->engine()->kpi(['granularity' => 'daily']),
        );
    }

    public function test_an_envelope_with_success_false_throws_with_the_engine_message(): void
    {
        Http::fake(['*/api/v1/analytics/kpi' => Http::response([
            'success' => false,
            'error' => ['message' => 'No sales rows available for that window.'],
        ], 200)]);

        $this->expectException(AiEngineException::class);
        $this->expectExceptionMessage('No sales rows available for that window.');

        $this->engine()->kpi();
    }

    public function test_a_failed_envelope_carries_status_422_and_the_operation(): void
    {
        Http::fake(['*/api/v1/analytics/kpi' => Http::response([
            'success' => false, 'error' => ['message' => 'Bad filter.'],
        ], 200)]);

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(422, $exception->upstreamStatus());
            $this->assertSame('analytics.kpi', $exception->operation());
        }
    }

    public function test_an_http_500_throws_with_upstream_status_500(): void
    {
        Http::fake(['*/api/v1/analytics/kpi' => Http::response(['detail' => 'boom'], 500)]);

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(500, $exception->upstreamStatus());
        }
    }

    public function test_an_http_404_throws_with_upstream_status_404(): void
    {
        Http::fake(['*/api/v1/analytics/kpi' => Http::response([], 404)]);

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(404, $exception->upstreamStatus());
            $this->assertSame(404, $exception->statusForClient());
        }
    }

    public function test_status_for_client_maps_auth_and_server_errors_to_502(): void
    {
        $this->assertSame(502, (new AiEngineException('nope', 401))->statusForClient());
        $this->assertSame(502, (new AiEngineException('nope', 403))->statusForClient());
        $this->assertSame(502, (new AiEngineException('nope', 500))->statusForClient());

        // A 503 is the engine being unavailable, not the platform being broken,
        // so it is passed through: the caller can retry, a 502 invites a bug hunt.
        $this->assertSame(503, (new AiEngineException('nope', 503))->statusForClient());
    }

    public function test_status_for_client_maps_a_422_upstream_error_to_422(): void
    {
        $this->assertSame(422, (new AiEngineException('nope', 422))->statusForClient());
    }

    public function test_status_for_client_maps_an_unmapped_status_to_502(): void
    {
        $this->assertSame(502, (new AiEngineException('nope', 200))->statusForClient());
    }

    public function test_an_empty_service_key_makes_the_client_unconfigured_and_throws_503(): void
    {
        config(['ai_engine.service_key' => '']);
        Http::fake();

        $client = AiEngineClient::fromConfig();

        $this->assertFalse($client->isConfigured());

        try {
            $client->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(503, $exception->upstreamStatus());
        }

        Http::assertNothingSent();
    }

    public function test_the_service_key_and_client_headers_are_sent_on_every_request(): void
    {
        Http::fake(['*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200)]);

        $client = $this->engine();
        $client->health();
        $client->kpi();
        $client->importJob(7);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Service-Key')
            && $request->hasHeader('X-Service-Key', 'test-service-key')
            && $request->hasHeader('X-Client', 'laravel-orchestrator'));
    }

    public function test_import_job_hits_the_documented_jobs_path(): void
    {
        Http::fake(['*/api/v1/imports/jobs/7' => Http::response(['success' => true, 'data' => [
            'id' => 7, 'status' => 'succeeded', 'progress' => 100.0,
        ]], 200)]);

        $this->assertSame('succeeded', $this->engine()->importJob(7)['status']);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/imports/jobs/7')
            && $request->method() === 'GET');
    }

    public function test_an_unreachable_engine_surfaces_as_a_503_ai_engine_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        try {
            $this->engine()->kpi();
            $this->fail('Expected an AiEngineException.');
        } catch (AiEngineException $exception) {
            $this->assertSame(503, $exception->upstreamStatus());
        }
    }

    public function test_commit_import_posts_run_async_to_the_commit_endpoint(): void
    {
        Http::fake(['*/api/v1/imports/commit' => Http::response([
            'success' => true, 'data' => ['import_job_id' => 42, 'status' => 'queued'],
        ], 200)]);

        $this->engine()->commitImport(42, ['qty' => 'quantity'], 'sales', true);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/imports/commit')
            && $request['import_job_id'] === 42
            && $request['run_async'] === true
            && $request['mappings'] === ['qty' => 'quantity']);
    }
}
