<?php

namespace App\Ai\Streaming;

use Closure;

final readonly class StreamTaps
{
    public function __construct(
        public Closure $onDelta,
        public Closure $onToolStart,
    ) {}
}
