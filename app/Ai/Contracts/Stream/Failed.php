<?php

namespace App\Ai\Contracts\Stream;

use App\Ai\Contracts\ChatResponse;

final readonly class Failed implements StreamEvent
{
    public function __construct(
        public string $reason,
        public ChatResponse $partial,
    ) {}
}
