<?php

namespace App\Ai\Compare;

final readonly class CaseOutcome
{
    public function __construct(
        public string $caseId,
        public string $feature,
        public string $category,
        public string $provider,
        public float $quality,
        public array $scores = [],
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public float $latencyMs = 0.0,
        public ?float $ttftMs = null,
        public ?Cost $cost = null,
        public string $error = '',
        public string $answer = '',
    ) {}

    public function failed(): bool
    {
        return $this->error !== '';
    }

    public function toArray(): array
    {
        return array_filter([
            'case' => $this->caseId,
            'feature' => $this->feature,
            'category' => $this->category,
            'provider' => $this->provider,
            'quality' => round($this->quality, 4),
            'scores' => array_map(fn (Score $score): array => $score->toArray(), $this->scores),
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'latency_ms' => round($this->latencyMs, 1),
            'ttft_ms' => $this->ttftMs === null
                ? null
                : round($this->ttftMs, 1),
            'cost_usd' => $this->cost?->isKnown()
                ? round($this->cost->usd, 8)
                : null,
            'cost_basis' => $this->cost?->basis,
            'error' => $this->error === ''
                ? null
                : $this->error,
            'answer' => $this->answer === ''
                ? null
                : $this->answer,
        ], fn (mixed $value): bool => $value !== null);
    }
}
