<?php

namespace App\Ai\Agents;

final readonly class AgentResult
{
    public const STATUS_DONE = 'done';

    public const STATUS_EXHAUSTED = 'exhausted';

    private function __construct(
        public string $status,
        public AgentState $state,
        public string $content,
        public Trace $trace,
        public string $reason,
        public int $runId,
        public array $usage,
    ) {}

    public static function done(AgentState $state, string $content, Trace $trace, int $runId, array $usage): self
    {
        return new self(
            status: self::STATUS_DONE,
            state: $state,
            content: $content,
            trace: $trace,
            reason: '',
            runId: $runId,
            usage: $usage,
        );
    }

    public static function exhausted(AgentState $state, Trace $trace, string $reason, int $runId, array $usage): self
    {
        return new self(
            status: self::STATUS_EXHAUSTED,
            state: $state,
            content: $state->conclusion,
            trace: $trace,
            reason: $reason,
            runId: $runId,
            usage: $usage,
        );
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_EXHAUSTED;
    }
}
