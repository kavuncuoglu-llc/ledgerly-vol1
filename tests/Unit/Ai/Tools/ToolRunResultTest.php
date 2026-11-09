<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Http\ChatResponse;
use App\Ai\Http\Usage;
use App\Ai\Tools\ToolResult;
use App\Ai\Tools\ToolRunResult;
use PHPUnit\Framework\TestCase;

class ToolRunResultTest extends TestCase
{
    public function test_new_messages_are_the_turns_after_the_input_plus_the_final_answer(): void
    {
        $call = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{}']];
        $run = new ToolRunResult(
            response: $this->response('INV-4471 is overdue.'),
            messages: [
                ['role' => 'system', 'content' => 's'],
                ['role' => 'user', 'content' => 'q'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true}'],
            ],
            rounds: 2,
            inputCount: 2,
            modelCallIds: [11, 12],
        );

        $new = $run->newMessages();

        $this->assertSame(['assistant', 'tool', 'assistant'], array_column($new, 'role'));
        $this->assertSame([$call], $new[0]['tool_calls']);
        $this->assertSame(11, $new[0]['model_call_id']);
        $this->assertSame('INV-4471 is overdue.', $new[2]['content']);
        $this->assertSame(12, $new[2]['model_call_id']);
        $this->assertArrayNotHasKey('model_call_id', $new[1]);
    }

    public function test_a_plain_answer_is_the_only_new_message(): void
    {
        $run = new ToolRunResult($this->response('Hi'), [['role' => 'user', 'content' => 'q']], rounds: 1, inputCount: 1, modelCallIds: [5]);

        $this->assertSame([['role' => 'assistant', 'content' => 'Hi', 'model_call_id' => 5]], $run->newMessages());
    }

    public function test_a_run_that_hit_the_cap_has_no_final_answer_message(): void
    {
        $run = new ToolRunResult($this->response(null, [['id' => 'c']]), [['role' => 'user', 'content' => 'q']], rounds: 5, hitCap: true, inputCount: 1);

        $this->assertSame([], $run->newMessages());
    }

    public function test_a_pending_run_has_no_final_answer_message(): void
    {
        $run = new ToolRunResult(
            $this->response(null, [['id' => 'c']]),
            [['role' => 'user', 'content' => 'q']],
            rounds: 1,
            pending: [ToolResult::pending('id', 'refund', 'url')],
            inputCount: 1,
        );

        $this->assertSame([], $run->newMessages());
    }

    public function test_for_conversation_keeps_everything_and_adds_the_id(): void
    {
        $run = new ToolRunResult($this->response('Hi'), [], rounds: 1, inputCount: 0, modelCallIds: [1]);

        $bound = $run->forConversation(77);

        $this->assertSame(77, $bound->conversationId);
        $this->assertSame('Hi', $bound->answer());
        $this->assertSame([1], $bound->modelCallIds);
    }

    private function response(?string $content, array $toolCalls = []): ChatResponse
    {
        return new ChatResponse(
            id: 'x',
            model: 'm',
            content: $content,
            toolCalls: $toolCalls,
            finishReason: 'stop',
            usage: new Usage(1, 1),
            raw: [],
        );
    }
}
