<?php

namespace App\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ModelCalled
{
    use Dispatchable;

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
