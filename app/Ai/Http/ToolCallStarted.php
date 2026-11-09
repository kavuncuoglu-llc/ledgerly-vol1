<?php

namespace App\Ai\Http;

final readonly class ToolCallStarted
{
    public function __construct(
        public int $index,
        public string $name,
    ) {}
}
