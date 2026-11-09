<?php

namespace App\Ai\Tools;

use App\Ai\Http\ChatResponse;

final readonly class ToolRunResult
{
    public function __construct(
        public ChatResponse $response,
        public array $messages,
        public int $rounds,
        public bool $hitCap = false,
        public array $pending = [],
    ) {}

    public function answer(): string
    {
        return $this->response->content ?? '';
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }
}
