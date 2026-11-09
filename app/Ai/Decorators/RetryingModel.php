<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Http\RetryPolicy;
use App\Ai\Resilience\CircuitBreaker;
use Generator;
use Throwable;

final class RetryingModel extends ChatModelDecorator
{
    public function __construct(
        ChatModel $inner,
        private readonly RetryPolicy $policy = new RetryPolicy,
        private readonly ?CircuitBreaker $breaker = null,
    ) {
        parent::__construct($inner);
    }

    public function withInner(ChatModel $inner): static
    {
        return new self($inner, $this->policy, $this->breaker);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->refuseWhenOpen();

        try {
            $response = $this->policy->run(fn (): ChatResponse => $this->inner->chat($request));
        } catch (ProviderUnavailable $exception) {
            $this->breaker?->recordFailure($this->inner->name());

            throw $exception;
        }

        $this->breaker?->recordSuccess($this->inner->name());

        return $response;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return $this->policy->run(fn (): array => $this->inner->raw($method, $path, $body));
    }

    public function stream(ChatRequest $request): Generator
    {
        $this->refuseWhenOpen();

        try {
            $response = yield from $this->streamWithRetries($request);
        } catch (ProviderUnavailable $exception) {
            $this->breaker?->recordFailure($this->inner->name());

            throw $exception;
        }

        $this->breaker?->recordSuccess($this->inner->name());

        return $response;
    }

    private function refuseWhenOpen(): void
    {
        if ($this->breaker === null) {
            return;
        }

        $provider = $this->inner->name();

        if (! $this->breaker->isOpen($provider)) {
            return;
        }

        throw new ProviderUnavailable("{$provider} circuit open");
    }

    private function streamWithRetries(ChatRequest $request): Generator
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
