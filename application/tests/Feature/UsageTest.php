<?php

namespace Tests\Feature;

use App\Services\OpenCodeGoAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Usage normalization: canonical fields, chat-shaped aliases, derived
 * totals, and optional reasoning tokens.
 */
class UsageTest extends TestCase
{
    public function test_canonical_usage_passes_through(): void
    {
        $usage = OpenCodeGoAdapter::normalizeUsage(['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]);

        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15], $usage);
    }

    public function test_chat_shaped_aliases_are_mapped(): void
    {
        $usage = OpenCodeGoAdapter::normalizeUsage(['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10]);

        $this->assertSame(['input_tokens' => 7, 'output_tokens' => 3, 'total_tokens' => 10], $usage);
    }

    public function test_total_is_derived_when_missing(): void
    {
        $usage = OpenCodeGoAdapter::normalizeUsage(['input_tokens' => 7, 'output_tokens' => 3]);

        $this->assertSame(10, $usage['total_tokens']);
    }

    public function test_reasoning_tokens_are_kept_when_present(): void
    {
        $usage = OpenCodeGoAdapter::normalizeUsage(['input_tokens' => 7, 'output_tokens' => 3, 'reasoning_tokens' => 2]);

        $this->assertSame(2, $usage['reasoning_tokens']);
    }

    public function test_non_array_usage_is_empty(): void
    {
        $this->assertSame([], OpenCodeGoAdapter::normalizeUsage(null));
        $this->assertSame([], OpenCodeGoAdapter::normalizeUsage('nope'));
    }

    public function test_complete_returns_latency_and_request_id(): void
    {
        Http::preventStrayRequests();
        Http::fake(['opencode.test/*' => Http::response([
            'id' => 'resp_x',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'hi']]]],
            'usage' => ['input_tokens' => 4, 'output_tokens' => 1, 'total_tokens' => 5],
        ], 200)]);

        $adapter = new OpenCodeGoAdapter('https://opencode.test/v1', 'sk-test', 10, 0);
        $result = $adapter->complete('muse-spark-1.3-contributor', 'Say hi.');

        $this->assertSame('hi', $result['text']);
        $this->assertSame(5, $result['usage']['total_tokens']);
        $this->assertSame('resp_x', $result['request_id']);
        $this->assertSame(200, $result['status']);
    }
}
