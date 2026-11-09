<?php

namespace App\Ai\Health;

use App\Ai\Resilience\BreakerState;

final readonly class HealthReport
{
    public function __construct(
        public string $provider,
        public bool $reachable,
        public string $reachableDetail,
        public bool $loaded,
        public string $loadedDetail,
        public bool $warm,
        public string $warmDetail,
        public BreakerState $breaker,
        public array $lastWarmUp,
    ) {}

    public function isHealthy(): bool
    {
        if (! $this->reachable) {
            return false;
        }

        if (! $this->loaded) {
            return false;
        }

        return ! $this->breaker->isOpen();
    }
}
