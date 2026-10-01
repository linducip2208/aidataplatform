<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenCodeGoAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real HTTP probing (faked transport): success shape, auth rejection,
 * unreachable hosts, and key scrubbing everywhere.
 */
class TestConnectionTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'https://opencode.test/v1';

    private function adapter(): OpenCodeGoAdapter
    {
        return new OpenCodeGoAdapter($this->base, 'sk-test-secret', 10, 0);
    }

    public function test_success_reports_status_latency_and_model_count(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response(['data' => [['id' => 'muse-spark-1.3-contributor']]], 200)]);

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['models']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertStringContainsString('200', $result['note']);
        $this->assertStringNotContainsString('sk-test-secret', $result['note']);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer sk-test-secret')
                && str_ends_with($request->url(), '/models');
        });
    }

    public function test_rejected_key_is_reported_without_leaking(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response(['error' => 'bad key sk-test-secret'], 401)]);

        $result = $this->adapter()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('key rejected', $result['note']);
        $this->assertStringNotContainsString('sk-test-secret', $result['note']);
    }

    public function test_unreachable_host_reports_without_leaking(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => function (): void {
            throw new ConnectionException('cURL error 7: Failed to connect (sk-test-secret)');
        }]);

        $result = $this->adapter()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertStringContainsString('Unreachable', $result['note']);
        $this->assertStringNotContainsString('sk-test-secret', $result['note']);
    }

    public function test_probe_endpoint_rejects_guests_and_viewers(): void
    {
        $this->postJson(route('admin.providers.probe'), [])->assertUnauthorized();

        $this->actingAs(User::factory()->viewer()->create())
            ->postJson(route('admin.providers.probe'), [])
            ->assertForbidden();
    }
}
