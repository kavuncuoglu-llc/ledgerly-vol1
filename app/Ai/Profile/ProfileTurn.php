<?php

namespace App\Ai\Profile;

final readonly class ProfileTurn
{
    public function __construct(
        public int $index,
        public bool $cold,
        public float $startedAt,
        public float $firstTokenAt,
        public float $endedAt,
        public int $promptTokens,
        public int $completionTokens,
        public array $deltaTimes,
        public string $error = '',
    ) {}

    public function succeeded(): bool
    {
        return $this->error === '' && $this->firstTokenAt > 0;
    }

    public function timeToFirstToken(): float
    {
        return max(0.0, $this->firstTokenAt - $this->startedAt);
    }

    public function prefillTokensPerSecond(): float
    {
        if ($this->cold) {
            return 0.0;
        }

        if ($this->timeToFirstToken() <= 0.0) {
            return 0.0;
        }

        return $this->promptTokens / $this->timeToFirstToken();
    }

    public function decodeTokensPerSecond(): float
    {
        $seconds = $this->endedAt - $this->firstTokenAt;

        $tokens = $this->generatedTokens() - 1;

        if ($seconds <= 0.0) {
            return 0.0;
        }

        if ($tokens < 1) {
            return 0.0;
        }

        return $tokens / $seconds;
    }

    public function generatedTokensBy(float $at): int
    {
        $arrived = count(array_filter($this->deltaTimes, fn (float $time): bool => $time <= $at));

        if ($this->deltaTimes === []) {
            return 0;
        }

        return (int) round($this->generatedTokens() * $arrived / count($this->deltaTimes));
    }

    private function generatedTokens(): int
    {
        if ($this->completionTokens > 0) {
            return $this->completionTokens;
        }

        return count($this->deltaTimes);
    }
}
