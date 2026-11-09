<?php

namespace App\Ai\Tools\Mcp;

final readonly class McpCallResult
{
    public function __construct(
        public string $text,
        public bool $isError = false,
    ) {}
}
