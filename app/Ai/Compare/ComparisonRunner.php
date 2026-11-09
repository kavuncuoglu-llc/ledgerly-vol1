<?php

namespace App\Ai\Compare;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use Closure;

final class ComparisonRunner
{
    private const ANSWER_LIMIT = 600;

    private const GIVE_UP_AFTER_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        private readonly AiManager $ai,
        private readonly CostModel $costs,
    ) {}

    public function run(array $cases, array $providers, bool $stream = false, ?Closure $progress = null): array
    {
        $outcomes = [];

        foreach ($providers as $provider) {
            $model = $this->ai->chat($provider);

            $consecutiveFailures = 0;

            foreach ($cases as $case) {
                $outcome = $consecutiveFailures >= self::GIVE_UP_AFTER_CONSECUTIVE_FAILURES
                    ? $this->failure($case, $provider, 'skipped after '.self::GIVE_UP_AFTER_CONSECUTIVE_FAILURES.' failures in a row')
                    : $this->outcomeFor($case, $provider, $model, $stream);

                $consecutiveFailures = $outcome->failed()
                    ? $consecutiveFailures + 1
                    : 0;

                $outcomes[] = $outcome;

                if ($progress !== null) {
                    $progress($provider, $case);
                }
            }
        }

        return $outcomes;
    }

    private function outcomeFor(CompareCase $case, string $provider, ChatModel $model, bool $stream): CaseOutcome
    {
        $started = hrtime(true);

        $timing = [
            'ttft' => null,
        ];

        try {
            $response = $stream
                ? $this->streamed($model, $case, $started, $timing)
                : $model->chat($case->requestFor($model));
        } catch (ModelRequestFailed|StreamTruncated $exception) {
            return $this->failure($case, $provider, $exception->getMessage());
        }

        $latencyMs = $this->elapsedMilliseconds($started);

        return $this->scored($case, $provider, $response, $latencyMs, $timing['ttft']);
    }

    private function streamed(ChatModel $model, CompareCase $case, int|float $started, array &$timing): ChatResponse
    {
        $events = $model->stream($case->requestFor($model));

        foreach ($events as $event) {
            if ($event instanceof Delta) {
                $timing['ttft'] ??= $this->elapsedMilliseconds($started);
            }
        }

        return $events->getReturn();
    }

    private function scored(CompareCase $case, string $provider, ChatResponse $response, float $latencyMs, ?float $ttftMs): CaseOutcome
    {
        $scores = array_map(fn ($scorer) => $scorer->score($response), $case->scorers);

        return new CaseOutcome(
            caseId: $case->id,
            feature: $case->feature,
            category: $case->category,
            provider: $provider,
            quality: $this->mean(array_map(fn (Score $score): float => $score->value, $scores)),
            scores: $scores,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            latencyMs: $latencyMs,
            ttftMs: $ttftMs,
            cost: $this->costs->of($provider, $response->usage, $latencyMs),
            answer: $case->sensitive
                ? ''
                : mb_substr((string) $response->content, 0, self::ANSWER_LIMIT),
        );
    }

    private function failure(CompareCase $case, string $provider, string $message): CaseOutcome
    {
        return new CaseOutcome(
            caseId: $case->id,
            feature: $case->feature,
            category: $case->category,
            provider: $provider,
            quality: 0.0,
            error: $message,
        );
    }

    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function elapsedMilliseconds(int|float $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
