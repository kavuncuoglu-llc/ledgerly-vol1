<?php

namespace App\Ai\Contracts;

final readonly class ChatResponse
{
    public function __construct(
        public string $id,
        public string $model,
        public ?string $content,
        public array $toolCalls,
        public FinishReason $finishReason,
        public Usage $usage,
        public array $raw,
        public ?int $modelCallId = null,
    ) {}

    public function withModelCallId(?int $modelCallId): self
    {
        return new self(
            id: $this->id,
            model: $this->model,
            content: $this->content,
            toolCalls: $this->toolCalls,
            finishReason: $this->finishReason,
            usage: $this->usage,
            raw: $this->raw,
            modelCallId: $modelCallId,
        );
    }

    public function withContent(string $content): self
    {
        return new self(
            id: $this->id,
            model: $this->model,
            content: $content,
            toolCalls: [],
            finishReason: $this->finishReason,
            usage: $this->usage,
            raw: $this->raw,
            modelCallId: $this->modelCallId,
        );
    }
}
