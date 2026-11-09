<?php

namespace App\Ai\Contracts;

use App\Ai\Http\CallDetails;

final readonly class ChatRequest
{
    public const NO_PROFILE = '';

    public function __construct(
        public array $messages,
        public string $feature,
        public ?string $model = null,
        public array $options = [],
        public array $tools = [],
        public ?array $responseFormat = null,
        public array $cacheBreakpointsAfter = [],
        public ?string $profile = null,
        public string|array|null $toolChoice = null,
        public ?string $promptVersion = null,
        public bool $repaired = false,
        public ?int $conversationId = null,
        public ?CallDetails $details = null,
    ) {}

    public static function fromOptions(
        array $messages,
        array $options = [],
        string $feature = 'unknown',
        ?string $promptVersion = null,
        bool $repaired = false,
        ?int $conversationId = null,
        ?CallDetails $details = null,
    ): self {
        return new self(
            messages: $messages,
            feature: $feature,
            model: $options['model'] ?? null,
            options: array_diff_key($options, array_flip(['profile', 'model', 'tools', 'tool_choice', 'response_format', 'cache_breakpoints_after'])),
            tools: $options['tools'] ?? [],
            responseFormat: $options['response_format'] ?? null,
            cacheBreakpointsAfter: $options['cache_breakpoints_after'] ?? [],
            profile: self::profileFrom($options),
            toolChoice: $options['tool_choice'] ?? null,
            promptVersion: $promptVersion,
            repaired: $repaired,
            conversationId: $conversationId,
            details: $details,
        );
    }

    public function withMessages(array $messages): self
    {
        return $this->copy(messages: $messages);
    }

    public function withOptions(array $options): self
    {
        return $this->copy(options: $options);
    }

    public function withProfileApplied(array $merged): self
    {
        return $this->copy(options: $merged, profile: self::NO_PROFILE);
    }

    public function withoutResponseFormat(): self
    {
        return $this->copy(responseFormat: null);
    }

    public function withModel(?string $model): self
    {
        return $this->copy(model: $model);
    }

    public function withCacheBreakpoints(array $indices): self
    {
        return $this->copy(cacheBreakpointsAfter: $indices);
    }

    private static function profileFrom(array $options): ?string
    {
        if (! array_key_exists('profile', $options)) {
            return null;
        }

        return $options['profile'] ?? self::NO_PROFILE;
    }

    private function copy(
        ?array $messages = null,
        ?array $options = null,
        ?string $profile = null,
        ?string $model = null,
        mixed $responseFormat = false,
        ?array $cacheBreakpointsAfter = null,
    ): self {
        return new self(
            messages: $messages ?? $this->messages,
            feature: $this->feature,
            model: $model ?? $this->model,
            options: $options ?? $this->options,
            tools: $this->tools,
            responseFormat: $responseFormat === false
                ? $this->responseFormat
                : $responseFormat,
            cacheBreakpointsAfter: $cacheBreakpointsAfter ?? $this->cacheBreakpointsAfter,
            profile: $profile ?? $this->profile,
            toolChoice: $this->toolChoice,
            promptVersion: $this->promptVersion,
            repaired: $this->repaired,
            conversationId: $this->conversationId,
            details: $this->details,
        );
    }
}
