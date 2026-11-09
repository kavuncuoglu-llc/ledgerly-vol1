<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\FinishReason;
use Throwable;

final readonly class ScriptedReply
{
    public function __construct(
        public ?string $content = null,
        public array $toolCalls = [],
        public array $deltas = [],
        public ?Throwable $failure = null,
        public ?FinishReason $finishReason = null,
    ) {}

    public function text(): ?string
    {
        if ($this->deltas !== []) {
            return implode('', $this->deltas);
        }

        return $this->content;
    }
}
