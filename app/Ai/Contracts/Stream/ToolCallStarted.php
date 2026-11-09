<?php

namespace App\Ai\Contracts\Stream;

final readonly class ToolCallStarted implements StreamEvent
{
    public function __construct(
        public int $index,
        public string $name,
    ) {}
}
