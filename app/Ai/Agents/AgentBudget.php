<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\Usage;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;

final class AgentBudget
{
    public const MAX_STATE_REPEATS = 2;

    private int $rounds = 0;

    private int $tokens = 0;

    private int $writes = 0;

    private int $repeats = 0;

    private float $startedAt;

    private string $lastStateHash = '';

    public function __construct(
        private readonly int $maxRounds,
        private readonly int $maxTokens,
        private readonly int $maxSeconds,
        private readonly int $maxWrites,
    ) {
        $this->startedAt = $this->clock();
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            maxRounds: (int) $config['max_rounds'],
            maxTokens: (int) $config['max_tokens'],
            maxSeconds: (int) $config['max_seconds'],
            maxWrites: (int) $config['max_writes'],
        );
    }

    public function restore(array $usage): self
    {
        $this->rounds = (int) ($usage['rounds'] ?? 0);

        $this->tokens = (int) ($usage['tokens'] ?? 0);

        $this->writes = (int) ($usage['writes'] ?? 0);

        $this->startedAt = $this->clock() - (float) ($usage['seconds'] ?? 0);

        return $this;
    }

    public function begin(AgentState $state): void
    {
        $this->lastStateHash = $state->hash();

        $this->repeats = 0;
    }

    public function allows(): bool
    {
        return $this->reason() === '';
    }

    public function recordRound(Usage $usage): void
    {
        $this->rounds++;

        $this->tokens += $usage->promptTokens + $usage->completionTokens;
    }

    public function recordCall(?ToolKind $kind, ToolResult $result): void
    {
        if ($kind === null) {
            return;
        }

        if (! $kind->isWrite()) {
            return;
        }

        if (! $result->ok) {
            return;
        }

        $this->writes++;
    }

    public function observeProgress(AgentState $state): void
    {
        $hash = $state->hash();

        $this->repeats = $hash === $this->lastStateHash
            ? $this->repeats + 1
            : 0;

        $this->lastStateHash = $hash;
    }

    public function reason(): string
    {
        if ($this->rounds >= $this->maxRounds) {
            return 'rounds';
        }

        if ($this->tokens >= $this->maxTokens) {
            return 'tokens';
        }

        if ($this->elapsedSeconds() >= $this->maxSeconds) {
            return 'time';
        }

        if ($this->writes >= $this->maxWrites) {
            return 'writes';
        }

        if ($this->repeats >= self::MAX_STATE_REPEATS) {
            return 'no_progress';
        }

        return '';
    }

    public function rounds(): int
    {
        return $this->rounds;
    }

    public function usage(): array
    {
        return [
            'rounds' => $this->rounds,
            'tokens' => $this->tokens,
            'writes' => $this->writes,
            'seconds' => round($this->elapsedSeconds(), 2),
            'max_rounds' => $this->maxRounds,
            'max_tokens' => $this->maxTokens,
            'max_seconds' => $this->maxSeconds,
            'max_writes' => $this->maxWrites,
        ];
    }

    private function elapsedSeconds(): float
    {
        return $this->clock() - $this->startedAt;
    }

    private function clock(): float
    {
        return (float) now()->format('U.u');
    }
}
