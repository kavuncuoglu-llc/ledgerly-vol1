<?php

namespace App\Ai\Load;

final readonly class TrafficShape
{
    public function __construct(
        public string $feature,
        public int $inputTokens,
        public int $outputTokens,
        public int $prefixTokens = 0,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            feature: (string) ($row['feature'] ?? ''),
            inputTokens: (int) $row['input_tokens'],
            outputTokens: (int) $row['output_tokens'],
            prefixTokens: (int) ($row['prefix_tokens'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'prefix_tokens' => $this->prefixTokens,
        ];
    }
}
