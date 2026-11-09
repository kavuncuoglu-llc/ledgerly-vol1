<?php

namespace App\Ai\Contracts;

final readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public ?int $cachedTokens = null,
        public ?int $reasoningTokens = null,
        public bool $estimated = false,
    ) {}

    public static function empty(): self
    {
        return new self(promptTokens: 0, completionTokens: 0);
    }

    public function toArray(): array
    {
        return array_filter([
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cached_tokens' => $this->cachedTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'estimated' => $this->estimated ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
