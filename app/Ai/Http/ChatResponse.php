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
    ) {}

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
