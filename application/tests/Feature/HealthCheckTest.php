<?php

namespace Tests\Feature;

use App\Services\OpenCodeGoAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider health: healthy / degraded / unhealthy with latency, HTTP
 * status, model availability, and check timestamp.
 */
class HealthCheckTest extends TestCase
{
    private string $base = 'https://opencode.test/v1';

    private function adapter(int $retries = 0): OpenCodeGoAdapter
    {
        return new OpenCodeGoAdapter($this->base, 'sk-test', 10, $retries);
    }

    public function test_healthy_when_models_are_advertised(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]], 200)]);

        $health = $this->adapter()->healthCheck();

        $this->assertSame('healthy', $health['status']);
        $this->assertSame(200, $health['http_status']);
        $this->assertSame(2, $health['models_available']);
        $this->assertIsInt($health['latency_ms']);
        $this->assertNotEmpty($health['checked_at']);
    }

    public function test_degraded_when_no_models_are_advertised(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response(['data' => []], 200)]);

        $health = $this->adapter()->healthCheck();

        $this->assertSame('degraded', $health['status']);
        $this->assertSame(0, $health['models_available']);
    }

    public function test_unhealthy_on_server_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response('boom', 500)]);

        $health = $this->adapter()->healthCheck();

        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame(500, $health['http_status']);
        $this->assertNull($health['models_available']);
    }

    public function test_unhealthy_on_rejected_key(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response('nope', 401)]);

        $health = $this->adapter()->healthCheck();

        $this->assertSame('unhealthy', $health['status']);
        $this->assertStringContainsString('key rejected', $health['note']);
    }

    public function test_unhealthy_when_unreachable(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => function (): void {
            throw new ConnectionException('dns failed');
        }]);

        $health = $this->adapter()->healthCheck();

        $this->assertSame('unhealthy', $health['status']);
        $this->assertNull($health['http_status']);
        $this->assertStringContainsString('Unreachable', $health['note']);
    }
}
