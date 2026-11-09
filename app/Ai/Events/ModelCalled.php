<?php

namespace App\Ai\Events;

final class ModelCalled
{
    public function __construct(
        public readonly string $feature,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $usage,
        public readonly string $finishReason,
        public readonly int $latencyMs,
        public readonly ?int $conversationId = null,
    ) {}
}
