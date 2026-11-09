<?php

namespace App\Ai\Contracts;

use Generator;

interface ChatModel
{
    public function chat(ChatRequest $request): ChatResponse;

    public function stream(ChatRequest $request): Generator;

    public function supports(Capability $capability): bool;

    public function name(): string;

    public function raw(string $method, string $path, array $body = []): array;
}
