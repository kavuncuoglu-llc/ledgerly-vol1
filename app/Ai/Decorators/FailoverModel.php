<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Exceptions\ProviderUnavailable;
use Closure;
use Generator;
use Illuminate\Support\Facades\Log;

final class FailoverModel extends ChatModelDecorator
{
    public function __construct(
        ChatModel $inner,
        private readonly Closure $fallback,
        private readonly string $fallbackName,
    ) {
        parent::__construct($inner);
    }

    public function withInner(ChatModel $inner): static
    {
        return new self($inner, $this->fallback, $this->fallbackName);
    }

    public function fallbackName(): string
    {
        return $this->fallbackName;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        try {
            return $this->inner->chat($request);
        } catch (ProviderUnavailable $exception) {
            $this->announce($request, $exception);

            return ($this->fallback)()->chat($request->withoutModel());
        }
    }

    public function stream(ChatRequest $request): Generator
    {
        $started = false;

        try {
            $stream = $this->inner->stream($request);

            foreach ($stream as $event) {
                $started = true;

                yield $event;
            }

            return $stream->getReturn();
        } catch (ProviderUnavailable $exception) {
            if ($started) {
                throw $exception;
            }

            $this->announce($request, $exception);

            return yield from ($this->fallback)()->stream($request->withoutModel());
        }
    }

    private function announce(ChatRequest $request, ProviderUnavailable $exception): void
    {
        Log::notice('Provider unavailable; failing over', [
            'feature' => $request->feature,
            'provider' => $this->inner->name(),
            'fallback' => $this->fallbackName,
            'reason' => $exception->getMessage(),
        ]);
    }
}
