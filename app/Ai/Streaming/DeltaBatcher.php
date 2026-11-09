<?php

namespace App\Ai\Streaming;

use Closure;

final class DeltaBatcher
{
    private string $buffer = '';

    private int $deltas = 0;

    private float $lastFlushAt;

    public function __construct(
        private readonly Closure $flush,
        private readonly int $maxMilliseconds = 50,
        private readonly int $maxDeltas = 20,
        private readonly ?Closure $clock = null,
    ) {
        $this->lastFlushAt = $this->nowMilliseconds();
    }

    public function push(string $delta): void
    {
        $this->buffer .= $delta;

        $this->deltas++;

        if ($this->deltas >= $this->maxDeltas) {
            $this->flushNow();

            return;
        }

        if ($this->nowMilliseconds() - $this->lastFlushAt >= $this->maxMilliseconds) {
            $this->flushNow();
        }
    }

    public function flushNow(): void
    {
        if ($this->buffer === '') {
            return;
        }

        $text = $this->buffer;

        $this->buffer = '';

        $this->deltas = 0;

        $this->lastFlushAt = $this->nowMilliseconds();

        ($this->flush)($text);
    }

    private function nowMilliseconds(): float
    {
        if ($this->clock !== null) {
            return (float) ($this->clock)();
        }

        return hrtime(true) / 1_000_000;
    }
}
