<?php

namespace Tests\Support;

use App\Ai\Profile\BackgroundSampler;
use App\Ai\Profile\MemorySample;

final class ScriptedSampler implements BackgroundSampler
{
    public array $started = [];

    public int $stopped = 0;

    public function start(string $provider, float $interval): void
    {
        $this->started[] = [$provider, $interval];
    }

    public function stop(): array
    {
        $this->stopped++;

        $now = microtime(true);

        return [
            new MemorySample($now - 0.01, 3 * 1_073_741_824, 0, 0, false),
            new MemorySample($now, 11 * 1_073_741_824, 8 * 1_073_741_824, 0, true),
        ];
    }
}
