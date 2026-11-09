<?php

namespace Tests\Support;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use Generator;
use RuntimeException;

final class PlainTextModel implements ChatModel
{
    public array $received = [];

    public function __construct(private readonly string $content) {}

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->received[] = $request;

        return new ChatResponse('plain', 'plain-model', $this->content, [], FinishReason::Stop, new Usage(10, 5), []);
    }

    public function stream(ChatRequest $request): Generator
    {
        throw new RuntimeException('not streamed');
    }

    public function supports(Capability $capability): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'plain';
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return [];
    }
}
