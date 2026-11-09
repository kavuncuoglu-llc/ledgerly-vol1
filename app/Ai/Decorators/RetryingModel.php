<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Http\RetryPolicy;
use Generator;
use Throwable;

final class RetryingModel extends ChatModelDecorator
{
    public function __construct(
        ChatModel $inner,
        private readonly RetryPolicy $policy = new RetryPolicy,
    ) {
        parent::__construct($inner);
    }

    public function withInner(ChatModel $inner): static
    {
        return new self($inner, $this->policy);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->policy->run(fn (): ChatResponse => $this->inner->chat($request));
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->policy->run(fn (): array => $this->inner->raw($method, $path, $body));
    }

    public function stream(ChatRequest $request): Generator
    {
        $attempt = 1;

        while (true) {
            $started = false;

            try {
                $stream = $this->inner->stream($request);

                foreach ($stream as $event) {
                    $started = true;

                    yield $event;
                }

                return $stream->getReturn();
            } catch (Throwable $exception) {
                if ($started) {
                    throw $exception;
                }

                $this->policy->pauseBeforeRetry($attempt, $exception);

                $attempt++;
            }
        }
    }
}
