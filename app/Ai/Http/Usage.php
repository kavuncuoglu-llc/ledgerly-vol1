<?php

namespace App\Ai\Http;

final readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public array $extras = [],
    ) {}

    public static function fromArray(array $usage): self
    {
        return new self(
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            extras: array_diff_key($usage, [
                'prompt_tokens' => true,
                'completion_tokens' => true,
            ]),
        );
    }

    public function toArray(): array
    {
        return [
            ...$this->extras,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
