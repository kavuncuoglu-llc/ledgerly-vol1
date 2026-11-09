<?php

namespace App\Ai\Load;

final readonly class ReplayLoadResult
{
    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    public const PAUSED = 'paused';

    public const TIMED_OUT = 'timed out';

    public function __construct(
        public array $users,
        public int $turnsPerUser,
        public float $wallSeconds,
        public array $ttftMilliseconds,
        public int $completionTokens,
    ) {}

    public function completedTurns(): int
    {
        return array_sum(array_map(fn (SimulatedUser $user): int => $user->completedTurns, $this->users));
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->users, fn (SimulatedUser $user): bool => $user->outcome === $outcome));
    }

    public function ttft(float $percentile): float
    {
        return Percentile::of($this->ttftMilliseconds, $percentile);
    }

    public function tokensPerSecond(): float
    {
        if ($this->wallSeconds <= 0.0) {
            return 0.0;
        }

        return $this->completionTokens / $this->wallSeconds;
    }

    public function line(): string
    {
        return sprintf(
            '%d users × %d turns · TTFT p50 %d ms · p99 %d ms · %d failures · aggregate %s tok/s',
            count($this->users),
            $this->turnsPerUser,
            round($this->ttft(50)),
            round($this->ttft(99)),
            $this->count(self::FAILED) + $this->count(self::TIMED_OUT),
            number_format($this->tokensPerSecond()),
        );
    }

    public function firstError(): string
    {
        foreach ($this->users as $user) {
            if ($user->error !== '') {
                return $user->error;
            }
        }

        return '';
    }

    public function detail(): string
    {
        return sprintf(
            '%d of %d turns completed in %.1f s · %d users finished, %d failed, %d waiting for an approval, %d timed out · TTFT from %d calls',
            $this->completedTurns(),
            count($this->users) * $this->turnsPerUser,
            $this->wallSeconds,
            $this->count(self::FINISHED),
            $this->count(self::FAILED),
            $this->count(self::PAUSED),
            $this->count(self::TIMED_OUT),
            count($this->ttftMilliseconds),
        );
    }
}
