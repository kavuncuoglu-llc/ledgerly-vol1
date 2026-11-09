<?php

namespace App\Ai\Decorators;

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\Failed;
use App\Ai\Events\ModelCalled;
use Generator;

final class UsageLoggingModel extends ChatModelDecorator
{
    public function withInner(ChatModel $inner): static
    {
        return new self($inner);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $started = hrtime(true);

        $response = $this->inner->chat($request);

        $event = $this->eventFor($request, $response, $this->elapsedMilliseconds($started), $request->repaired);

        event($event);

        return $response->withModelCallId($event->modelCallId);
    }

    public function stream(ChatRequest $request): Generator
    {
        $started = hrtime(true);

        $firstDeltaMs = null;

        $response = null;

        foreach ($this->inner->stream($request) as $item) {
            if ($item instanceof Delta) {
                $firstDeltaMs ??= $this->elapsedMilliseconds($started);
            }

            if ($item instanceof Failed) {
                event($this->eventFor($request, $item->partial, $this->elapsedMilliseconds($started), false, $firstDeltaMs));
            }

            if ($item instanceof Completed) {
                $event = $this->eventFor($request, $item->response, $this->elapsedMilliseconds($started), false, $firstDeltaMs);

                event($event);

                $response = $item->response->withModelCallId($event->modelCallId);

                $item = new Completed($response);
            }

            yield $item;
        }

        return $response;
    }

    private function eventFor(ChatRequest $request, ChatResponse $response, int $latencyMs, bool $repaired, ?int $ttftMs = null): ModelCalled
    {
        $cached = $this->cachedTokens($request, $response);

        return new ModelCalled(
            $request->feature,
            $this->inner->name(),
            $response->model,
            $response->usage->toArray(),
            $response->finishReason->value,
            $latencyMs,
            conversationId: $request->conversationId,
            promptVersion: $request->promptVersion,
            repaired: $repaired,
            cachedTokens: $cached['tokens'],
            ttftMs: $ttftMs,
            contextBreakdown: $request->details?->contextBreakdown,
            semanticCacheHit: $request->details?->semanticCacheHit,
            cachedTokensEstimated: $cached['estimated'],
            request: $this->recordedRequest($request),
        );
    }

    private function recordedRequest(ChatRequest $request): ?array
    {
        if (! config('ai.debug.record_requests')) {
            return null;
        }

        return $request->toRecorded();
    }

    private function cachedTokens(ChatRequest $request, ChatResponse $response): array
    {
        $reported = $response->usage->cachedTokens;

        if ($request->conversationId === null) {
            return [
                'tokens' => $reported,
                'estimated' => false,
            ];
        }

        $estimate = app(PrefixCacheEstimator::class)->estimate($request->feature, $request->conversationId, $request->messages, $response->usage->promptTokens);

        if ($reported !== null) {
            return [
                'tokens' => $reported,
                'estimated' => false,
            ];
        }

        return [
            'tokens' => $estimate,
            'estimated' => true,
        ];
    }

    private function elapsedMilliseconds(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
