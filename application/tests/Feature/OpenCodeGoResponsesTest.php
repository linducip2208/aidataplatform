<?php

namespace Tests\Feature;

use App\Services\OpenCodeGoAdapter;
use Tests\TestCase;

/**
 * Responses payload parsing: canonical shape first, provider variants
 * degrade to partial data instead of throwing.
 */
class OpenCodeGoResponsesTest extends TestCase
{
    public function test_canonical_responses_shape(): void
    {
        $parsed = OpenCodeGoAdapter::parseResponsesPayload([
            'id' => 'resp_123',
            'model' => 'muse-spark-1.3-contributor',
            'output' => [
                ['type' => 'message', 'content' => [
                    ['type' => 'output_text', 'text' => 'Hello '],
                    ['type' => 'output_text', 'text' => 'world'],
                ]],
            ],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 3, 'total_tokens' => 15],
        ], 'fallback-model');

        $this->assertSame('Hello world', $parsed['text']);
        $this->assertSame(['input_tokens' => 12, 'output_tokens' => 3, 'total_tokens' => 15], $parsed['usage']);
        $this->assertSame('muse-spark-1.3-contributor', $parsed['model']);
        $this->assertSame('resp_123', $parsed['request_id']);
        $this->assertSame([], $parsed['tool_calls']);
    }

    public function test_function_call_items_are_normalized(): void
    {
        $parsed = OpenCodeGoAdapter::parseResponsesPayload([
            'output' => [
                ['type' => 'function_call', 'name' => 'list_datasets', 'arguments' => '{"limit":5}', 'call_id' => 'call_1'],
            ],
        ]);

        $this->assertSame('', $parsed['text']);
        $this->assertCount(1, $parsed['tool_calls']);
        $this->assertSame('list_datasets', $parsed['tool_calls'][0]['name']);
        $this->assertSame(['limit' => 5], $parsed['tool_calls'][0]['arguments']);
        $this->assertSame('call_1', $parsed['tool_calls'][0]['call_id']);
    }

    public function test_chat_shaped_payload_degrades_gracefully(): void
    {
        $parsed = OpenCodeGoAdapter::parseResponsesPayload([
            'choices' => [['message' => ['content' => 'hi there']]],
        ], 'fallback-model');

        $this->assertSame('hi there', $parsed['text']);
        $this->assertSame('fallback-model', $parsed['model']);
        $this->assertSame([], $parsed['usage']);
    }

    public function test_garbage_payload_never_throws(): void
    {
        foreach ([null, 'nope', 42, ['unexpected' => 'shape']] as $payload) {
            $parsed = OpenCodeGoAdapter::parseResponsesPayload($payload);

            $this->assertSame('', $parsed['text']);
            $this->assertSame([], $parsed['usage']);
            $this->assertSame([], $parsed['tool_calls']);
        }
    }

    public function test_sse_text_deltas_concatenate(): void
    {
        $buffer = "event: response.output_text.delta\ndata: {\"delta\":\"Hel\"}\n\n"
            ."event: response.output_text.delta\ndata: {\"delta\":\"lo\"}\n\n"
            ."event: response.completed\ndata: {\"text\":\"Hello\"}\n\n";

        [$events, $remainder] = OpenCodeGoAdapter::parseSseBuffer($buffer);

        $this->assertSame('', $remainder);
        $this->assertSame('AITextDelta', $events[0]['event']);
        $this->assertSame('Hel', $events[0]['delta']);
        $this->assertSame('AITextDelta', $events[1]['event']);
        $this->assertSame('AIResponseCompleted', $events[2]['event']);
    }

    public function test_sse_keeps_the_partial_tail(): void
    {
        [$events, $remainder] = OpenCodeGoAdapter::parseSseBuffer("data: {\"delta\":\"a\"}\n\npartial");

        $this->assertCount(1, $events);
        $this->assertSame('partial', $remainder);
    }

    public function test_sse_done_frame_is_swallowed(): void
    {
        [$events] = OpenCodeGoAdapter::parseSseBuffer("data: [DONE]\n\n");

        $this->assertSame([], $events);
    }
}
