<?php

namespace App\Ai\Http;

use App\Ai\Exceptions\ModelRequestFailed;

final readonly class ChatResponse
{
    public function __construct(
        public string $id,
        public string $model,
        public ?string $content,
        public array $toolCalls,
        public string $finishReason,
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

    public static function fromArray(array $body): self
    {
        $message = $body['choices'][0]['message'] ?? [];

        $content = $message['content'] ?? null;

        $toolCalls = $message['tool_calls'] ?? [];

        if ($content === null && $toolCalls === []) {
            throw ModelRequestFailed::malformed();
        }

        return new self(
            id: (string) ($body['id'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            content: $content,
            toolCalls: $toolCalls,
            finishReason: (string) ($body['choices'][0]['finish_reason'] ?? 'unknown'),
            usage: Usage::fromArray($body['usage'] ?? []),
            raw: $body,
        );
    }
}
