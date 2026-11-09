<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\FinishReason;
use Throwable;

final class FakeScript
{
    private string $feature = FakeLedger::ANY_FEATURE;

    private int $toolCallCount = 0;

    public function __construct(private readonly FakeLedger $ledger) {}

    public function for(string $feature): self
    {
        $this->feature = $feature;

        return $this;
    }

    public function respondWith(string $content, int $times = 1): self
    {
        return $this->add(new ScriptedReply(content: $content), $times);
    }

    public function respondWithJson(array $data, int $times = 1): self
    {
        return $this->add(new ScriptedReply(content: (string) json_encode($data)), $times);
    }

    public function respondWithToolCall(string $name, array $arguments, int $times = 1): self
    {
        $calls = [];

        for ($count = 0; $count < $times; $count++) {
            $calls[] = $this->toolCallReply($name, $arguments);
        }

        foreach ($calls as $reply) {
            $this->add($reply, 1);
        }

        return $this;
    }

    public function respondWithStream(array $deltas, int $times = 1): self
    {
        return $this->add(new ScriptedReply(deltas: array_values($deltas)), $times);
    }

    public function respondWithFinish(string $content, FinishReason $finishReason, int $times = 1): self
    {
        return $this->add(new ScriptedReply(content: $content, finishReason: $finishReason), $times);
    }

    public function failWith(Throwable $failure, int $times = 1): self
    {
        return $this->add(new ScriptedReply(failure: $failure), $times);
    }

    private function toolCallReply(string $name, array $arguments): ScriptedReply
    {
        $this->toolCallCount++;

        return new ScriptedReply(
            toolCalls: [
                [
                    'id' => "call_fake_{$this->toolCallCount}",
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'arguments' => (string) json_encode((object) $arguments),
                    ],
                ],
            ],
            finishReason: FinishReason::ToolCalls,
        );
    }

    private function add(ScriptedReply $reply, int $times): self
    {
        $this->ledger->script($this->feature, $reply, $times);

        return $this;
    }
}
