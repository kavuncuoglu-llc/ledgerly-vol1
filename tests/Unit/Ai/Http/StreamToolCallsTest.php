<?php

namespace Tests\Unit\Ai\Http;

use App\Ai\Events\ModelCalled;
use App\Ai\Http\AiHttpClient;
use App\Ai\Http\StreamAccumulator;
use App\Ai\Http\ToolCallStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class StreamToolCallsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.base_url', 'http://model.test/v1');
        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_stream_announces_the_tool_the_moment_its_name_arrives_then_returns_the_assembled_call(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->toolDelta(0, id: 'call_1', name: 'lookup_invoice', arguments: ''),
            $this->toolDelta(0, arguments: '{"month":'),
            $this->toolDelta(0, arguments: '"2026-03",'),
            $this->toolDelta(0, arguments: '"invoice_number":null}'),
            $this->finish('tool_calls'),
        ]))]);

        $stream = $this->client()->stream(messages: [['role' => 'user', 'content' => 'March?']]);
        $yielded = iterator_to_array($stream, false);

        $this->assertEquals([new ToolCallStarted(0, 'lookup_invoice')], $yielded);
        $response = $stream->getReturn();
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertNull($response->content);
        $this->assertSame([
            [
                'id' => 'call_1',
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_invoice',
                    'arguments' => '{"month":"2026-03","invoice_number":null}',
                ],
            ],
        ], $response->toolCalls);
    }

    public function test_interleaved_parallel_calls_accumulate_by_index_not_by_position(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            $this->toolDelta(0, id: 'call_1', name: 'lookup_invoice', arguments: ''),
            $this->toolDelta(1, id: 'call_2', name: 'lookup_invoice', arguments: ''),
            $this->toolDelta(1, arguments: '{"month":"2026-04",'),
            $this->toolDelta(0, arguments: '{"month":"2026-03",'),
            $this->toolDelta(0, arguments: '"invoice_number":null}'),
            $this->toolDelta(1, arguments: '"invoice_number":null}'),
            $this->finish('tool_calls'),
        ]))]);

        $stream = $this->client()->stream(messages: [['role' => 'user', 'content' => 'March and April?']]);
        $yielded = iterator_to_array($stream, false);

        $this->assertCount(2, $yielded);
        $calls = $stream->getReturn()->toolCalls;
        $this->assertSame(['call_1', 'call_2'], array_column($calls, 'id'));
        $this->assertSame('{"month":"2026-03","invoice_number":null}', $calls[0]['function']['arguments']);
        $this->assertSame('{"month":"2026-04","invoice_number":null}', $calls[1]['function']['arguments']);
    }

    public function test_a_reply_without_tool_calls_has_none_and_keeps_its_content(): void
    {
        Http::fake(['*' => Http::response($this->sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Hi'], 'finish_reason' => null]]],
            $this->finish('stop'),
        ]))]);

        $stream = $this->client()->stream(messages: [['role' => 'user', 'content' => 'Hi']]);
        iterator_to_array($stream, false);

        $this->assertSame([], $stream->getReturn()->toolCalls);
        $this->assertSame('Hi', $stream->getReturn()->content);
    }

    public function test_accumulator_announces_each_call_once(): void
    {
        $accumulator = new StreamAccumulator;

        $accumulator->absorb($this->toolDelta(0, id: 'call_1', name: 'open_ticket', arguments: ''));
        $first = $accumulator->drainStartedToolCalls();
        $accumulator->absorb($this->toolDelta(0, arguments: '{}'));
        $second = $accumulator->drainStartedToolCalls();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
    }

    private function toolDelta(int $index, ?string $id = null, ?string $name = null, string $arguments = ''): array
    {
        $call = ['index' => $index];

        if ($id !== null) {
            $call['id'] = $id;
        }

        $function = ['arguments' => $arguments];

        if ($name !== null) {
            $function['name'] = $name;
        }

        $call['function'] = $function;

        return ['id' => 'chatcmpl-1', 'model' => 'served:8b', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [$call]], 'finish_reason' => null]]];
    }

    private function finish(string $reason): array
    {
        return ['id' => 'chatcmpl-1', 'model' => 'served:8b', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $reason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    }

    private function sse(array $chunks): string
    {
        $lines = array_map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n", $chunks);

        return implode('', $lines)."data: [DONE]\n\n";
    }

    private function client(): AiHttpClient
    {
        return new AiHttpClient(config('ai'));
    }
}
