<?php

namespace App\Ai\Sampling;

final class SampleResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $finishReason,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
    ) {}
}
