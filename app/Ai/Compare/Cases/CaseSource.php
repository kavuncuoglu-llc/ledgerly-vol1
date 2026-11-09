<?php

namespace App\Ai\Compare\Cases;

use App\Ai\Contracts\ChatModel;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableGoldenSet;
use App\Ai\Exceptions\UnreadableTicketSamples;
use App\Ai\Exceptions\UnusableComparison;

final class CaseSource
{
    public function __construct(
        private readonly SupportReplyCases $support,
        private readonly TicketClassifyCases $tickets,
        private readonly RecordedRequestCases $recorded,
    ) {}

    public function features(): array
    {
        return array_keys((array) config('ai.compare.defaults', []));
    }

    public function build(string $feature, ?string $golden, int $limit, bool $replay, ?ChatModel $judge): BuiltCases
    {
        if (! in_array($feature, $this->features(), true)) {
            throw UnusableComparison::unknownFeature($feature, $this->features());
        }

        if ($replay) {
            return $this->recorded->forFeature($feature, $limit === 0 ? (int) config('ai.compare.replay_limit') : $limit, $judge);
        }

        $path = $this->absolute($golden ?? (string) config('ai.compare.defaults', [])[$feature]);

        return $this->fromGolden($feature, $path, $limit, $judge);
    }

    private function fromGolden(string $feature, string $path, int $limit, ?ChatModel $judge): BuiltCases
    {
        try {
            return match ($feature) {
                SupportReplyCases::FEATURE => $this->support->fromFile($path, $limit, $judge),
                TicketClassifyCases::FEATURE => $this->tickets->fromFile($path, $limit),
            };
        } catch (UnusableComparison $exception) {
            throw $exception;
        } catch (UnreadableGoldenSet|UnreadableTicketSamples|EmbeddingFailed|ModelRequestFailed $exception) {
            throw new UnusableComparison($exception->getMessage(), previous: $exception);
        }
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
