<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\DecoratesChatModel;

abstract class ChatModelDecorator implements DecoratesChatModel
{
    public function __construct(protected readonly ChatModel $inner) {}

    public function inner(): ChatModel
    {
        return $this->inner;
    }

    public function supports(Capability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->inner->raw($method, $path, $body);
    }
}
