<?php

namespace Tests\Feature;

use App\Services\OpenCodeGoAdapter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retry discipline: transient statuses are retried (honoring Retry-After),
 * auth/validation errors never are.
 */
class RetryTest extends TestCase
{
    private string $base = 'https://opencode.test/v1';

    public function test_rate_limit_with_retry_after_is_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->base.'/*' => Http::sequence()
                ->push('busy', 429, ['Retry-After' => '1'])
                ->push(['data' => [['id' => 'm']]], 200),
        ]);

        $slept = [];
        $adapter = new OpenCodeGoAdapter($this->base, 'sk-test', 10, 3, function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $sent = $adapter->sendWithRetry('GET', $this->base.'/models');

        $this->assertSame(200, $sent['response']->status());
        $this->assertSame(2, $sent['attempts']);
        // Retry-After: 1 is honored instead of the exponential backoff.
        $this->assertSame([1000], $slept);

        Http::assertSentCount(2);
    }

    public function test_server_errors_back_off_exponentially(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->base.'/*' => Http::sequence()
                ->push('boom', 503)
                ->push('boom', 502)
                ->push(['data' => []], 200),
        ]);

        $slept = [];
        $adapter = new OpenCodeGoAdapter($this->base, 'sk-test', 10, 3, function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $sent = $adapter->sendWithRetry('GET', $this->base.'/models');

        $this->assertSame(200, $sent['response']->status());
        $this->assertSame(3, $sent['attempts']);
        $this->assertSame([500, 1000], $slept);
    }

    #[DataProvider('nonRetryableStatuses')]
    public function test_auth_and_validation_errors_are_never_retried(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->base.'/*' => Http::response('nope', $status)]);

        $adapter = new OpenCodeGoAdapter($this->base, 'sk-test', 10, 3);

        $sent = $adapter->sendWithRetry('GET', $this->base.'/models');

        $this->assertSame(1, $sent['attempts']);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int}> */
    public static function nonRetryableStatuses(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'forbidden' => [403], 'not found' => [404], 'unprocessable' => [422]];
    }

    public function test_transient_status_list(): void
    {
        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            $this->assertTrue(OpenCodeGoAdapter::isTransientStatus($status), "HTTP {$status} should be transient.");
        }

        foreach ([200, 400, 401, 403, 404, 422] as $status) {
            $this->assertFalse(OpenCodeGoAdapter::isTransientStatus($status), "HTTP {$status} must never be retried.");
        }
    }
}
